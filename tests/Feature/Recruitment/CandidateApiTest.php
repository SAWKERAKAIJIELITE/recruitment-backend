<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** People: search, file, corrections, deactivation (D-049). */
class CandidateApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const URL = '/api/v1/staff/candidates';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function person(string $nationalNumber = '01234567890', string $name = 'أحمد محمد علي'): Candidate
    {
        return $this->openApplication(null, ['candidate' => ['national_number' => $nationalNumber, 'full_name' => $name]])->candidate;
    }

    // ---- reading ----

    public function test_people_are_listed_with_their_live_application(): void
    {
        $staff = $this->hiringStaff();
        $person = $this->person();

        $this->asStaff($staff)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.id', $person->id)
            ->assertJsonPath('data.0.applications_count', 1)
            ->assertJsonPath('data.0.live_application.status', 'awaiting_exam')
            ->assertJsonPath('data.0.birth_place.name_ar', 'دمشق')
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_search_by_name_in_any_spelling_or_by_national_number_in_any_digits(): void
    {
        $staff = $this->hiringStaff();
        $this->person('11111111111', 'أحمد علي');
        $this->person('22222222222', 'سارة خالد');

        $this->asStaff($staff)->getJson(self::URL . '?q=' . urlencode('احمد'))->assertJsonCount(1, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?q=' . urlencode('٢٢٢٢٢٢٢٢٢٢٢'))->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'سارة خالد');
    }

    public function test_filters_by_activity_and_live_application(): void
    {
        $staff = $this->hiringStaff();
        $live = $this->person('11111111111');
        $ended = $this->person('22222222222');
        $this->closeApplication($ended->applications()->sole());

        $this->asStaff($staff)->getJson(self::URL . '?has_live_application=true')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live->id);
        $this->asStaff($staff)->getJson(self::URL . '?has_live_application=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ended->id);
        $this->asStaff($staff)->getJson(self::URL . '?is_active=false')->assertJsonCount(0, 'data');
    }

    public function test_one_person_shows_all_applications_newest_first(): void
    {
        $staff = $this->hiringStaff();
        $person = $this->person();
        $first = $person->applications()->sole();
        $this->closeApplication($first);
        $this->travel(1)->minutes();
        $second = $this->openApplication(null, ['job_position_id' => $this->jobPosition('إداري')->id]);

        $this->asStaff($staff)->getJson(self::URL . "/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.national_number', '01234567890')
            ->assertJsonPath('data.applications.0.id', $second->id)
            ->assertJsonPath('data.applications.1.id', $first->id)
            ->assertJsonPath('data.applications.1.status', 'closed');
    }

    public function test_reading_needs_reports_or_candidates_permission(): void
    {
        $person = $this->person();

        $this->asStaff(StaffUserFactory::new()->create())->getJson(self::URL)->assertForbidden();
        $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())->getJson(self::URL . "/{$person->id}")->assertOk();
    }

    // ---- corrections ----

    public function test_correcting_a_person_is_audited_and_searchable(): void
    {
        $staff = $this->hiringStaff();
        $person = $this->person();
        Log::spy();

        $this->asStaff($staff)->patchJson(self::URL . "/{$person->id}", ['full_name' => 'أحمد محمود علي', 'birth_date' => '1997-05-02'])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'أحمد محمود علي')
            ->assertJsonPath('data.birth_date', '1997-05-02');

        $this->asStaff($staff)->getJson(self::URL . '?q=' . urlencode('محمود'))->assertJsonCount(1, 'data');
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'candidate.updated'
            && $context['changes']['full_name'] === ['from' => 'أحمد محمد علي', 'to' => 'أحمد محمود علي']
            && $context['changes']['birth_date'] === ['from' => '1998-03-01', 'to' => '1997-05-02']
            && ! isset($context['changes']['name_search_key']))->once();
    }

    public function test_a_mistyped_national_number_can_be_corrected_but_not_to_someone_elses(): void
    {
        $staff = $this->hiringStaff();
        $person = $this->person('01234567890');
        $this->person('22222222222', 'سارة خالد');

        $this->asStaff($staff)->patchJson(self::URL . "/{$person->id}", ['national_number' => '22222222222'])
            ->assertStatus(422)->assertJsonPath('errors.national_number.0', __('recruitment.national_number_taken'));

        $this->asStaff($staff)->patchJson(self::URL . "/{$person->id}", ['national_number' => '٠١٢٣٤٥٦٧٨٩٩'])
            ->assertOk()->assertJsonPath('data.national_number', '01234567899');
    }

    public function test_corrections_follow_the_same_rules_as_creation(): void
    {
        $person = $this->person();
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->patchJson(self::URL . "/{$person->id}", ['full_name' => 'احمد 2'])->assertStatus(422)->assertJsonValidationErrors('full_name');
        $this->asStaff($staff)->patchJson(self::URL . "/{$person->id}", ['national_number' => '123'])->assertStatus(422)->assertJsonValidationErrors('national_number');
        $this->asStaff($staff)->patchJson(self::URL . "/{$person->id}", ['full_name' => null])->assertStatus(422)->assertJsonValidationErrors('full_name');
    }

    public function test_correcting_needs_candidates_manage(): void
    {
        $person = $this->person();

        $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())
            ->patchJson(self::URL . "/{$person->id}", ['notes' => 'x'])->assertForbidden();
    }

    // ---- deactivation ----

    public function test_a_person_with_a_live_application_cannot_be_deactivated(): void
    {
        $person = $this->person();
        $live = $person->applications()->sole();

        $this->asStaff($this->hiringStaff())->postJson(self::URL . "/{$person->id}/deactivate", ['reason' => 'متوفى'])
            ->assertStatus(422)
            ->assertJsonPath('errors.candidate.0', __('recruitment.candidate_has_live_application', ['number' => $live->nomination_number]));
    }

    public function test_a_deactivated_person_cannot_be_nominated_until_reactivated(): void
    {
        $staff = $this->hiringStaff();
        $person = $this->person();
        $this->closeApplication($person->applications()->sole());

        $this->asStaff($staff)->postJson(self::URL . "/{$person->id}/deactivate", ['reason' => 'طلب الجهة'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.deactivation.reason', 'طلب الجهة')
            ->assertJsonPath('data.deactivation.by.id', $staff->id);

        $response = $this->asStaff($staff)->postJson('/api/v1/staff/applications', $this->applicationPayload())->assertStatus(422);
        $this->assertSame(__('recruitment.candidate_inactive'), $response->json('errors')['candidate.national_number'][0]);

        $this->asStaff($staff)->postJson(self::URL . "/{$person->id}/activate")->assertOk()->assertJsonPath('data.deactivation', null);
        $this->asStaff($staff)->postJson('/api/v1/staff/applications', $this->applicationPayload())->assertCreated();
    }

    public function test_deactivation_needs_a_reason_and_happens_once(): void
    {
        $staff = $this->hiringStaff();
        $person = $this->person();
        $this->closeApplication($person->applications()->sole());

        $this->asStaff($staff)->postJson(self::URL . "/{$person->id}/deactivate", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->asStaff($staff)->postJson(self::URL . "/{$person->id}/activate")->assertStatus(422);
        $this->asStaff($staff)->postJson(self::URL . "/{$person->id}/deactivate", ['reason' => 'سبب'])->assertOk();
        $this->asStaff($staff)->postJson(self::URL . "/{$person->id}/deactivate", ['reason' => 'سبب'])->assertStatus(422);
    }

    public function test_the_database_requires_a_reason_for_an_inactive_person(): void
    {
        $person = $this->person();

        $this->expectException(QueryException::class);

        DB::table('candidates')->where('id', $person->id)->update(['is_active' => false, 'deactivated_at' => now()]);
    }
}
