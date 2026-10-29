<?php

namespace App\Modules\Exams\Scoring;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * The computed result of an exam: the system's decision only. An administrative exception never changes it; it
 * is recorded as a separate decision (analysis doc §07: computed result = failed, administrative decision =
 * exception accepted).
 */
final readonly class ExamOutcome
{
    public const SUBJECT_BELOW_FLOOR = 'subject_below_floor';

    public const TOO_MANY_BELOW_PASS_MARK = 'too_many_below_pass_mark';

    public const AVERAGE_NOT_ABOVE = 'average_not_above';

    /**
     * @param  array<int, SubjectResult>  $subjects  by subject id, in plan order
     * @param  list<array{code: string, subject_ids?: list<int>}>  $reasons  empty when passed
     */
    public function __construct(
        public array $subjects,
        public BigRational $average,
        public bool $passed,
        public array $reasons,
        public int $policyVersion,
    ) {
    }

    /** Subjects that passed only thanks to the tolerance. */
    public function toleratedSubjectIds(): array
    {
        if (! $this->passed) {
            return [];
        }

        return array_values(array_map(
            fn(SubjectResult $subject) => $subject->subjectId,
            array_filter($this->subjects, fn(SubjectResult $subject) => $subject->band === SubjectResult::IN_TOLERANCE),
        ));
    }

    public function averageDisplay(int $scale = 2): string
    {
        return (string) $this->average->toScale($scale, RoundingMode::HalfUp);
    }

    /** A plain array for storage or JSON. Scores are rounded for display; the decision was made on exact values. */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'average' => $this->averageDisplay(),
            'reasons' => $this->reasons,
            'tolerated_subject_ids' => $this->toleratedSubjectIds(),
            'policy_version' => $this->policyVersion,
            'subjects' => array_values(array_map(fn(SubjectResult $subject) => [
                'subject_id' => $subject->subjectId,
                'score' => $subject->display(),
                'band' => $subject->band,
                'parts' => array_map(fn(BigRational $percent) => (string) $percent->toScale(2, RoundingMode::HalfUp), $subject->parts),
            ], $this->subjects)),
        ];
    }
}
