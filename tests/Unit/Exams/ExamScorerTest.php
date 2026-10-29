<?php

namespace Tests\Unit\Exams;

use App\Modules\Exams\Scoring\ExamOutcome;
use App\Modules\Exams\Scoring\ExamScorer;
use App\Modules\Exams\Scoring\Mark;
use App\Modules\Exams\Scoring\PassRules;
use App\Modules\Exams\Scoring\ScoringPlan;
use App\Modules\Exams\Scoring\SubjectResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The pass/fail engine against the analysis doc §04 (rules, formula and worked examples). */
class ExamScorerTest extends TestCase
{
    private function rules(): PassRules
    {
        return new PassRules(1, '60.00', '58.00', 1, '70.00');
    }

    /** Four subjects scored as a whole, ids 1 to 4. */
    private function score(array $percentages): ExamOutcome
    {
        $plan = new ScoringPlan(array_fill_keys(array_keys(array_combine(range(1, count($percentages)), $percentages)), null));
        $marks = [];

        foreach (array_values($percentages) as $i => $value) {
            $marks[$i + 1] = Mark::of($value);
        }

        return (new ExamScorer())->score($plan, $marks, $this->rules());
    }

    /** The doc's table: subjects, average, decision, reasons. */
    public static function docExamples(): array
    {
        return [
            'one subject in tolerance' => [['59', '72', '78', '80'], '72.25', true, []],
            '58 is inside the tolerance' => [['58', '72', '78', '80'], '72.00', true, []],
            'a subject below 58' => [['57.99', '80', '80', '80'], '74.50', false, [ExamOutcome::SUBJECT_BELOW_FLOOR]],
            'two subjects below 60 and the average' => [['59', '59', '80', '80'], '69.50', false, [ExamOutcome::TOO_MANY_BELOW_PASS_MARK, ExamOutcome::AVERAGE_NOT_ABOVE]],
            'average not above 70' => [['60', '70', '75', '75'], '70.00', false, [ExamOutcome::AVERAGE_NOT_ABOVE]],
        ];
    }

    #[DataProvider('docExamples')]
    public function test_the_analysis_docs_worked_examples(array $subjects, string $average, bool $passed, array $reasons): void
    {
        $outcome = $this->score($subjects);

        $this->assertSame($average, $outcome->averageDisplay());
        $this->assertSame($passed, $outcome->passed);
        $this->assertSame($reasons, array_column($outcome->reasons, 'code'));
    }

    public function test_the_tolerated_subject_is_named_only_when_the_exam_passes(): void
    {
        $this->assertSame([1], $this->score(['59', '72', '78', '80'])->toleratedSubjectIds());
        $this->assertSame([], $this->score(['59', '59', '80', '80'])->toleratedSubjectIds());
        $this->assertSame(SubjectResult::IN_TOLERANCE, $this->score(['59', '72', '78', '80'])->subjects[1]->band);
    }

    public function test_reasons_name_the_subjects(): void
    {
        $outcome = $this->score(['50', '59', '90', '95']);

        $this->assertSame([
            ['code' => ExamOutcome::SUBJECT_BELOW_FLOOR, 'subject_ids' => [1]],
            ['code' => ExamOutcome::TOO_MANY_BELOW_PASS_MARK, 'subject_ids' => [1, 2]],
        ], $outcome->reasons);
    }

    public function test_the_quran_is_one_subject_from_its_two_weighted_parts(): void
    {
        // The legacy sheet: التلاوة out of 60 and التجويد out of 40 add up to القرآن الكريم out of 100.
        $plan = new ScoringPlan([1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => [61 => '60', 62 => '40']]);
        $marks = [1 => Mark::of(80), 2 => Mark::of(74), 3 => Mark::of(82), 4 => Mark::of(90), 5 => Mark::of(71), 6 => [61 => Mark::of(47, 60), 62 => Mark::of(38, 40)]];

        $outcome = (new ExamScorer())->score($plan, $marks, $this->rules());

        $this->assertSame('85.00', $outcome->subjects[6]->display());
        $this->assertSame('78.33', (string) $outcome->subjects[6]->parts[61]->toScale(2, \Brick\Math\RoundingMode::HalfUp));
        $this->assertSame('80.33', $outcome->averageDisplay());   // 482 / 600, as in the sheet
        $this->assertCount(6, $outcome->subjects);                 // counted once in the average
        $this->assertTrue($outcome->passed);
    }

