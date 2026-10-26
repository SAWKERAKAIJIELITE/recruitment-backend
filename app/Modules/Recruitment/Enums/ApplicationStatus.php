<?php

namespace App\Modules\Recruitment\Enums;

/**
 * Current status of an application (analysis doc §03, diagram 03; D-037).
 * The legal moves live here, in one place; the database stores the same values (CHECK).
 *
 * An application is live until it ends: its contract ended without renewal, or authorized staff closed it.
 * A person can have only one live application at a time.
 */
enum ApplicationStatus: string
{
    case AwaitingExam = 'awaiting_exam';
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
            self::AwaitingExam => 'بانتظار الاختبار',
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
     * Legal next statuses (keyed by value) and the type of decision each one requires.
     * Every status before a written contract can be closed by authorized staff; a written contract
     * ends only through contract_ended.
     *
     * @return array<string, DecisionType>
     */
    public function transitions(): array
    {
        $close = [self::Closed->value => DecisionType::Closure];

        return match ($this) {
            self::AwaitingExam => [
                self::AwaitingInterview->value => DecisionType::Outcome,
                self::ExamFailed->value => DecisionType::Outcome,
                self::ExamAbsent->value => DecisionType::Outcome,
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

    /** The decision type needed to move from this status to $to, or null if that move is not allowed. */
    public function decisionRequiredFor(self $to): ?DecisionType
    {
        return $this->transitions()[$to->value] ?? null;
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
