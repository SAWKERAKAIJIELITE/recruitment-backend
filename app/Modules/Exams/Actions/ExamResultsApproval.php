<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\AttemptStatus;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Exams\Support\HallGuard;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionReasonType;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Support\LifecycleNotices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The exams office approves a session's results, once, after the session (D-104). Until then a decided result keeps
 * its application in exam_taken: Recruitment and the interviews office are told nothing has passed, and nothing that
 * passed ever goes back.
 *
 *  - Refused while the session is out in a hall, before it ends, while an attempt runs or a result is undecided (a
 *    hand mark missing), and once approved.
 *  - Every application of the session still in exam_taken moves as its latest result says: passed → awaiting_interview,
 *    failed → exam_failed, dated now (the approval is the decision; the exam's day stays on exam_taken).
 *  - `failures`: candidates who passed and are failed by decision (ترسيب بقرار: cheating, say), each with a reason
 *    type, a reason and an optional decision number. Only a pass can be failed: a failure is admitted only by an
 *    exception, after the approval, as before. The computed result stays as it is.
 *  - An application closed meanwhile keeps its status (its result is kept, D-075).
 */
class ExamResultsApproval
{
    public function __construct(
        private readonly ApplicationLifecycle $lifecycle,
        private readonly SweepExamSessions $sweep,
        private readonly LifecycleNotices $notices,
    ) {}

    /**
     * @param  list<array{booking_id: string, reason_type: string, reason: string, decision_number?: ?string}>  $failures
     * @return array{passed: int, failed: int, failed_by_decision: int}
     */
    public function approve(ExamSession $session, array $failures, StaffUser $actor): array
    {
        // Absences and timeouts first, as when staff open the session: each commits on its own.
        $this->sweep->sweep($session->id);

        return $this->notices->batch(fn() => DB::transaction(function () use ($session, $failures, $actor) {
            $session = ExamSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            HallGuard::assertNotOut($session);
            $this->assertApprovable($session);

            $pending = $this->pending($session);
            $failures = $this->checkedFailures($failures, $pending);
            $now = Date::now();
            $counts = ['passed' => 0, 'failed' => 0, 'failed_by_decision' => 0];

            foreach ($pending as $booking) {
                /** @var ExamBooking $booking */
                $application = Application::query()->whereKey($booking->application_id)->lockForUpdate()->firstOrFail();
                $failure = $failures[$booking->id] ?? null;
                $passed = $booking->getAttribute('latest_result')->passed;

                match (true) {
                    $failure !== null => $this->lifecycle->move($application, ApplicationStatus::ExamFailed, DecisionType::FailedByDecision, $actor, $failure['reason'], $booking, $now, $failure['decision_number'] ?? null, DecisionReasonType::from($failure['reason_type'])),
                    $passed => $this->lifecycle->move($application, ApplicationStatus::AwaitingInterview, DecisionType::Outcome, $actor, null, $booking, $now),
                    default => $this->lifecycle->move($application, ApplicationStatus::ExamFailed, DecisionType::Outcome, $actor, null, $booking, $now),
                };

                $counts[match (true) {
                    $failure !== null => 'failed_by_decision',
                    $passed => 'passed',
                    default => 'failed',
                }]++;
            }

            $session->forceFill(['results_approved_at' => $now, 'results_approved_by' => $actor->id])->save();

            AuditTrail::record('exam_session.results_approved', $actor, $counts + [
                'session_id' => $session->id,
                'failures' => array_map(fn(array $failure) => ['booking_id' => $failure['booking_id'], 'reason_type' => $failure['reason_type'], 'reason' => $failure['reason'], 'decision_number' => $failure['decision_number'] ?? null], array_values($failures)),
            ]);

            return $counts;
        }));
    }

    private function assertApprovable(ExamSession $session): void
    {
        if ($session->results_approved_at !== null) {
            $this->fail('session', 'exams.approval.already', ['at' => $session->results_approved_at->setTimezone(config('reports.timezone'))->format('Y-m-d H:i')]);
        }

        if (Date::now()->lessThan($session->endsAt())) {
            $this->fail('session', 'exams.approval.not_ended');
        }

        $running = $session->attempts()->where('status', AttemptStatus::InProgress)->count();

        if ($running > 0) {
            $this->fail('session', 'exams.approval.running', ['count' => $running]);
        }

        // A result not decided yet: the attempt has none (a hand mark missing). An application closed meanwhile has its
        // result kept without moving (D-075), so the booking's decided_at cannot tell.
        $undecided = $session->bookings()->where('status', BookingStatus::Attended)->whereDoesntHave('attempt.result')->count();

        if ($undecided > 0) {
            $this->fail('session', 'exams.approval.undecided', ['count' => $undecided]);
        }
    }

    /**
     * The session's attended bookings whose application still waits in exam_taken because of them, each with its latest
     * result (`latest_result`).
     *
     * @return Collection<int, ExamBooking>
     */
    private function pending(ExamSession $session): Collection
    {
        $bookings = ExamBooking::query()
            ->where('session_id', $session->id)
            ->where('status', BookingStatus::Attended)
            ->whereNotNull('decided_at')
            ->whereHas('application', fn($application) => $application->where('current_status', ApplicationStatus::ExamTaken))
            ->with('attempt')
            ->orderBy('id')
            ->get();

        $results = ExamResult::query()
            ->whereIn('attempt_id', $bookings->pluck('attempt.id'))
            ->orderBy('revision')
            ->get()
            ->keyBy('attempt_id');   // the latest revision wins

        return $bookings->each(fn(ExamBooking $booking) => $booking->setAttribute('latest_result', $results->get($booking->attempt->id)))->keyBy('id');
    }

    /**
     * Each failure names a booking of this session waiting for the approval whose result is a pass.
     *
     * @param  Collection<string, ExamBooking>  $pending
     * @return array<string, array> by booking id
     */
    private function checkedFailures(array $failures, Collection $pending): array
    {
        $errors = [];
        $byBooking = [];

        foreach (array_values($failures) as $index => $failure) {
            $booking = $pending->get($failure['booking_id']);

            if ($booking === null) {
                $errors["failures.{$index}.booking_id"] = [__('exams.approval.not_pending')];
            } elseif (! $booking->getAttribute('latest_result')->passed) {
                $errors["failures.{$index}.booking_id"] = [__('exams.approval.not_a_pass')];
            } else {
                $byBooking[$booking->id] = $failure;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $byBooking;
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
