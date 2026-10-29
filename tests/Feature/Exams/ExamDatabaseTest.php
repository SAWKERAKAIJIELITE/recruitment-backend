<?php

namespace Tests\Feature\Exams;

use App\Modules\Exams\Actions\ManageExamPlans;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ExamTestHelpers;
use Tests\TestCase;

/** What the database itself guarantees about exam plans, pass policies and the question bank (D-052 to D-055). */
class ExamDatabaseTest extends TestCase
{
    use RefreshDatabase, ExamTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function refused(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database accepted the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function published(): ExamPlanVersion
    {
        return $this->publishedPlan($this->position(), $this->planContent([$this->subject()], $this->quran()));
    }

    private function draftWithQuran(): array
    {
        $draft = app(ManageExamPlans::class)->createDraft($this->position(), $this->examManager());
        [$quran, $recitation, $tajweed] = $this->quran();
        $planSubject = DB::table('exam_plan_subjects')->insertGetId(['plan_version_id' => $draft->id, 'subject_id' => $quran->id, 'position' => 1]);

        return [$draft, $quran, $recitation, $tajweed, $planSubject];
    }

    public function test_pass_policy_1_is_there_and_never_changes(): void
    {
        $this->assertDatabaseHas('exam_pass_policies', ['version' => 1, 'subject_pass_mark' => '60.00', 'tolerance_floor' => '58.00', 'max_tolerated_subjects' => 1, 'average_must_exceed' => '70.00']);

        $this->refused(fn() => DB::table('exam_pass_policies')->update(['subject_pass_mark' => '50']));
        $this->refused(fn() => DB::table('exam_pass_policies')->delete());
        $this->refused(fn() => DB::table('exam_pass_policies')->insert(['version' => 2, 'subject_pass_mark' => '60', 'tolerance_floor' => '61', 'max_tolerated_subjects' => 1, 'average_must_exceed' => '70', 'description' => 'x']));
    }

    public function test_a_published_version_and_its_content_never_change(): void
    {
        $plan = $this->published();
        $subjectRow = DB::table('exam_plan_subjects')->where('plan_version_id', $plan->id)->first();
        $partRow = DB::table('exam_plan_subject_parts')->where('plan_version_id', $plan->id)->first();

        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $plan->id)->update(['duration_minutes' => 10]));
        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $plan->id)->update(['status' => 'draft', 'published_at' => null, 'published_by' => null]));
        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $plan->id)->delete());
        $this->refused(fn() => DB::table('exam_plan_subjects')->where('id', $subjectRow->id)->update(['easy_count' => 9]));
        $this->refused(fn() => DB::table('exam_plan_subjects')->where('id', $subjectRow->id)->delete());
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->where('id', $partRow->id)->update(['weight' => '1']));
        $this->refused(fn() => DB::table('exam_plan_subjects')->insert(['plan_version_id' => $plan->id, 'subject_id' => $this->subject('فقه العبادات')->id, 'position' => 9, 'easy_count' => 1, 'medium_count' => 1, 'hard_count' => 1]));
    }

    public function test_published_becomes_retired_and_then_nothing(): void
    {
        $plan = $this->published();

        DB::table('exam_plan_versions')->where('id', $plan->id)->update(['status' => 'retired', 'retired_at' => now()]);
        $this->assertSame('retired', $plan->fresh()->status->value);

        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $plan->id)->update(['status' => 'published', 'retired_at' => null]));
        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $plan->id)->update(['notes' => 'x']));
    }

    public function test_retiring_changes_nothing_else(): void
    {
        $plan = $this->published();

        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $plan->id)->update(['status' => 'retired', 'retired_at' => now(), 'duration_minutes' => 5]));
    }

    public function test_a_draft_is_published_before_it_is_retired(): void
    {
        $draft = app(ManageExamPlans::class)->createDraft($this->position(), $this->examManager());

        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $draft->id)->update(['status' => 'retired', 'retired_at' => now(), 'published_at' => now(), 'published_by' => $draft->created_by, 'duration_minutes' => 60]));
    }

    public function test_publication_data_matches_the_status(): void
    {
        $draft = app(ManageExamPlans::class)->createDraft($this->position(), $this->examManager());

        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $draft->id)->update(['status' => 'published', 'duration_minutes' => 60]));   // no publisher, no date
        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $draft->id)->update(['published_at' => now()]));   // a draft has no publication date
        $this->refused(fn() => DB::table('exam_plan_versions')->where('id', $draft->id)->update(['status' => 'published', 'published_at' => now(), 'published_by' => $draft->created_by]));   // no duration
    }

    public function test_one_published_and_one_draft_per_job_position(): void
    {
        $plan = $this->published();
        $base = [
            'job_position_id' => $plan->job_position_id, 'duration_minutes' => 60,
            'pass_policy_id' => $plan->pass_policy_id, 'created_by' => $plan->created_by, 'created_at' => now(), 'updated_at' => now(),
        ];

        $this->refused(fn() => DB::table('exam_plan_versions')->insert($base + ['id' => (string) Str::uuid7(), 'version' => 2, 'status' => 'published', 'published_at' => now(), 'published_by' => $plan->created_by]));

        DB::table('exam_plan_versions')->insert($base + ['id' => (string) Str::uuid7(), 'version' => 2, 'status' => 'draft']);
        $this->refused(fn() => DB::table('exam_plan_versions')->insert($base + ['id' => (string) Str::uuid7(), 'version' => 3, 'status' => 'draft']));
        $this->refused(fn() => DB::table('exam_plan_versions')->insert($base + ['id' => (string) Str::uuid7(), 'version' => 1, 'status' => 'retired', 'published_at' => now(), 'published_by' => $plan->created_by, 'retired_at' => now()]));
    }

    public function test_a_plan_part_must_be_a_part_of_its_plan_subject(): void
    {
        [$quran, $recitation] = $this->quran();
        $hadith = $this->subject();
        $draft = app(ManageExamPlans::class)->createDraft($this->position(), $this->examManager());
        $planSubject = DB::table('exam_plan_subjects')->insertGetId(['plan_version_id' => $draft->id, 'subject_id' => $hadith->id, 'position' => 1]);
        $part = ['plan_subject_id' => $planSubject, 'plan_version_id' => $draft->id, 'part_id' => $recitation->id, 'position' => 1, 'weight' => '1', 'assessment' => 'exam'];

        // Recitation belongs to the Quran, not to the hadith; nor can the row claim the Quran while its plan subject is the hadith.
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['subject_id' => $hadith->id]));
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['subject_id' => $quran->id]));
    }

    public function test_every_difficulty_or_none(): void
    {
        [$draft, $quran, $recitation, , $planSubject] = $this->draftWithQuran();
        $part = ['plan_subject_id' => $planSubject, 'plan_version_id' => $draft->id, 'subject_id' => $quran->id, 'part_id' => $recitation->id, 'position' => 1, 'weight' => '1', 'assessment' => 'exam'];

        $this->refused(fn() => DB::table('exam_plan_subjects')->insert(['plan_version_id' => $draft->id, 'subject_id' => $this->subject()->id, 'position' => 2, 'easy_count' => 3, 'medium_count' => 3, 'hard_count' => 0]));
        $this->refused(fn() => DB::table('exam_plan_subjects')->insert(['plan_version_id' => $draft->id, 'subject_id' => $this->subject('مادة')->id, 'position' => 3, 'easy_count' => 3, 'medium_count' => null, 'hard_count' => 1]));
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['easy_count' => 1, 'medium_count' => 0, 'hard_count' => 1]));

        DB::table('exam_plan_subject_parts')->insert($part + ['easy_count' => 1, 'medium_count' => 1, 'hard_count' => 1]);
        $this->assertDatabaseCount('exam_plan_subject_parts', 1);
    }

    public function test_a_manual_part_has_a_scale_and_no_questions_an_exam_part_the_reverse(): void
    {
        [$draft, $quran, , $tajweed, $planSubject] = $this->draftWithQuran();
        $part = ['plan_subject_id' => $planSubject, 'plan_version_id' => $draft->id, 'subject_id' => $quran->id, 'part_id' => $tajweed->id, 'position' => 1, 'weight' => '40'];

        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['assessment' => 'manual']));                                                        // no scale
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['assessment' => 'manual', 'max_score' => '40', 'easy_count' => 1, 'medium_count' => 1, 'hard_count' => 1]));
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['assessment' => 'exam', 'max_score' => '40']));
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['assessment' => 'oral', 'max_score' => '40']));
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert($part + ['assessment' => 'manual', 'max_score' => '0']));
        $this->refused(fn() => DB::table('exam_plan_subject_parts')->insert(array_replace($part, ['assessment' => 'manual', 'max_score' => '40', 'weight' => '0'])));

        DB::table('exam_plan_subject_parts')->insert($part + ['assessment' => 'manual', 'max_score' => '40']);
        $this->assertDatabaseCount('exam_plan_subject_parts', 1);
    }

    public function test_part_defaults_are_checked(): void
    {
        [, , $recitation] = $this->quran();

        $this->refused(fn() => DB::table('exam_subject_parts')->where('id', $recitation->id)->update(['default_weight' => '-1']));
        $this->refused(fn() => DB::table('exam_subject_parts')->where('id', $recitation->id)->update(['default_assessment' => 'oral']));
    }

    public function test_deleting_a_draft_takes_its_content(): void
    {
        $plans = app(ManageExamPlans::class);
        $manager = $this->examManager();
        $draft = $plans->createDraft($this->position(), $manager);
        $plans->replaceDraft($draft, $this->planContent([$this->subject()], $this->quran(), stock: false), $manager);

        $plans->deleteDraft($draft, $manager);

        $this->assertDatabaseCount('exam_plan_subjects', 0);
        $this->assertDatabaseCount('exam_plan_subject_parts', 0);
    }

    // ---- question bank ----

    public function test_question_versions_never_change(): void
    {
        $subject = $this->subject();
        $this->stockBank($subject, null, 1);
        $version = DB::table('exam_question_versions')->first();

        $this->refused(fn() => DB::table('exam_question_versions')->where('id', $version->id)->update(['correct_option' => 1]));
        $this->refused(fn() => DB::table('exam_question_versions')->where('id', $version->id)->delete());
    }

    public function test_a_question_version_is_well_formed(): void
    {
        $subject = $this->subject();
        $author = StaffUserFactory::new()->create();
        $question = DB::table('exam_questions')->insertGetId(['subject_id' => $subject->id, 'difficulty' => 'easy', 'created_by' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
        $version = fn(array $values) => fn() => DB::table('exam_question_versions')->insert($values + ['question_id' => $question, 'version' => 1, 'prompt' => 'سؤال', 'options' => json_encode(['أ', 'ب']), 'correct_option' => 0, 'created_by' => $author->id]);

        $this->refused($version(['options' => json_encode(['أ'])]));                       // fewer than two options
        $this->refused($version(['options' => json_encode(range(1, 7))]));                 // more than six
        $this->refused($version(['options' => json_encode(['أ' => 1])]));                  // not a list
        $this->refused($version(['correct_option' => 2]));                                  // not one of the options
        $this->refused($version(['prompt' => '  ']));
        $this->refused(fn() => DB::table('exam_questions')->insert(['subject_id' => $subject->id, 'difficulty' => 'expert', 'created_by' => $author->id]));
    }

    public function test_a_question_part_belongs_to_its_subject(): void
    {
        [, $recitation] = $this->quran();
        $hadith = $this->subject();

        $this->refused(fn() => DB::table('exam_questions')->insert(['subject_id' => $hadith->id, 'part_id' => $recitation->id, 'difficulty' => 'easy', 'created_by' => StaffUserFactory::new()->create()->id]));
    }
}
