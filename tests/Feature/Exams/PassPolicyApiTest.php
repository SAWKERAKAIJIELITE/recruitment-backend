<?php

namespace Tests\Feature\Exams;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExamTestHelpers;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Adding versions of the pass rules (D-052, D-063). */
class PassPolicyApiTest extends TestCase
{
    use ExamTestHelpers, RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/exam-pass-policies';

    private const RULES = ['subject_pass_mark' => '65', 'tolerance_floor' => '62.5', 'max_tolerated_subjects' => 2, 'average_must_exceed' => '72', 'description' => 'قواعد العام الجديد'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_settled_rules_are_version_1_and_current(): void
    {
        $this->asStaff($this->examManager())->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.subject_pass_mark', '60.00')
            ->assertJsonPath('data.0.is_current', true);
    }

    public function test_a_new_version_is_used_by_plans_published_afterwards_only(): void
    {
        $before = $this->publishedPlan($this->position('مدرس'), $this->planContent([$this->subject('العقيدة')]));
        $author = $this->allow($this->examManager(), 'pass_policies.manage');

        $this->asStaff($author)->postJson(self::URL, self::RULES)
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.tolerance_floor', '62.50')
            ->assertJsonPath('data.max_tolerated_subjects', 2)
            ->assertJsonPath('data.is_current', true);

        $after = $this->publishedPlan($this->position('خطيب'), $this->planContent([$this->subject('الفقه')]));

        $this->assertSame(1, $before->fresh()->passPolicy->version);   // results already scored keep their rules
        $this->assertSame(2, $after->passPolicy->version);
        $this->asStaff($author)->getJson(self::URL)->assertJsonPath('data.0.version', 2)->assertJsonPath('data.1.is_current', false);
    }

    public function test_adding_needs_the_individual_permission_and_consistent_rules(): void
    {
        $this->asStaff($this->examManager())->postJson(self::URL, self::RULES)->assertForbidden();   // exam_bank.manage is not enough

        $author = $this->allow($this->examManager(), 'pass_policies.manage');
        $this->asStaff($author)->postJson(self::URL, ['tolerance_floor' => '70'] + self::RULES)->assertStatus(422)->assertJsonValidationErrors('tolerance_floor');
        $this->asStaff($author)->postJson(self::URL, ['average_must_exceed' => '100'] + self::RULES)->assertStatus(422)->assertJsonValidationErrors('average_must_exceed');
        $this->asStaff($author)->postJson(self::URL, ['subject_pass_mark' => '60.555'] + self::RULES)->assertStatus(422)->assertJsonValidationErrors('subject_pass_mark');
        $this->asStaff($author)->postJson(self::URL, array_diff_key(self::RULES, ['description' => 1]))->assertStatus(422)->assertJsonValidationErrors('description');
    }

    public function test_versions_are_never_changed(): void
    {
        $this->expectException(QueryException::class);
        DB::table('exam_pass_policies')->where('version', 1)->update(['subject_pass_mark' => 50]);
    }
}