    public function test_weights_need_not_add_up_to_100(): void
    {
        $plan = new ScoringPlan([1 => [10 => '3', 11 => '2']]);
        $outcome = (new ExamScorer())->score($plan, [1 => [10 => Mark::of(100), 11 => Mark::of(50)]], $this->rules());

        $this->assertSame('80.00', $outcome->subjects[1]->display());   // (100×3 + 50×2) / 5
    }

    public function test_decisions_use_exact_values_not_rounded_ones(): void
    {
        // 2 of 3 points = 66.666…%; 59.995% would display as 60.00 but is below 60.
        $outcome = $this->score(['59.995', '90', '90', '90']);
        $this->assertSame('60.00', $outcome->subjects[1]->display());
        $this->assertSame(SubjectResult::IN_TOLERANCE, $outcome->subjects[1]->band);

        // An average of exactly 70 + 1/3000: above 70 although it displays as 70.00.
        $plan = new ScoringPlan([1 => null, 2 => null, 3 => null]);
        $marks = [1 => Mark::of('70.001'), 2 => Mark::of(70), 3 => Mark::of(70)];
        $exact = (new ExamScorer())->score($plan, $marks, $this->rules());
        $this->assertSame('70.00', $exact->averageDisplay());
        $this->assertTrue($exact->passed);

        // Thirds: 1/3 point of 1 per part never accumulates rounding errors.
        $thirds = (new ExamScorer())->score(new ScoringPlan([1 => [1 => '1', 2 => '1', 3 => '1']]), [1 => [1 => Mark::of(2, 3), 2 => Mark::of(2, 3), 3 => Mark::of(2, 3)]], $this->rules());
        $this->assertTrue($thirds->subjects[1]->score->isEqualTo(\Brick\Math\BigRational::of('200/3')));
    }

    public function test_the_rules_come_from_the_policy_version(): void
    {
        $strict = new PassRules(2, '65', '65', 0, '75');
        $outcome = (new ExamScorer())->score(new ScoringPlan([1 => null, 2 => null]), [1 => Mark::of(64), 2 => Mark::of(95)], $strict);

        $this->assertFalse($outcome->passed);
        $this->assertSame(2, $outcome->policyVersion);
        $this->assertSame(SubjectResult::BELOW_FLOOR, $outcome->subjects[1]->band);
    }

    public function test_full_and_zero_marks(): void
    {
        $this->assertTrue($this->score(['100', '100', '100', '100'])->passed);
        $this->assertSame('0.00', $this->score(['0', '0', '0', '0'])->averageDisplay());
    }

    public static function badMarks(): array
    {
        return [
            'missing subject' => [[1 => null, 2 => null], fn() => [1 => Mark::of(70)]],
            'extra subject' => [[1 => null], fn() => [1 => Mark::of(70), 2 => Mark::of(70)]],
            'parts for a whole subject' => [[1 => null], fn() => [1 => [5 => Mark::of(70)]]],
            'whole mark for a subject with parts' => [[1 => [5 => '1', 6 => '1']], fn() => [1 => Mark::of(70)]],
            'missing part' => [[1 => [5 => '1', 6 => '1']], fn() => [1 => [5 => Mark::of(70)]]],
            'not a mark' => [[1 => [5 => '1']], fn() => [1 => [5 => 70]]],
        ];
    }

    #[DataProvider('badMarks')]
    public function test_marks_must_match_the_plan(array $plan, \Closure $marks): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ExamScorer())->score(new ScoringPlan($plan), $marks(), $this->rules());
    }

    public function test_impossible_marks_and_plans_are_refused(): void
    {
        foreach ([fn() => Mark::of(101), fn() => Mark::of(-1), fn() => Mark::of(1, 0), fn() => new ScoringPlan([]), fn() => new ScoringPlan([1 => []]), fn() => new ScoringPlan([1 => [2 => '0']]), fn() => new PassRules(1, '60', '61', 1, '70')] as $make) {
            try {
                $make();
                $this->fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
