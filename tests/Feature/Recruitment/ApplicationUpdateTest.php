<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Correcting what was declared in a live application (D-049). */
class ApplicationUpdateTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private function url(Application $application): string
    {
        return "/api/v1/staff/applications/{$application->id}";
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_only_the_fields_sent_change_and_the_change_is_audited(): void
    {
        $application = $this->openApplication();
        Log::spy();

        $this->asStaff($this->hiringStaff())->patchJson($this->url($application), ['phone_number' => '٠٩٩٩ ٨٨٨ ٧٧٧', 'overnight_work' => false])
            ->assertOk()
            ->assertJsonPath('data.phone_number', '0999888777')
            ->assertJsonPath('data.availability.overnight_work', false)
            ->assertJsonPath('data.availability.full_commitment', true)
            ->assertJsonPath('data.job_position.name_ar', 'مدرس');

        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'application.updated'
            && $context['changes']['phone_number'] === ['from' => '0912345678', 'to' => '0999888777']
            && count($context['changes']) === 2)->once();
    }

    public function test_the_person_the_number_and_the_status_are_not_editable_here(): void
    {
        $application = $this->openApplication();

        $this->asStaff($this->hiringStaff())->patchJson($this->url($application), [
            'nomination_number' => 'X',
            'current_status' => 'contract_written',
            'candidate' => ['full_name' => 'اسم آخر'],
            'notes' => 'ملاحظة',
        ])->assertOk();

        $fresh = $application->fresh('candidate');
        $this->assertSame($application->nomination_number, $fresh->nomination_number);
        $this->assertSame('awaiting_exam', $fresh->current_status->value);
        $this->assertSame('أحمد محمد علي', $fresh->candidate->full_name);
        $this->assertSame('ملاحظة', $fresh->notes);
    }

    public function test_an_ended_application_is_not_edited(): void
    {
        $application = $this->openApplication();
        $this->closeApplication($application);

        $this->asStaff($this->hiringStaff())->patchJson($this->url($application), ['notes' => 'متأخر'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', __('recruitment.application_ended', ['status' => 'أُغلق الطلب']));
    }

    public function test_a_new_district_needs_an_area_of_that_district_or_none(): void
    {
        $staff = $this->hiringStaff();
        $application = $this->openApplication();   // area المزة in دمشق
        $aleppo = $this->district('حلب');
        $shahba = $this->area($aleppo, 'الشهباء');

        $this->asStaff($staff)->patchJson($this->url($application), ['residence_district_id' => $aleppo->id])
            ->assertStatus(422)->assertJsonValidationErrors('residence_area_id');

        $this->asStaff($staff)->patchJson($this->url($application), ['residence_district_id' => $aleppo->id, 'residence_area_id' => $shahba->id])
            ->assertOk()->assertJsonPath('data.residence.area.name_ar', 'الشهباء');

        $this->asStaff($staff)->patchJson($this->url($application), ['residence_district_id' => $this->referenceIds()['residence_district_id'], 'residence_area_id' => null])
            ->assertOk()->assertJsonPath('data.residence.area', null);
    }

    public function test_an_area_alone_must_belong_to_the_current_district(): void
    {
        $application = $this->openApplication();
        $foreign = $this->area($this->district('حلب'), 'الشهباء');

        $this->asStaff($this->hiringStaff())->patchJson($this->url($application), ['residence_area_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('residence_area_id');
    }

    public function test_discontinued_studies_need_years_counting_what_is_stored(): void
    {
        $staff = $this->hiringStaff();
        $application = $this->openApplication(null, ['studying_years' => null]);

        $this->asStaff($staff)->patchJson($this->url($application), ['studies_discontinued' => true])
            ->assertStatus(422)->assertJsonValidationErrors('studying_years');

        $this->asStaff($staff)->patchJson($this->url($application), ['studies_discontinued' => true, 'studying_years' => 2])
            ->assertOk()->assertJsonPath('data.education.studies_discontinued', true);

        $this->asStaff($staff)->patchJson($this->url($application), ['studies_discontinued' => null])
            ->assertOk()->assertJsonPath('data.education.studies_discontinued', false);
    }

    public function test_fields_follow_the_same_rules_as_when_opening(): void
    {
        $application = $this->openApplication();
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->patchJson($this->url($application), ['quran_parts_memorized' => 31])->assertStatus(422)->assertJsonValidationErrors('quran_parts_memorized');
        $this->asStaff($staff)->patchJson($this->url($application), ['job_position_id' => null])->assertStatus(422)->assertJsonValidationErrors('job_position_id');
        $this->asStaff($staff)->patchJson($this->url($application), ['phone_number' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }

    public function test_correcting_needs_candidates_manage(): void
    {
        $application = $this->openApplication();

        $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())
            ->patchJson($this->url($application), ['notes' => 'x'])->assertForbidden();
    }
}
