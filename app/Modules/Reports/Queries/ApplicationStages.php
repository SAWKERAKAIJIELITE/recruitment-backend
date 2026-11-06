<?php

namespace App\Modules\Reports\Queries;

use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\ApplicationStatusEvent;
use Carbon\CarbonInterface;

/**
 * What happened to an application, read from its status history (D-037, D-038): when it was nominated, every exam
 * decision with what followed it (an exception admitting a failed candidate, an accepted absence excuse), and the
 * accepted excuses of its interview absences. A correction replaces the decision it corrects and keeps that
 * decision's date: the exam happened then.
 *
 * Each exam decision carries the booking that caused it (exam sessions, D-074), whose marks ExamScores reads.
 */
final class ApplicationStages
{
    /**
     * @return array{
     *     nominated_at: ?CarbonInterface,
     *     exams: list<array{round: int, result: string, at: CarbonInterface, corrected: bool, reason: ?string, booking_id: ?string, exception: ?array{at: CarbonInterface, reason: ?string}, excuse: ?array{at: CarbonInterface, reason: ?string}}>,
     *     interview_excuses: array<string, array{at: CarbonInterface, reason: ?string}>
     * }
     */
    public static function of(Application $application): array
    {
        $nominatedAt = null;
        $exams = [];
        $interviewExcuses = [];
        $stageOf = [];

        foreach ($application->history() as $event) {
            /** @var ApplicationStatusEvent $event */
            $previous = $event->previousEvent;
            // The status the decision started from; a correction starts where the decision it replaces started.
            $stage = $previous === null ? null : ($event->decision_type === D::Correction ? $stageOf[$previous->id] : $previous->to_status);
            $stageOf[$event->id] = $stage;
            $last = array_key_last($exams);

            match (true) {
                $event->decision_type === D::Created => $nominatedAt = $event->occurred_at,
                $stage === S::AwaitingExam && $event->decision_type === D::Outcome => $exams[] = [
                    'round' => count($exams) + 1,
                    'result' => self::examResult($event->to_status),
                    'at' => $event->occurred_at,
                    'corrected' => false,
                    'reason' => $event->reason,
                    'booking_id' => $event->exam_booking_id,
                    'exception' => null,
                    'excuse' => null,
                ],
                $stage === S::AwaitingExam && $event->decision_type === D::Correction && $last !== null => $exams[$last] = ['result' => self::examResult($event->to_status), 'corrected' => true] + $exams[$last],
                $stage === S::ExamFailed && $event->decision_type === D::Exception && $last !== null => $exams[$last]['exception'] = ['at' => $event->occurred_at, 'reason' => $event->reason],
                $stage === S::ExamAbsent && $event->decision_type === D::ExcuseAccepted && $last !== null => $exams[$last]['excuse'] = ['at' => $event->occurred_at, 'reason' => $event->reason],
                $stage === S::InterviewAbsent && $event->decision_type === D::ExcuseAccepted && $event->interview_id !== null => $interviewExcuses[$event->interview_id] = ['at' => $event->occurred_at, 'reason' => $event->reason],
                default => null,
            };
        }

        return ['nominated_at' => $nominatedAt, 'exams' => $exams, 'interview_excuses' => $interviewExcuses];
    }

    private static function examResult(S $status): string
    {
        return match ($status) {
            S::AwaitingInterview => 'passed',
            S::ExamFailed => 'failed',
            S::ExamAbsent => 'absent',
            default => throw new \LogicException("{$status->value} is not an exam outcome"),
        };
    }
}
