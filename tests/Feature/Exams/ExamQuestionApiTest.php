<?php

namespace Tests\Feature\Exams;

use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExamTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The question bank (analysis doc §05, D-055). */
class ExamQuestionApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, ExamTestHelpers;

    private const URL = '/api/v1/staff/exam-questions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private ?int $defaultSubjectId = null;

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'subject_id' => $this->defaultSubjectId ??= $this->subject('مادة الأسئلة')->id,
            'difficulty' => 'medium',
            'prompt' => 'من راوي حديث «إنما الأعمال بالنيات»؟',
            'options' => ['أبو هريرة', 'عمر بن الخطاب', 'أنس بن مالك'],
            'correct_option' => 1,
        ];
    }

    public function test_a_question_is_added_as_version_1(): void
    {
        Log::spy();

        $this->asStaff($this->examManager())->postJson(self::URL, $this->body(['prompt' => '  سؤال؟  ', 'options' => [' أ ', 'ب']]))
            ->assertCreated()
            ->assertJsonPath('data.difficulty', 'medium')
            ->assertJsonPath('data.part', null)
            ->assertJsonPath('data.current.version', 1)
            ->assertJsonPath('data.current.prompt', 'سؤال؟')
            ->assertJsonPath('data.current.options', ['أ', 'ب'])
            ->assertJsonPath('data.current.correct_option', 1);

        Log::shouldHaveReceived('notice')->withArgs(fn(string $event) => $event === 'exam_question.created')->once();
    }

    public function test_a_question_of_a_part(): void
    {
        [$quran, $recitation] = $this->quran();

        $this->asStaff($this->examManager())->postJson(self::URL, $this->body(['subject_id' => $quran->id, 'part_id' => $recitation->id]))
            ->assertCreated()->assertJsonPath('data.part.name_ar', 'التلاوة');
        $this->asStaff($this->examManager())->postJson(self::URL, $this->body(['part_id' => $recitation->id]))
            ->assertStatus(422)->assertJsonValidationErrors('part_id');
    }

    public function test_questions_are_well_formed(): void
    {
        $manager = $this->examManager();

        foreach ([
            'options' => ['options' => ['وحيد']],
            'options.1' => ['options' => ['أ', 'أ']],
            'correct_option' => ['correct_option' => 3],
            'difficulty' => ['difficulty' => 'expert'],
            'prompt' => ['prompt' => ' '],
        ] as $field => $bad) {
            $this->asStaff($manager)->postJson(self::URL, $this->body($bad))->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->asStaff($manager)->postJson(self::URL, $this->body(['options' => array_map(fn($i) => "خيار {$i}", range(1, 7))]))
            ->assertStatus(422)->assertJsonValidationErrors('options');
    }

    public function test_a_correction_is_a_new_version_and_the_old_one_stays(): void
    {
        $manager = $this->examManager();
        $id = $this->asStaff($manager)->postJson(self::URL, $this->body())->json('data.id');

        $this->asStaff($manager)->patchJson(self::URL . "/{$id}", ['correct_option' => 2])
            ->assertOk()
            ->assertJsonPath('data.current.version', 2)
            ->assertJsonPath('data.current.correct_option', 2)
            ->assertJsonPath('data.current.prompt', 'من راوي حديث «إنما الأعمال بالنيات»؟')
            ->assertJsonPath('data.versions.0.correct_option', 1)
            ->assertJsonCount(2, 'data.versions');

        // The same content again, or only the difficulty: no new version.
        $this->asStaff($manager)->patchJson(self::URL . "/{$id}", ['correct_option' => 2, 'difficulty' => 'hard'])
            ->assertOk()->assertJsonPath('data.current.version', 2)->assertJsonPath('data.difficulty', 'hard');
    }

    public function test_a_correction_keeps_the_answer_among_the_options_and_the_question_where_it_is(): void
    {
        $manager = $this->examManager();
        $id = $this->asStaff($manager)->postJson(self::URL, $this->body())->json('data.id');

        $this->asStaff($manager)->patchJson(self::URL . "/{$id}", ['options' => ['أ', 'ب']])   // the answer was option 1: still fine
            ->assertOk()->assertJsonPath('data.current.options', ['أ', 'ب']);
        $this->asStaff($manager)->patchJson(self::URL . "/{$id}", ['correct_option' => 2])
            ->assertStatus(422)->assertJsonPath('errors.correct_option.0', __('exams.correct_option_out_of_range'));
        $this->asStaff($manager)->patchJson(self::URL . "/{$id}", ['subject_id' => $this->subject('مادة')->id])
            ->assertStatus(422)->assertJsonValidationErrors('subject_id');
    }

    public function test_archived_questions_are_hidden_and_not_counted(): void
    {
        $manager = $this->examManager();
        $subject = $this->subject();
        $this->stockBank($subject, null, 2);
        $question = ExamQuestion::query()->where('difficulty', 'hard')->first();

        $this->asStaff($manager)->deleteJson(self::URL . "/{$question->id}")->assertOk()->assertJsonPath('data.archived', true);
        $this->asStaff($manager)->getJson(self::URL)->assertJsonCount(5, 'data');
        $this->asStaff($manager)->getJson(self::URL . '?include_archived=1')->assertJsonCount(6, 'data');
        $this->asStaff($manager)->getJson(self::URL . '/stats')->assertJsonFragment(['subject_id' => $subject->id, 'part_id' => null, 'difficulty' => 'hard', 'questions' => 1]);
        $this->asStaff($manager)->postJson(self::URL . "/{$question->id}/restore")->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_questions_are_filtered(): void
    {
        $manager = $this->examManager();
        $hadith = $this->subject();
        [$quran, $recitation] = $this->quran();
        $this->stockBank($hadith, null, 1);
        $this->stockBank($quran, $recitation, 1);
        $this->stockBank($quran, null, 1);
        $this->asStaff($manager)->postJson(self::URL, $this->body(['subject_id' => $hadith->id, 'prompt' => 'سؤال عن الصلاة']))->assertCreated();

        $this->asStaff($manager)->getJson(self::URL . "?subject_id={$quran->id}")->assertJsonCount(6, 'data');
        $this->asStaff($manager)->getJson(self::URL . "?subject_id={$quran->id}&part_id={$recitation->id}")->assertJsonCount(3, 'data');
        $this->asStaff($manager)->getJson(self::URL . "?subject_id={$quran->id}&part_id=none")->assertJsonCount(3, 'data');
        $this->asStaff($manager)->getJson(self::URL . '?difficulty=easy')->assertJsonCount(3, 'data');
        $this->asStaff($manager)->getJson(self::URL . '?q=' . urlencode('الصلاة'))->assertJsonCount(1, 'data');
        $this->asStaff($manager)->getJson(self::URL . '?q=' . urlencode('%'))->assertJsonCount(0, 'data');
    }

    public function test_only_exam_bank_managers_see_the_bank(): void
    {
        $this->stockBank($this->subject(), null, 1);
        $id = ExamQuestion::query()->value('id');

        foreach (['recruitment_staff', 'general_manager'] as $role) {
            $user = StaffUserFactory::new()->withRole($role)->create();
            $this->asStaff($user)->getJson(self::URL)->assertForbidden();
            $this->asStaff($user)->getJson(self::URL . "/{$id}")->assertForbidden();
            $this->asStaff($user)->postJson(self::URL, $this->body())->assertForbidden();
        }
    }
}
