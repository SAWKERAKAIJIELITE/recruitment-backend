<?php

namespace Tests\Feature\Exams;

use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Scoring\ExamScorer;
use App\Modules\Exams\Scoring\Mark;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\ExamTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Exam plan versions per job position (D-053, D-054). */
class ExamPlanApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, ExamTestHelpers;

    private const URL = '/api/v1/staff/exam-plans';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function draftId($manager, $position): string
    {
        return $this->asStaff($manager)->postJson(self::URL, ['job_position_id' => $position->id])->assertCreated()->json('data.id');
    }

    // ---- drafts ----

    public function test_a_first_draft_is_empty_version_1_under_the_current_policy(): void
    {
        $this->asStaff($this->examManager())->postJson(self::URL, ['job_position_id' => $this->position()->id])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subjects', [])
            ->assertJsonPath('data.question_count', 0)
            ->assertJsonPath('data.pass_policy', [
                'version' => 1, 'subject_pass_mark' => '60.00', 'tolerance_floor' => '58.00',
                'max_tolerated_subjects' => 1, 'average_must_exceed' => '70.00',
            ]);
    }

    public function test_a_draft_is_replaced_whole_in_exam_order_with_questions_per_difficulty(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject('الحديث النبوي');
        $seerah = $this->subject('السيرة النبوية');
        $id = $this->draftId($manager, $this->position());

        $content = $this->planContent([$seerah, $hadith], $this->quran(), stock: false);
        $content['subjects'][2]['parts'][0]['weight'] = '65';   // a plan's own weight; التجويد keeps its default 40

        $this->asStaff($manager)->putJson(self::URL . "/{$id}", $content)
            ->assertOk()
            ->assertJsonPath('data.subjects.0.subject.name_ar', 'السيرة النبوية')
            ->assertJsonPath('data.subjects.1.subject.name_ar', 'الحديث النبوي')
            ->assertJsonPath('data.subjects.0.questions', ['easy' => 2, 'medium' => 2, 'hard' => 1])
            ->assertJsonPath('data.subjects.0.question_count', 5)
            ->assertJsonPath('data.subjects.2.scored_from_parts', true)
            ->assertJsonPath('data.subjects.2.questions', null)
            ->assertJsonPath('data.subjects.2.parts.0.assessment', 'exam')
            ->assertJsonPath('data.subjects.2.parts.0.weight', '65.00')
            ->assertJsonPath('data.subjects.2.parts.0.max_score', null)
            ->assertJsonPath('data.subjects.2.parts.1.assessment', 'manual')
            ->assertJsonPath('data.subjects.2.parts.1.weight', '40.00')
            ->assertJsonPath('data.subjects.2.parts.1.questions', null)
            ->assertJsonPath('data.subjects.2.parts.1.max_score', '40.00')
            ->assertJsonPath('data.subjects.2.question_count', 5)     // التجويد asks no online question
            ->assertJsonPath('data.question_count', 15);

        // Replacing again replaces everything.
        $this->asStaff($manager)->putJson(self::URL . "/{$id}", $this->planContent([$hadith], stock: false))
            ->assertOk()->assertJsonCount(1, 'data.subjects')->assertJsonPath('data.question_count', 5);
    }

    public function test_a_part_can_be_assessed_differently_from_its_default(): void
    {
        $manager = $this->examManager();
        [$quran, $recitation, $tajweed] = $this->quran();
        $id = $this->draftId($manager, $this->position());

        $this->asStaff($manager)->putJson(self::URL . "/{$id}", ['duration_minutes' => 60, 'subjects' => [['subject_id' => $quran->id, 'parts' => [
            ['part_id' => $recitation->id, 'assessment' => 'manual'],
            ['part_id' => $tajweed->id, 'assessment' => 'exam', 'questions' => ['easy' => 1, 'medium' => 1, 'hard' => 1]],
        ]]]])
            ->assertOk()
            ->assertJsonPath('data.subjects.0.parts.0.assessment', 'manual')
            ->assertJsonPath('data.subjects.0.parts.0.max_score', '100.00')      // the default scale
            ->assertJsonPath('data.subjects.0.parts.1.assessment', 'exam')
            ->assertJsonPath('data.subjects.0.parts.1.questions.hard', 1);
    }

    public function test_draft_content_is_checked(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject();
        [$quran, $recitation, $tajweed] = $this->quran();
        $other = $this->subject('فقه العبادات');
        $archived = $this->subject('مادة مؤرشفة');
        $archived->delete();
        $id = $this->draftId($manager, $this->position());
        $put = fn(array $subjects) => $this->asStaff($manager)->putJson(self::URL . "/{$id}", ['duration_minutes' => 60, 'subjects' => $subjects]);
        $q = ['easy' => 1, 'medium' => 1, 'hard' => 1];

        $put([['subject_id' => $hadith->id, 'questions' => $q], ['subject_id' => $hadith->id, 'questions' => $q]])->assertStatus(422)->assertJsonValidationErrors('subjects.1.subject_id');
        $put([['subject_id' => $archived->id, 'questions' => $q]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.subject_id');
        $put([['subject_id' => $other->id, 'parts' => [['part_id' => $recitation->id, 'questions' => $q]]]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.parts.0.part_id');
        $put([['subject_id' => $quran->id, 'questions' => $q, 'parts' => [['part_id' => $recitation->id, 'questions' => $q]]]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.questions');
        $put([['subject_id' => $quran->id, 'parts' => [['part_id' => $tajweed->id, 'questions' => $q]]]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.parts.0.questions');
        $put([['subject_id' => $quran->id, 'parts' => [['part_id' => $recitation->id, 'max_score' => 50]]]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.parts.0.max_score');
        $put([['subject_id' => $quran->id, 'parts' => [['part_id' => $recitation->id, 'assessment' => 'oral']]]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.parts.0.assessment');
        $put([['subject_id' => $quran->id, 'parts' => [['part_id' => $recitation->id, 'weight' => 0, 'questions' => $q]]]])->assertStatus(422)->assertJsonValidationErrors('subjects.0.parts.0.weight');
    }

    public function test_every_difficulty_is_asked_at_least_once(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject();
        $id = $this->draftId($manager, $this->position());
        $put = fn(array $questions) => $this->asStaff($manager)->putJson(self::URL . "/{$id}", ['duration_minutes' => 60, 'subjects' => [['subject_id' => $hadith->id, 'questions' => $questions]]]);

        $put(['easy' => 5, 'medium' => 5, 'hard' => 0])->assertStatus(422)->assertJsonValidationErrors('subjects.0.questions.hard');
        $put(['easy' => 5, 'medium' => 5])->assertStatus(422)->assertJsonValidationErrors('subjects.0.questions');
        $put(['easy' => 5, 'medium' => 5, 'hard' => null])->assertStatus(422)->assertJsonValidationErrors('subjects.0.questions.hard');
        $put(['easy' => 5, 'medium' => 5, 'hard' => 1, 'expert' => 1])->assertStatus(422)->assertJsonValidationErrors('subjects.0.questions');
        $put(['easy' => 5, 'medium' => 5, 'hard' => 1])->assertOk();
    }

    public function test_one_draft_at_a_time_per_job_position(): void
    {
        $manager = $this->examManager();
        $position = $this->position();
        $this->draftId($manager, $position);

        $this->asStaff($manager)->postJson(self::URL, ['job_position_id' => $position->id])
            ->assertStatus(422)->assertJsonPath('errors.job_position_id.0', __('exams.draft_exists', ['version' => 1]));
        $this->draftId($manager, $this->position('مدير مكتب'));
    }

    public function test_no_draft_for_an_archived_job_position(): void
    {
        $position = $this->position();
        $position->delete();

        $this->asStaff($this->examManager())->postJson(self::URL, ['job_position_id' => $position->id])
            ->assertStatus(422)->assertJsonValidationErrors('job_position_id');
    }

    public function test_a_draft_is_deleted_a_published_version_is_not(): void
    {
        $manager = $this->examManager();
        $position = $this->position();
        $id = $this->draftId($manager, $position);

        $this->asStaff($manager)->deleteJson(self::URL . "/{$id}")->assertNoContent();
        $this->assertDatabaseCount('exam_plan_versions', 0);

        $published = $this->publishedPlan($position, $this->planContent([$this->subject()]));
        $this->asStaff($manager)->deleteJson(self::URL . "/{$published->id}")
            ->assertStatus(422)->assertJsonPath('errors.status.0', __('exams.not_a_draft', ['status' => 'منشورة']));
    }

    // ---- publishing ----

    public function test_publishing_freezes_the_draft_and_is_audited(): void
    {
        $manager = $this->examManager();
        $id = $this->draftId($manager, $this->position());
        $this->asStaff($manager)->putJson(self::URL . "/{$id}", $this->planContent([$this->subject()], $this->quran()))->assertOk();
        Log::spy();

        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_by.id', $manager->id)
            ->assertJsonPath('data.retired_at', null);

        $this->asStaff($manager)->putJson(self::URL . "/{$id}", $this->planContent([], stock: false))->assertStatus(422)->assertJsonValidationErrors('status');
        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('status');
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'exam_plan.published' && $context['plan_version_id'] === $id)->once();
    }

    public function test_an_incomplete_draft_lists_every_problem(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject();
        [$quran, $recitation, $tajweed] = $this->quran();
        $id = $this->draftId($manager, $this->position());

        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")
            ->assertStatus(422)->assertJsonValidationErrors(['subjects', 'duration_minutes']);

        $this->asStaff($manager)->putJson(self::URL . "/{$id}", ['duration_minutes' => null, 'subjects' => [
            ['subject_id' => $hadith->id],
            ['subject_id' => $quran->id, 'parts' => [['part_id' => $recitation->id], ['part_id' => $tajweed->id]]],
        ]])->assertOk();

        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('errors', [
                'duration_minutes' => [__('exams.duration_required')],
                'subjects.0.questions' => [__('exams.questions_required', ['item' => 'الحديث النبوي'])],
                'subjects.1.parts.0.questions' => [__('exams.questions_required', ['item' => 'القرآن الكريم / التلاوة'])],
            ]);
    }

    public function test_a_plan_with_no_online_question_is_not_published(): void
    {
        $manager = $this->examManager();
        [$quran, , $tajweed] = $this->quran();
        $id = $this->draftId($manager, $this->position());
        $this->asStaff($manager)->putJson(self::URL . "/{$id}", ['duration_minutes' => 30, 'subjects' => [['subject_id' => $quran->id, 'parts' => [['part_id' => $tajweed->id]]]]])->assertOk();

        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")
            ->assertStatus(422)->assertJsonPath('errors.subjects.0', __('exams.no_exam_questions'));
    }

    public function test_publishing_needs_enough_questions_of_each_difficulty_in_the_bank(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject();
        $quran = $this->quran();
        $id = $this->draftId($manager, $this->position());
        $this->asStaff($manager)->putJson(self::URL . "/{$id}", $this->planContent([$hadith], $quran, stock: false))->assertOk();
        $this->stockBank($hadith, null, 2);                    // 2 of each: hard (1) is enough, easy and medium (2) too
        ExamQuestion::query()->where('subject_id', $hadith->id)->where('difficulty', 'medium')->first()->delete();   // archived: not counted
        $this->stockBank($quran[0], $quran[2], 5);             // questions of التجويد do not count for التلاوة

        $this->asStaff($manager)->getJson(self::URL . "/{$id}/readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.problems', [
                'subjects.0.questions.medium' => [__('exams.bank_short', ['item' => 'الحديث النبوي', 'difficulty' => 'متوسط', 'have' => 1, 'need' => 2])],
                'subjects.1.parts.0.questions.easy' => [__('exams.bank_short', ['item' => 'القرآن الكريم / التلاوة', 'difficulty' => 'سهل', 'have' => 0, 'need' => 2])],
                'subjects.1.parts.0.questions.medium' => [__('exams.bank_short', ['item' => 'القرآن الكريم / التلاوة', 'difficulty' => 'متوسط', 'have' => 0, 'need' => 2])],
                'subjects.1.parts.0.questions.hard' => [__('exams.bank_short', ['item' => 'القرآن الكريم / التلاوة', 'difficulty' => 'صعب', 'have' => 0, 'need' => 1])],
            ]);
        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('subjects.0.questions.medium');

        $this->stockBank($hadith, null, 1);
        $this->stockBank($quran[0], $quran[1], 2);
        $this->asStaff($manager)->getJson(self::URL . "/{$id}/readiness")->assertJsonPath('data.ready', true);
        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")->assertOk();
    }

    public function test_a_subject_scored_as_a_whole_draws_on_all_its_questions(): void
    {
        $manager = $this->examManager();
        [$quran, $recitation] = $this->quran();
        $id = $this->draftId($manager, $this->position());
        $this->asStaff($manager)->putJson(self::URL . "/{$id}", ['duration_minutes' => 30, 'subjects' => [['subject_id' => $quran->id, 'questions' => $this->askedQuestions]]])->assertOk();
        $this->stockBank($quran, $recitation, 2);

        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")->assertOk();
    }

    public function test_a_subject_or_part_archived_after_it_was_added_blocks_publishing(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject();
        $quran = $this->quran();
        $id = $this->draftId($manager, $this->position());
        $this->asStaff($manager)->putJson(self::URL . "/{$id}", $this->planContent([$hadith], $quran))->assertOk();
        $hadith->delete();
        $quran[2]->delete();

        $this->asStaff($manager)->postJson(self::URL . "/{$id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('errors', [
                'subjects.0.subject_id' => [__('exams.subject_archived', ['subject' => 'الحديث النبوي'])],
                'subjects.1.parts.1.part_id' => [__('exams.part_archived', ['part' => 'التجويد', 'subject' => 'القرآن الكريم'])],
            ]);
    }

    public function test_a_new_version_copies_the_published_one_and_retires_it_when_published(): void
    {
        $manager = $this->examManager();
        $position = $this->position();
        $v1 = $this->publishedPlan($position, $this->planContent([$this->subject()], $this->quran()));

        $v2 = $this->asStaff($manager)->postJson(self::URL, ['job_position_id' => $position->id])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.duration_minutes', 120)
            ->assertJsonPath('data.question_count', 10)
            ->assertJsonPath('data.subjects.1.parts.0.weight', '60.00')
            ->assertJsonPath('data.subjects.1.parts.1.assessment', 'manual')
            ->assertJsonPath('data.subjects.1.parts.1.max_score', '40.00')
            ->json('data.id');

        $this->asStaff($manager)->postJson(self::URL . "/{$v2}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        $this->asStaff($manager)->getJson(self::URL . "/{$v1->id}")->assertJsonPath('data.status', 'retired')->assertJsonPath('data.question_count', 10);
        $this->assertNotNull($v1->fresh()->retired_at);
        $this->asStaff($manager)->getJson(self::URL . "?job_position_id={$position->id}&status=published")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $v2);
    }

    public function test_a_draft_can_start_from_an_older_version_of_the_same_position_only(): void
    {
        $manager = $this->examManager();
        $position = $this->position();
        $v1 = $this->publishedPlan($position, $this->planContent([$this->subject()]));
        $this->publishedPlan($position, $this->planContent([$this->subject('السيرة النبوية'), $this->subject('فقه العبادات')]));
        $other = $this->publishedPlan($this->position('سائق'), $this->planContent([$this->subject('البناء الإيماني')]));

        $this->asStaff($manager)->postJson(self::URL, ['job_position_id' => $position->id, 'copy_from' => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors('copy_from');
        $this->asStaff($manager)->postJson(self::URL, ['job_position_id' => $position->id, 'copy_from' => $v1->id])
            ->assertCreated()->assertJsonPath('data.version', 3)->assertJsonCount(1, 'data.subjects');
    }

    public function test_changing_part_defaults_never_changes_a_published_plan(): void
    {
        $quran = $this->quran();
        $plan = $this->publishedPlan($this->position(), $this->planContent([], $quran));

        $this->asStaff($this->examManager())->patchJson("/api/v1/staff/exam-subject-parts/{$quran[1]->id}", ['default_weight' => 90, 'default_assessment' => 'manual'])->assertOk();

        $this->asStaff($this->examManager())->getJson(self::URL . "/{$plan->id}")
            ->assertJsonPath('data.subjects.0.parts.0.weight', '60.00')
            ->assertJsonPath('data.subjects.0.parts.0.assessment', 'exam');
    }

    public function test_a_published_plan_feeds_the_scoring_engine_with_the_manual_mark(): void
    {
        $hadith = $this->subject();
        [$quran, $recitation, $tajweed] = $this->quran();
        $plan = $this->publishedPlan($this->position(), $this->planContent([$hadith], [$quran, $recitation, $tajweed]));

        // التلاوة from the exam (47 of 60 points), التجويد entered by staff: 38 out of its scale of 40.
        $outcome = (new ExamScorer())->score(
            $plan->scoringPlan(),
            [$hadith->id => Mark::of(80), $quran->id => [$recitation->id => Mark::of(47, 60), $tajweed->id => Mark::of(38, 40)]],
            $plan->passPolicy->rules(),
        );

        $this->assertSame('85.00', $outcome->subjects[$quran->id]->display());
        $this->assertSame('82.50', $outcome->averageDisplay());
        $this->assertTrue($outcome->passed);
        $this->assertSame(1, $outcome->policyVersion);
    }

    // ---- reading and permissions ----

    public function test_plans_are_listed_and_filtered(): void
    {
        $position = $this->position();
        $this->publishedPlan($position, $this->planContent([$this->subject()]));
        $this->draftId($this->examManager(), $position);
        $this->publishedPlan($this->position('سائق'), $this->planContent([$this->subject('فقه العبادات')]));
        $reader = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($reader)->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data');
        $this->asStaff($reader)->getJson(self::URL . "?job_position_id={$position->id}")->assertJsonCount(2, 'data')->assertJsonPath('data.0.version', 2);
        $this->asStaff($reader)->getJson(self::URL . '?status=draft')->assertJsonCount(1, 'data');
        $this->asStaff($reader)->getJson(self::URL . '?status=old')->assertStatus(422);
    }

    public function test_only_exam_bank_managers_change_plans_or_see_readiness(): void
    {
        $position = $this->position();
        $plan = $this->publishedPlan($position, $this->planContent([$this->subject()]));
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();

        $this->asStaff($staff)->getJson(self::URL . "/{$plan->id}")->assertOk();
        $this->asStaff($staff)->postJson(self::URL, ['job_position_id' => $position->id])->assertForbidden();
        $this->asStaff($staff)->postJson(self::URL . "/{$plan->id}/publish")->assertForbidden();
        $this->asStaff($staff)->getJson(self::URL . "/{$plan->id}/readiness")->assertForbidden();
        $this->asStaff(StaffUserFactory::new()->create())->getJson(self::URL)->assertForbidden();
        $this->asStaff($this->examManager())->getJson(self::URL . '/' . (string) Str::uuid())->assertNotFound();
    }

    public function test_versions_number_up_per_job_position(): void
    {
        $a = $this->position();
        $b = $this->position('سائق');
        $hadith = $this->subject();

        $this->publishedPlan($a, $this->planContent([$hadith]));
        $this->publishedPlan($a, $this->planContent([$hadith], stock: false));
        $this->publishedPlan($b, $this->planContent([$hadith], stock: false));

        $this->assertSame([1, 2], ExamPlanVersion::query()->where('job_position_id', $a->id)->orderBy('version')->pluck('version')->all());
        $this->assertSame([1], ExamPlanVersion::query()->where('job_position_id', $b->id)->pluck('version')->all());
    }
}
