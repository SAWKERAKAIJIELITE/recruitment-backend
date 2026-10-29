<?php

namespace Tests\Feature\Exams;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ExamTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Subjects and parts (analysis doc §05). */
class ExamSubjectApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, ExamTestHelpers;

    private const URL = '/api/v1/staff/exam-subjects';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_subject_is_added_with_its_weighted_parts(): void
    {
        Log::spy();

        $this->asStaff($this->examManager())->postJson(self::URL, [
            'name_ar' => 'القرآن الكريم',
            'parts' => [['name_ar' => 'التلاوة', 'default_weight' => 60], ['name_ar' => 'التجويد', 'default_weight' => '40.5', 'default_assessment' => 'manual']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name_ar', 'القرآن الكريم')
            ->assertJsonPath('data.parts.0.name_ar', 'التلاوة')
            ->assertJsonPath('data.parts.0.default_weight', '60.00')
            ->assertJsonPath('data.parts.0.default_assessment', 'exam')
            ->assertJsonPath('data.parts.1.default_weight', '40.50')
            ->assertJsonPath('data.parts.1.default_assessment', 'manual');

        Log::shouldHaveReceived('notice')->withArgs(fn(string $event) => $event === 'exam_subject.created')->once();
    }

    public function test_names_are_unique_in_any_spelling_and_parts_within_their_subject(): void
    {
        $manager = $this->examManager();
        $this->subject('الحديث النبوي');
        [$quran] = $this->quran();

        $this->asStaff($manager)->postJson(self::URL, ['name_ar' => 'الحديث النبوى'])->assertStatus(422)->assertJsonPath('errors.name_ar.0', __('recruitment.name_taken'));
        $this->asStaff($manager)->postJson(self::URL, ['name_ar' => 'مادة', 'parts' => [['name_ar' => 'جزء', 'default_weight' => 1], ['name_ar' => 'جزء', 'default_weight' => 2]]])
            ->assertStatus(422)->assertJsonValidationErrors('parts.1.name_ar');
        $this->asStaff($manager)->postJson(self::URL . "/{$quran->id}/parts", ['name_ar' => 'التلاوه', 'default_weight' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('name_ar');

        // The same part name in another subject is fine.
        $this->asStaff($manager)->postJson(self::URL, ['name_ar' => 'مادة أخرى', 'parts' => [['name_ar' => 'التلاوة', 'default_weight' => 1]]])->assertCreated();
    }

    public function test_an_archived_name_is_restored_not_added_again(): void
    {
        $manager = $this->examManager();
        $subject = $this->subject('السيرة النبوية');

        $this->asStaff($manager)->deleteJson(self::URL . "/{$subject->id}")->assertOk()->assertJsonPath('data.archived', true);
        $this->asStaff($manager)->getJson(self::URL)->assertJsonCount(0, 'data');
        $this->asStaff($manager)->getJson(self::URL . '?include_archived=true')->assertJsonCount(1, 'data');
        $this->asStaff($manager)->postJson(self::URL, ['name_ar' => 'السيرة النبوية'])->assertStatus(422)->assertJsonPath('errors.name_ar.0', __('recruitment.name_archived'));
        $this->asStaff($manager)->postJson(self::URL . "/{$subject->id}/restore")->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_parts_are_renamed_reweighted_archived_and_restored(): void
    {
        $manager = $this->examManager();
        [, $recitation] = $this->quran();
        $url = "/api/v1/staff/exam-subject-parts/{$recitation->id}";

        $this->asStaff($manager)->patchJson($url, ['default_weight' => 65])->assertOk()->assertJsonPath('data.default_weight', '65.00')->assertJsonPath('data.name_ar', 'التلاوة');
        $this->asStaff($manager)->patchJson($url, ['name_ar' => 'التلاوة والحفظ'])->assertOk()->assertJsonPath('data.default_weight', '65.00');
        $this->asStaff($manager)->patchJson($url, ['default_assessment' => 'manual'])->assertOk()->assertJsonPath('data.default_assessment', 'manual');
        $this->asStaff($manager)->patchJson($url, ['default_assessment' => 'oral'])->assertStatus(422)->assertJsonValidationErrors('default_assessment');
        $this->asStaff($manager)->deleteJson($url)->assertOk()->assertJsonPath('data.archived', true);
        $this->asStaff($manager)->getJson(self::URL)->assertJsonCount(1, 'data.0.parts');
        $this->asStaff($manager)->postJson("{$url}/restore")->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_weights_are_positive_with_two_decimals_at_most(): void
    {
        $manager = $this->examManager();
        $subject = $this->subject();

        foreach ([0, -5, '1.234', 'abc', 100000] as $weight) {
            $this->asStaff($manager)->postJson(self::URL . "/{$subject->id}/parts", ['name_ar' => 'جزء', 'default_weight' => $weight])
                ->assertStatus(422)->assertJsonValidationErrors('default_weight');
        }
    }

    public function test_renaming_does_not_take_parts(): void
    {
        $subject = $this->subject();

        $this->asStaff($this->examManager())->putJson(self::URL . "/{$subject->id}", ['name_ar' => 'الحديث الشريف', 'parts' => [['name_ar' => 'جزء', 'default_weight' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('parts');
        $this->asStaff($this->examManager())->putJson(self::URL . "/{$subject->id}", ['name_ar' => 'الحديث الشريف'])
            ->assertOk()->assertJsonPath('data.name_ar', 'الحديث الشريف');
    }

    public function test_hiring_staff_and_the_general_manager_read_only_exam_bank_managers_change(): void
    {
        $subject = $this->subject();

        foreach (['recruitment_staff', 'general_manager'] as $role) {
            $user = StaffUserFactory::new()->withRole($role)->create();
            $this->asStaff($user)->getJson(self::URL)->assertOk();
            $this->asStaff($user)->postJson(self::URL, ['name_ar' => 'مادة'])->assertForbidden();
            $this->asStaff($user)->deleteJson(self::URL . "/{$subject->id}")->assertForbidden();
        }

        $this->asStaff(StaffUserFactory::new()->create())->getJson(self::URL)->assertForbidden();
    }
}
