<?php

namespace App\Modules\Exams\Scoring;

use Brick\Math\BigRational;

/**
 * The pass/fail engine (analysis doc §04, diagram 04). Pure: no database, no clock.
 *
 *   PartScore      = achieved / max × 100
 *   SubjectScore   = Σ(PartScore × PartWeight) / Σ(PartWeight)     (a subject scored as a whole: its own score)
 *   OverallAverage = Σ(SubjectScore) / SubjectCount                 (subjects weigh equally; a composite subject
 *                                                                   such as القرآن الكريم counts once)
 *
 * Failed when any subject is below the tolerance floor (58), or more subjects than allowed (1) are below the pass
 * mark (60), or the average is not strictly above 70. Otherwise passed. Every comparison is made on exact
 * fractions, never on rounded numbers (§04 "important mathematical rule"); every reason that applies is reported.
 */
final class ExamScorer
{
    /**
     * @param  array<int, Mark|array<int, Mark>>  $marks  subject id => Mark (whole subject) or part id => Mark
     *
     * @throws \InvalidArgumentException when the marks do not match the plan
     */
    public function score(ScoringPlan $plan, array $marks, PassRules $rules): ExamOutcome
    {
        $this->assertMatches($plan, $marks);

        $subjects = [];

        foreach ($plan->subjects as $subjectId => $weights) {
            [$score, $parts] = $weights === null ? [$marks[$subjectId]->percent(), []] : $this->weighted($weights, $marks[$subjectId]);

            $subjects[$subjectId] = new SubjectResult($subjectId, $score, $this->band($score, $rules), $parts);
        }

        $average = BigRational::sum(...array_map(fn(SubjectResult $subject) => $subject->score, array_values($subjects)))
            ->dividedBy(count($subjects));

        $reasons = [];

        $belowFloor = $this->ids($subjects, fn(SubjectResult $subject) => $subject->band === SubjectResult::BELOW_FLOOR);
        if ($belowFloor !== []) {
            $reasons[] = ['code' => ExamOutcome::SUBJECT_BELOW_FLOOR, 'subject_ids' => $belowFloor];
        }

        $belowPassMark = $this->ids($subjects, fn(SubjectResult $subject) => $subject->band !== SubjectResult::PASSED);
        if (count($belowPassMark) > $rules->maxToleratedSubjects) {
            $reasons[] = ['code' => ExamOutcome::TOO_MANY_BELOW_PASS_MARK, 'subject_ids' => $belowPassMark];
        }

        if (! $average->isGreaterThan($rules->averageMustExceed)) {
            $reasons[] = ['code' => ExamOutcome::AVERAGE_NOT_ABOVE];
        }

        return new ExamOutcome($subjects, $average, $reasons === [], $reasons, $rules->version);
    }

    /** @return array{0: BigRational, 1: array<int, BigRational>} */
    private function weighted(array $weights, array $marks): array
    {
        $sum = BigRational::zero();
        $parts = [];

        foreach ($weights as $partId => $weight) {
            $parts[$partId] = $marks[$partId]->percent();
            $sum = $sum->plus($parts[$partId]->multipliedBy($weight));
        }

        return [$sum->dividedBy(BigRational::sum(...array_values($weights))), $parts];
    }

    private function band(BigRational $score, PassRules $rules): string
    {
        return match (true) {
            $score->isGreaterThanOrEqualTo($rules->subjectPassMark) => SubjectResult::PASSED,
            $score->isGreaterThanOrEqualTo($rules->toleranceFloor) => SubjectResult::IN_TOLERANCE,
            default => SubjectResult::BELOW_FLOOR,
        };
    }

    /** @return list<int> */
    private function ids(array $subjects, callable $filter): array
    {
        return array_values(array_map(fn(SubjectResult $subject) => $subject->subjectId, array_filter($subjects, $filter)));
    }

    /** Every subject and part of the plan has exactly one mark, and nothing else does. */
    private function assertMatches(ScoringPlan $plan, array $marks): void
    {
        $missing = array_diff_key($plan->subjects, $marks);
        $extra = array_diff_key($marks, $plan->subjects);

        if ($missing !== [] || $extra !== []) {
            throw new \InvalidArgumentException('Marks must cover exactly the subjects of the plan.');
        }

        foreach ($plan->subjects as $subjectId => $weights) {
            $mark = $marks[$subjectId];

            if ($weights === null ? ! $mark instanceof Mark : (! is_array($mark) || ! $this->sameKeys($mark, $weights))) {
                throw new \InvalidArgumentException("Subject {$subjectId}: give one mark for the subject, or one per part, as the plan says.");
            }

            if (is_array($mark)) {
                foreach ($mark as $partMark) {
                    if (! $partMark instanceof Mark) {
                        throw new \InvalidArgumentException("Subject {$subjectId}: every part mark must be a Mark.");
                    }
                }
            }
        }
    }

    private function sameKeys(array $a, array $b): bool
    {
        return array_diff_key($a, $b) === [] && array_diff_key($b, $a) === [];
    }
}
