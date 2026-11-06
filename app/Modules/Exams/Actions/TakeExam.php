<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Enums\ClosedBy;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamAttemptItem;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Random\Randomizer;

/**
 * The candidate's exam (analysis doc §06; D-056, D-073, D-075). The server keeps the time:
 *
 *  - start: from the session's start until the late window closes (start + late_minutes). The attempt ends with
 *    the session, so a latecomer has less time unless staff extend that attempt. The session's questions are
 *    given in this candidate's own order (the options keep theirs);
 *  - save: one answer at a time, kept with its history, until the deadline. A save after it is refused and closes
 *    the attempt;
 *  - submit: closes the attempt; submitting again changes nothing and returns the same receipt.
 */
class TakeExam
{
    public function __construct(private readonly ExamResults $results, private readonly Randomizer $random = new Randomizer) {}

    public function start(ExamBooking $booking, ?string $ip): ExamAttempt
    {
        $attempt = DB::transaction(function () use ($booking, $ip) {
            // The session first (shared): an extension for everyone, a change or a new draw waits for this start, and
            // this start sees their result (D-073). Then the booking, then the application: the order every exam
            // write follows, so two of them never wait for each other.
            $session = ExamSession::query()->whereKey($booking->session_id)->sharedLock()->firstOrFail();
            $booking = ExamBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $now = Date::now();

            if ($booking->status === BookingStatus::Attended) {
                return ExamAttempt::query()->where('booking_id', $booking->id)->firstOrFail();   // started already: the same attempt
            }

            if ($booking->status !== BookingStatus::Booked) {
                $this->fail('booking', 'exams.candidate.not_booked', ['status' => $booking->status->labelAr()]);
            }

            if ($now->lessThan($session->starts_at)) {
                $this->fail('booking', 'exams.candidate.not_yet', ['time' => $session->starts_at->toIso8601String()]);
            }

            if ($now->greaterThanOrEqualTo($session->lateUntil())) {
                $this->fail('booking', 'exams.candidate.too_late', ['time' => $session->lateUntil()->toIso8601String()]);
            }

            $application = Application::query()->whereKey($booking->application_id)->sharedLock()->firstOrFail();

            if ($application->current_status !== ApplicationStatus::AwaitingExam) {
                $this->fail('booking', 'exams.candidate.application_moved');
            }

            $attempt = new ExamAttempt;
            $attempt->forceFill([
                'booking_id' => $booking->id,
                'session_id' => $session->id,
                'started_at' => $now,
                'deadline_at' => $session->endsAt(),
                'start_ip' => $ip,
            ])->save();

            $questions = $this->random->shuffleArray($session->questions()->pluck('id')->all());
            ExamAttemptItem::query()->insert(array_map(fn (int $questionId, int $index) => [
                'attempt_id' => $attempt->id, 'session_id' => $session->id, 'position' => $index + 1, 'session_question_id' => $questionId,
            ], $questions, array_keys($questions)));

            $booking->forceFill(['status' => BookingStatus::Attended])->save();

            AuditTrail::record('exam_attempt.started', null, ['attempt_id' => $attempt->id, 'booking_id' => $booking->id, 'ip' => $ip, 'deadline' => $attempt->deadline_at->toIso8601String()], $application->candidate_id);

            return $attempt;
        });

        // Started already and past its deadline: closed now, outside the booking's lock.
        return $this->closeIfExpired($attempt);
    }

    /** @param ?int $option an index of the question's options (from 0), or null to clear the answer */
    public function save(ExamAttempt $attempt, int $position, ?int $option, ?string $ip): ExamAttemptItem
    {
        $result = DB::transaction(function () use ($attempt, $position, $option, $ip) {
            $attempt = ExamAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->hasExpired()) {
                $this->results->close($attempt, ClosedBy::Timeout, $attempt->deadline_at);

                return null;   // committed, then refused below
            }

            if (! $attempt->isOpen()) {
                $this->fail('attempt', 'exams.candidate.closed');
            }

            $item = ExamAttemptItem::query()->where('attempt_id', $attempt->id)->where('position', $position)->with('question.version')->first()
                ?? $this->fail('position', 'exams.candidate.no_such_question');

            if ($option !== null && $option >= count($item->question->version->options)) {
                $this->fail('option', 'exams.candidate.no_such_option');
            }

            $now = Date::now();
            $item->forceFill(['selected_option' => $option, 'answered_at' => $option === null ? null : $now])->save();
            $item->revisions()->forceCreate(['selected_option' => $option, 'ip' => $ip, 'saved_at' => $now]);

            return $item;
        });

        return $result ?? $this->fail('attempt', 'exams.candidate.time_over');
    }

    /** Idempotent: an attempt already closed is returned as it is. */
    public function submit(ExamAttempt $attempt): ExamAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $attempt = ExamAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->hasExpired()) {
                return $this->results->close($attempt, ClosedBy::Timeout, $attempt->deadline_at);
            }

            return $attempt->isOpen() ? $this->results->close($attempt, ClosedBy::Candidate, Date::now()) : $attempt;
        });
    }

    /** The server closes an attempt whose deadline passed, on the next look at it. */
    public function closeIfExpired(ExamAttempt $attempt): ExamAttempt
    {
        if (! $attempt->hasExpired()) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt) {
            $attempt = ExamAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            return $attempt->hasExpired() ? $this->results->close($attempt, ClosedBy::Timeout, $attempt->deadline_at) : $attempt;
        });
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
