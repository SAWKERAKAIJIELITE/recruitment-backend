<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Enums\BookingStatus;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The administrative decisions after an exam (analysis doc §07; individual permission exceptions.approve). Neither
 * changes the exam's own result:
 *
 *  - an accepted absence excuse: exam_absent → awaiting_exam, optionally booked at once into the next session (a new
 *    round, a new attempt; the absence stays);
 *  - an exception for a failed exam: exam_failed → awaiting_interview, marked as an exception (the computed result
 *    stays failed).
 */
class ExamDecisions
{
    public function __construct(private readonly ApplicationLifecycle $lifecycle, private readonly ManageExamBookings $bookings) {}

    public function acceptExcuse(Application $application, string $reason, ?CarbonInterface $occurredAt, ?string $sessionId, StaffUser $actor): ?ExamBooking
    {
        return DB::transaction(function () use ($application, $reason, $occurredAt, $sessionId, $actor) {
            $application = $this->lock($application, ApplicationStatus::ExamAbsent);
            $absent = $this->latestBooking($application, BookingStatus::Absent);
            $this->move($application, ApplicationStatus::AwaitingExam, DecisionType::ExcuseAccepted, $actor, $reason, $absent, $occurredAt);

            AuditTrail::record('exam.excuse_accepted', $actor, ['application_id' => $application->id, 'booking_id' => $absent?->id]);

            if ($sessionId === null) {
                return null;
            }

            return $this->bookings->bookMany(ExamSession::query()->findOrFail($sessionId), [$application->id], $actor)[0];
        });
    }

    public function approveException(Application $application, string $reason, ?CarbonInterface $occurredAt, StaffUser $actor): void
    {
        DB::transaction(function () use ($application, $reason, $occurredAt, $actor) {
            $application = $this->lock($application, ApplicationStatus::ExamFailed);
            $failed = $this->latestBooking($application, BookingStatus::Attended);
            $this->move($application, ApplicationStatus::AwaitingInterview, DecisionType::Exception, $actor, $reason, $failed, $occurredAt);

            AuditTrail::record('exam.exception_approved', $actor, ['application_id' => $application->id, 'booking_id' => $failed?->id]);
        });
    }

    private function move(Application $application, ApplicationStatus $to, DecisionType $type, StaffUser $actor, string $reason, ?ExamBooking $cause, ?CarbonInterface $occurredAt): void
    {
        try {
            $this->lifecycle->move($application, $to, $type, $actor, $reason, $cause, $occurredAt);
        } catch (IllegalStatusTransition) {
            throw ValidationException::withMessages(['occurred_at' => [__('exams.decisions.date')]]);
        }
    }

    /** The booking behind the current status (none for an outcome entered before exam sessions existed). */
    private function latestBooking(Application $application, BookingStatus $status): ?ExamBooking
    {
        return ExamBooking::query()->where('application_id', $application->id)->where('status', $status)->orderByDesc('round')->first();
    }

    private function lock(Application $application, ApplicationStatus $expected): Application
    {
        $application = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if ($application->current_status !== $expected) {
            throw ValidationException::withMessages(['application' => [__('exams.decisions.wrong_status', ['status' => $application->current_status->labelAr()])]]);
        }

        return $application;
    }
}
