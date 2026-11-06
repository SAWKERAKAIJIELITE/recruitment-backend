<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\AttemptStatus;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Enums\ClosedBy;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What the clock decides (analysis doc §06: "after the window closes without a valid attempt, an absence is
 * created and linked to the appointment"; D-073):
 *
 *  - a booking not started when its session's late window closed: absent; the application moves to exam_absent,
 *    dated when the window closed, with the booking as the cause (an application that left awaiting_exam in the
 *    meantime has its booking cancelled instead);
 *  - an attempt still open after its deadline: closed by the server at the deadline, and decided when nothing is
 *    missing.
 *
 * Runs every minute (`exams:sweep`), and for one session whenever staff look at it; candidates' own attempts are
 * closed whenever they touch them. Running it twice changes nothing.
 */
class SweepExamSessions
{
    public function __construct(private readonly ApplicationLifecycle $lifecycle, private readonly ExamResults $results) {}

    /** @return array{absent: int, closed: int} */
    public function sweep(?string $sessionId = null): array
    {
        $now = Date::now();
        $absent = 0;
        $closed = 0;

        $late = ExamBooking::query()
            ->where('status', BookingStatus::Booked)
            ->when($sessionId, fn($query, $id) => $query->where('session_id', $id))
            ->whereHas('session', fn($session) => $session->whereRaw("starts_at + late_minutes * interval '1 minute' <= ?", [$now]))
            ->pluck('id');

        foreach ($late as $id) {
            $absent += (int) $this->each('absent', $id, fn () => DB::transaction(fn () => $this->markAbsent($id)));
        }

        $expired = ExamAttempt::query()
            ->where('status', AttemptStatus::InProgress)
            ->where('deadline_at', '<=', $now)
            ->when($sessionId, fn($query, $id) => $query->where('session_id', $id))
            ->pluck('id');

        foreach ($expired as $id) {
            $closed += (int) $this->each('close', $id, fn () => DB::transaction(function () use ($id) {
                $attempt = ExamAttempt::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if (! $attempt->hasExpired()) {
                    return false;
                }

                $this->results->close($attempt, ClosedBy::Timeout, $attempt->deadline_at);

                return true;
            }));
        }

        return ['absent' => $absent, 'closed' => $closed];
    }

    /** One item at a time: an item that fails (a lock wait, a decision refused) is logged and tried again next time; the others go on. */
    private function each(string $what, string $id, \Closure $work): bool
    {
        try {
            return (bool) $work();
        } catch (\Throwable $e) {
            Log::error('exam_sweep_failed', ['what' => $what, 'id' => $id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function markAbsent(string $id): bool
    {
        $booking = ExamBooking::query()->whereKey($id)->lockForUpdate()->with('session')->firstOrFail();

        if ($booking->status !== BookingStatus::Booked) {
            return false;   // started or cancelled meanwhile
        }

        $application = Application::query()->whereKey($booking->application_id)->lockForUpdate()->firstOrFail();

        if ($application->current_status !== ApplicationStatus::AwaitingExam) {
            $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => Date::now(), 'cancel_reason' => __('exams.bookings.application_moved', [], 'ar')])->save();
            AuditTrail::record('exam_booking.cancelled', null, ['booking_id' => $booking->id, 'reason' => 'application_moved']);

            return false;
        }

        $closedAt = $booking->session->lateUntil();
        $this->lifecycle->move($application, ApplicationStatus::ExamAbsent, DecisionType::Outcome, null, null, $booking, $closedAt);
        $booking->forceFill(['status' => BookingStatus::Absent, 'decided_at' => Date::now()])->save();

        AuditTrail::record('exam_booking.absent', null, ['booking_id' => $booking->id, 'application_id' => $application->id]);

        return true;
    }
}
