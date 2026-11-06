<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\AttemptStatus;
use App\Modules\Exams\Enums\ExamPlanStatus;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exam sessions (D-056, D-073): created on a published plan with the plan's duration, a late window and a password;
 * its questions are drawn at once. Until it starts, its start, place, late window and questions can change.
 * The password and notes can change at any time. An extension adds time for everyone still sitting (individual
 * permission attempts.extend). A session nobody was booked into can be deleted.
 */
class ManageExamSessions
{
    public function __construct(private readonly DrawSessionQuestions $draw) {}

    /** @param array{plan_version_id: string, starts_at: string, location: string, late_minutes?: ?int, password: string, notes?: ?string} $data */
    public function create(array $data, StaffUser $actor): ExamSession
    {
        return DB::transaction(function () use ($data, $actor) {
            $plan = ExamPlanVersion::query()->whereKey($data['plan_version_id'])->sharedLock()->firstOrFail();

            if ($plan->status !== ExamPlanStatus::Published) {
                $this->fail('plan_version_id', 'exams.sessions.plan_not_published', ['status' => __("exams.statuses.{$plan->status->value}")]);
            }

            $session = new ExamSession;
            $session->fill(['starts_at' => $data['starts_at'], 'location' => $data['location'], 'notes' => $data['notes'] ?? null]);
            $session->forceFill([
                'plan_version_id' => $plan->id,
                'duration_minutes' => $plan->duration_minutes,
                'late_minutes' => $data['late_minutes'] ?? (int) config('exams.late_minutes'),
                'password' => $data['password'],
                'created_by' => $actor->id,
            ]);
            $this->assertTiming($session);
            $session->save();

            $drawn = $this->draw->draw($session);

            AuditTrail::record('exam_session.created', $actor, ['session_id' => $session->id, 'plan_version_id' => $plan->id, 'questions' => $drawn]);

            return $session;
        });
    }

    /** Only what is sent changes. The timing only until someone starts; the password at any time. */
    public function update(ExamSession $session, array $data, StaffUser $actor): ExamSession
    {
        return DB::transaction(function () use ($session, $data, $actor) {
            $session = $this->lock($session);
            $timing = array_intersect_key($data, array_flip(['starts_at', 'late_minutes', 'location']));

            if ($timing !== [] && $session->hasStarted()) {
                $this->fail('session', 'exams.sessions.started');
            }

            $session->fill(array_intersect_key($data, array_flip(['starts_at', 'location', 'late_minutes', 'notes'])));

            if (isset($data['password'])) {
                $session->forceFill(['password' => $data['password']]);
            }

            $this->assertTiming($session);
            $session->save();

            AuditTrail::record('exam_session.updated', $actor, ['session_id' => $session->id, 'fields' => array_keys(array_diff_key($data, ['password' => true])), 'password_changed' => isset($data['password'])]);

            return $session;
        });
    }

    /** Draws its questions again (a question corrected or added in the bank), until the session starts. */
    public function redraw(ExamSession $session, StaffUser $actor): ExamSession
    {
        return DB::transaction(function () use ($session, $actor) {
            $session = $this->lock($session);

            if ($session->hasStarted()) {
                $this->fail('session', 'exams.sessions.started');
            }

            $drawn = $this->draw->draw($session);
            AuditTrail::record('exam_session.redrawn', $actor, ['session_id' => $session->id, 'questions' => $drawn]);

            return $session;
        });
    }

    /** A session nobody was ever booked into (bookings are history, even cancelled ones). */
    public function delete(ExamSession $session, StaffUser $actor): void
    {
        DB::transaction(function () use ($session, $actor) {
            $session = $this->lock($session);

            if ($session->bookings()->exists() || $session->extensions()->exists()) {
                $this->fail('session', 'exams.sessions.in_use');
            }

            $session->questions()->delete();
            $session->delete();

            AuditTrail::record('exam_session.deleted', $actor, ['session_id' => $session->id, 'starts_at' => $session->starts_at->toIso8601String(), 'location' => $session->location]);
        });
    }

    /**
     * More time for everyone (individual permission attempts.extend, with a reason): the session ends later, and
     * every attempt still running gets the same minutes on its deadline (each move recorded on the attempt). Given
     * before the session ends; attempts already closed stay closed.
     */
    public function extend(ExamSession $session, int $minutes, string $reason, StaffUser $actor): ExamSession
    {
        return DB::transaction(function () use ($session, $minutes, $reason, $actor) {
            $session = $this->lock($session);
            $now = Date::now();

            if ($now->greaterThanOrEqualTo($session->endsAt())) {
                $this->fail('session', 'exams.sessions.ended');
            }

            $session->forceFill(['extra_minutes' => $session->extra_minutes + $minutes])->save();
            $extension = $session->extensions()->forceCreate(['minutes' => $minutes, 'reason' => trim($reason), 'extended_by' => $actor->id, 'created_at' => $now]);
            $moved = 0;

            $running = ExamAttempt::query()->where('session_id', $session->id)->where('status', AttemptStatus::InProgress)->where('deadline_at', '>', $now)->lockForUpdate()->get();

            foreach ($running as $attempt) {
                ExtendAttempt::move($attempt, $minutes, $reason, $actor, $extension->id);
                $moved++;
            }

            AuditTrail::record('exam_session.extended', $actor, ['session_id' => $session->id, 'minutes' => $minutes, 'reason' => trim($reason), 'attempts' => $moved]);

            return $session;
        });
    }

    private function assertTiming(ExamSession $session): void
    {
        if ($session->late_minutes >= $session->duration_minutes) {
            $this->fail('late_minutes', 'exams.sessions.late_too_long', ['duration' => $session->duration_minutes]);
        }
    }

    private function lock(ExamSession $session): ExamSession
    {
        return ExamSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
