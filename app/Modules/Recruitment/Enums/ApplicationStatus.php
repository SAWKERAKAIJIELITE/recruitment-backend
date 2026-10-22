<?php

namespace App\Modules\Recruitment\Enums;

/**
 * Current status of an application (analysis doc §03, diagram 03).
 * The legal moves live here, in one place; the database stores the same values (CHECK).
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
        };
    }

    /**
     * Legal next statuses (keyed by value) and the type of decision each one requires.
     *
     * @return array<string, DecisionType>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::AwaitingExam => [
                self::AwaitingInterview->value => DecisionType::Outcome,
                self::ExamFailed->value => DecisionType::Outcome,
                self::ExamAbsent->value => DecisionType::Outcome,
            ],
            self::ExamFailed => [self::AwaitingInterview->value => DecisionType::Exception],
            self::ExamAbsent => [self::AwaitingExam->value => DecisionType::ExcuseAccepted],
            self::AwaitingInterview => [
                self::AwaitingContract->value => DecisionType::Outcome,
                self::InterviewFailed->value => DecisionType::Outcome,
                self::InterviewAbsent->value => DecisionType::Outcome,
            ],
            self::InterviewAbsent => [self::AwaitingInterview->value => DecisionType::ExcuseAccepted],
            self::AwaitingContract => [self::ContractWritten->value => DecisionType::Outcome],
            self::InterviewFailed, self::ContractWritten => [],
        };
    }

    /** The decision type needed to move from this status to $to, or null if that move is not allowed. */
    public function decisionRequiredFor(self $to): ?DecisionType
    {
        return $this->transitions()[$to->value] ?? null;
    }

    public function isTerminal(): bool
    {
        return $this->transitions() === [];
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
