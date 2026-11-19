<?php

namespace App\Modules\Recruitment\Enums;

/**
 * Current status of an application (analysis doc §03, diagram 03; D-037, D-087, D-104).
 * The legal moves live here, in one place; the database stores the same values (CHECK).
 *
 * An application opens as received (by hand or from an approved import) and enters the lifecycle when Recruitment
 * starts it. It is live until it ends: its contract ended without renewal, or authorized staff closed it.
 * A person can have only one live application at a time.
 */
enum ApplicationStatus: string
{
    case Received = 'received';
    case AwaitingExam = 'awaiting_exam';
    /** The exam was taken and its result decided; the exams office has not approved the session's results yet (D-104). */
    case ExamTaken = 'exam_taken';
    case ExamFailed = 'exam_failed';
    case ExamAbsent = 'exam_absent';
    case AwaitingInterview = 'awaiting_interview';
    case InterviewFailed = 'interview_failed';
    case InterviewAbsent = 'interview_absent';
    case AwaitingContract = 'awaiting_contract';
    case ContractWritten = 'contract_written';
    case ContractEnded = 'contract_ended';
    case Closed = 'closed';

    public function labelAr(): string
    {
        return match ($this) {
            self::Received => 'مستلم بانتظار البدء',
            self::AwaitingExam => 'بانتظار الاختبار',
            self::ExamTaken => 'أدى الاختبار بانتظار اعتماد النتيجة',
            self::ExamFailed => 'راسب بالاختبارات',
            self::ExamAbsent => 'غائب عن الاختبارات',
            self::AwaitingInterview => 'ناجح بالاختبارات بانتظار المقابلة',
            self::InterviewFailed => 'راسب بالمقابلة',
            self::InterviewAbsent => 'غائب عن المقابلة',
            self::AwaitingContract => 'ناجح بالمقابلة بانتظار كتابة العقد',
            self::ContractWritten => 'تمت كتابة العقد الأول',
            self::ContractEnded => 'انتهى العقد ولم يُجدَّد',
            self::Closed => 'أُغلق الطلب',
        };
    }

    /**
     * Legal next statuses (keyed by value) and the type of decision each one requires (a list when several may: the
     * first is the regular one). Every status before a written contract can be closed by authorized staff; a written
     * contract ends only through contract_ended. A received application starts (to awaiting_exam) or is closed.
     * A decided exam result waits in exam_taken until the session's results are approved: then passed, failed, or
     * failed by decision (a pass the office fails with a reason, D-104). Nothing goes back from a pass.
     *
     * @return array<string, DecisionType|list<DecisionType>>
     */
    public function transitions(): array
    {
        $close = [self::Closed->value => DecisionType::Closure];

        return match ($this) {
            self::Received => [self::AwaitingExam->value => DecisionType::Started] + $close,
            self::AwaitingExam => [
                self::ExamTaken->value => DecisionType::Outcome,
                self::ExamAbsent->value => DecisionType::Outcome,
            ] + $close,
            self::ExamTaken => [
                self::AwaitingInterview->value => DecisionType::Outcome,
                self::ExamFailed->value => [DecisionType::Outcome, DecisionType::FailedByDecision],
            ] + $close,
            self::ExamFailed => [self::AwaitingInterview->value => DecisionType::Exception] + $close,
            self::ExamAbsent => [self::AwaitingExam->value => DecisionType::ExcuseAccepted] + $close,
            self::AwaitingInterview => [
                self::AwaitingContract->value => DecisionType::Outcome,
                self::InterviewFailed->value => DecisionType::Outcome,
                self::InterviewAbsent->value => DecisionType::Outcome,
            ] + $close,
            self::InterviewFailed => $close,
            self::InterviewAbsent => [self::AwaitingInterview->value => DecisionType::ExcuseAccepted] + $close,
            self::AwaitingContract => [self::ContractWritten->value => DecisionType::Outcome] + $close,
            self::ContractWritten => [self::ContractEnded->value => DecisionType::Outcome],
            self::ContractEnded, self::Closed => [],
        };
    }

    /** The decision type needed to move from this status to $to (the regular one when several may), or null if that move is not allowed. */
    public function decisionRequiredFor(self $to): ?DecisionType
    {
        return $this->decisionsFor($to)[0] ?? null;
    }

    /** @return list<DecisionType> the decision types that may move this status to $to ([] when the move is not allowed) */
    public function decisionsFor(self $to): array
    {
        $types = $this->transitions()[$to->value] ?? [];

        return is_array($types) ? $types : [$types];
    }

    /** The application has ended: nothing can change it any more, and the person may be nominated again. */
    public function isTerminal(): bool
    {
        return $this->transitions() === [];
    }

    public function isLive(): bool
    {
        return ! $this->isTerminal();
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            fn(self $status) => $status->value,
            array_filter(self::cases(), fn(self $status) => $status->isTerminal()),
        ));
    }
}
