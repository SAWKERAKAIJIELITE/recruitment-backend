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

    /** The stepper's stages, in order (D-100). */
    public const STEPS = ['nomination', 'exam', 'interview', 'contract'];

    private const STEP_LABELS = ['nomination' => 'الترشيح', 'exam' => 'الاختبار', 'interview' => 'المقابلة', 'contract' => 'العقد الأول'];

    /** step => state => label */
    private const STATE_LABELS = [
        'nomination' => ['current' => 'بانتظار البدء', 'done' => 'تم الترشيح', 'closed' => 'أُغلق الطلب'],
        'exam' => ['pending' => 'لم يصل بعد', 'current' => 'بانتظار الاختبار', 'done' => 'ناجح', 'done_by_exception' => 'ناجح باستثناء', 'failed' => 'راسب', 'absent' => 'غائب', 'closed' => 'أُغلق الطلب'],
        'interview' => ['pending' => 'لم يصل بعد', 'current' => 'بانتظار المقابلة', 'done' => 'ناجح', 'failed' => 'راسب', 'absent' => 'غائب', 'closed' => 'أُغلق الطلب'],
        'contract' => ['pending' => 'لم يصل بعد', 'current' => 'بانتظار كتابة العقد', 'done' => 'كُتب العقد', 'ended' => 'انتهى العقد', 'closed' => 'أُغلق الطلب'],
    ];

    /**
     * The application's stages for the candidate page's stepper (D-100), read from its history: nomination, exam,
     * interview, first contract, each `pending` (not reached), `current` (waiting there), `done`, `failed`, `absent`,
     * `ended` (the contract, ended without renewal) or `closed` (the application was closed while waiting there), with
     * the date it got there and its Arabic label. The exam and the interview count their rounds; a passed exam says
     * whether it passed by exception.
     *
     * @return list<array{step: string, label: string, state: string, state_label: string, at: ?string, rounds: ?int, by_exception: ?bool}>
     */
    public static function steps(Application $application): array
    {
        $into = [];
        $rounds = ['exam' => 0, 'interview' => 0];
        $nominatedAt = null;

        foreach ($application->history() as $event) {
            /** @var ApplicationStatusEvent $event */
            $into[$event->to_status->value] = $event;
            $from = $event->previousEvent?->to_status;
            $nominatedAt ??= $event->decision_type === D::Created ? $event->occurred_at : null;

            if ($event->decision_type === D::Outcome && $from === S::AwaitingExam) {
                $rounds['exam']++;
            }

            if ($event->decision_type === D::Outcome && $from === S::AwaitingInterview) {
                $rounds['interview']++;
            }
        }

        $status = $application->current_status;
        $closed = $status === S::Closed ? ($into[S::Closed->value] ?? null) : null;
        $status = $closed === null ? $status : ($closed->previousEvent?->to_status ?? S::Received);
        $reached = match ($status) {
            S::Received => 0,
            S::AwaitingExam, S::ExamFailed, S::ExamAbsent => 1,
            S::AwaitingInterview, S::InterviewFailed, S::InterviewAbsent => 2,
            default => 3,
        };
        $at = fn(S $status) => isset($into[$status->value]) ? $into[$status->value]->occurred_at->toIso8601String() : null;
        $examByException = ($into[S::AwaitingInterview->value] ?? null)?->decision_type === D::Exception;

        // The stage reached, as it stands.
        $here = match ($status) {
            S::Received => ['current', $nominatedAt?->toIso8601String()],
            S::AwaitingExam => ['current', $at(S::AwaitingExam)],
            S::ExamFailed => ['failed', $at(S::ExamFailed)],
            S::ExamAbsent => ['absent', $at(S::ExamAbsent)],
            S::AwaitingInterview => ['current', $at(S::AwaitingInterview)],
            S::InterviewFailed => ['failed', $at(S::InterviewFailed)],
            S::InterviewAbsent => ['absent', $at(S::InterviewAbsent)],
            S::AwaitingContract => ['current', $at(S::AwaitingContract)],
            S::ContractWritten => ['done', $at(S::ContractWritten)],
            S::ContractEnded => ['ended', $at(S::ContractEnded)],
            default => ['current', null],
        };

        if ($closed !== null && $here[0] === 'current') {
            $here = ['closed', $closed->occurred_at->toIso8601String()];
        }

        // When each passed stage was left.
        $passedAt = [
            'nomination' => $nominatedAt?->toIso8601String(),
            'exam' => $at(S::AwaitingInterview),
            'interview' => $at(S::AwaitingContract),
            'contract' => $at(S::ContractWritten),
        ];

        $steps = [];

        foreach (self::STEPS as $index => $step) {
            [$state, $date] = match (true) {
                $index < $reached => ['done', $passedAt[$step]],
                $index === $reached => $here,
                default => ['pending', null],
            };
            $byException = $step === 'exam' && $state === 'done' ? $examByException : null;

            $steps[] = [
                'step' => $step,
                'label' => self::STEP_LABELS[$step],
                'state' => $state,
                'state_label' => self::STATE_LABELS[$step][$byException ? 'done_by_exception' : $state] ?? $state,
                'at' => $date,
                'rounds' => $rounds[$step] ?? null,
                'by_exception' => $byException,
            ];
        }

        return $steps;
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
