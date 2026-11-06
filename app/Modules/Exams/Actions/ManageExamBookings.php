<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who sits a session (D-074): applications awaiting their exam, for the session's job position (its plan's), with
 * no other booking waiting or undecided. Booked until the late window closes; a booking is cancelled with a reason
 * while the candidate has not started.
 */
class ManageExamBookings
{
    /**
     * All or nothing: one refused application (422 on `application_ids.N`) books none.
     *
     * @param  list<string>  $applicationIds
     * @return list<ExamBooking>
     */
    public function bookMany(ExamSession $session, array $applicationIds, StaffUser $actor): array
    {
        return DB::transaction(function () use ($session, $applicationIds, $actor) {
            $session = ExamSession::query()->whereKey($session->id)->sharedLock()->with('plan')->firstOrFail();

            if (Date::now()->greaterThanOrEqualTo($session->lateUntil())) {
                $this->fail('session', 'exams.bookings.session_closed');
            }

            $booked = [];

            // Locked in one order (by id) first: two bookings sharing applications in another order never deadlock.
            Application::query()->whereKey($applicationIds)->orderBy('id')->lockForUpdate()->get();

            foreach (array_values($applicationIds) as $index => $id) {
                $application = Application::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->assertBookable($application, $session, "application_ids.{$index}");

                $booking = new ExamBooking;
                $booking->forceFill([
                    'session_id' => $session->id,
                    'application_id' => $application->id,
                    'round' => (int) ExamBooking::query()->where('application_id', $application->id)->max('round') + 1,
                    'created_by' => $actor->id,
                ])->save();
                $booked[] = $booking;

                AuditTrail::record('exam_booking.created', $actor, ['booking_id' => $booking->id, 'session_id' => $session->id, 'application_id' => $application->id, 'round' => $booking->round]);
            }

            return $booked;
        });
    }

    public function cancel(ExamBooking $booking, string $reason, ?StaffUser $actor): ExamBooking
    {
        return DB::transaction(function () use ($booking, $reason, $actor) {
            $booking = ExamBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== BookingStatus::Booked) {
                $this->fail('booking', 'exams.bookings.not_waiting', ['status' => $booking->status->labelAr()]);
            }

            // Once the late window closed, a booking not started is an absence (the sweep records it); cancelling it
            // would put the application back in the queue without an accepted excuse.
            if ($actor !== null && Date::now()->greaterThanOrEqualTo(ExamSession::query()->findOrFail($booking->session_id)->lateUntil())) {
                $this->fail('booking', 'exams.bookings.window_closed');
            }

            $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => Date::now(), 'cancelled_by' => $actor?->id, 'cancel_reason' => trim($reason)])->save();

            AuditTrail::record('exam_booking.cancelled', $actor, ['booking_id' => $booking->id, 'reason' => trim($reason)]);

            return $booking;
        });
    }

    private function assertBookable(Application $application, ExamSession $session, string $field): void
    {
        if ($application->current_status !== ApplicationStatus::AwaitingExam) {
            $this->fail($field, 'exams.bookings.wrong_status', ['status' => $application->current_status->labelAr()]);
        }

        if ($application->job_position_id !== $session->plan->job_position_id) {
            $this->fail($field, 'exams.bookings.other_job_position');
        }

        $open = ExamBooking::query()
            ->where('application_id', $application->id)
            ->whereIn('status', [BookingStatus::Booked, BookingStatus::Attended])
            ->whereNull('decided_at')
            ->exists();

        if ($open) {
            $this->fail($field, 'exams.bookings.already_booked');
        }
    }

    private function fail(string $field, string $key, array $replace = []): never
    {
        throw ValidationException::withMessages([$field => [__($key, $replace)]]);
    }
}
