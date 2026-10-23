<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

class ApplicationApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const URL = '/api/v1/staff/applications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ---- opening an application ----

    public function test_hiring_staff_opens_an_application(): void
    {
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_exam')
            ->assertJsonPath('data.status_label', 'بانتظار الاختبار')
            ->assertJsonPath('data.job_position.name_ar', 'مدرس')
            ->assertJsonPath('data.residence.district.name_ar', 'دمشق')
            ->assertJsonPath('data.residence.area.name_ar', 'المزة')
            ->assertJsonPath('data.marital_status.name_ar', 'أعزب')
            ->assertJsonPath('data.availability.full_commitment', true)
            ->assertJsonPath('data.candidate.full_name', 'أحمد محمد علي')
            ->assertJsonPath('data.candidate.national_number', '01234567890')
            ->assertJsonPath('data.candidate.birth_place.name_ar', 'دمشق')
            ->assertJsonPath('data.history.0.decision_type', 'created')
            ->assertJsonPath('data.history.0.from_status', null)
            ->assertJsonPath('data.history.0.actor.id', $staff->id)
            ->assertJsonPath('candidate_matched', false);

        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('candidates', 1);
    }

    public function test_the_nomination_number_follows_the_configured_format(): void
    {
        $response = $this->asStaff($this->hiringStaff())->postJson(self::URL, $this->applicationPayload())->assertCreated();

        $this->assertMatchesRegularExpression('/^' . now()->format('Y') . '\d{6}$/', $response->json('data.nomination_number'));
    }

    public function test_numbers_increase_with_each_application(): void
    {
        $staff = $this->hiringStaff();
        $first = $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->json('data.nomination_number');
        $second = $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload([
            'candidate' => ['national_number' => '99999999999'],
        ]))->json('data.nomination_number');

        $this->assertSame((int) $first + 1, (int) $second);
    }

    public function test_the_national_number_is_stored_as_typed_text_with_leading_zeros(): void
    {
        $this->asStaff($this->hiringStaff())->postJson(self::URL, $this->applicationPayload())->assertCreated();

        $this->assertSame('01234567890', DB::table('candidates')->value('national_number'));
    }

    public function test_arabic_indic_digits_and_separators_are_normalised(): void
    {
        $this->asStaff($this->hiringStaff())->postJson(self::URL, $this->applicationPayload([
            'phone_number' => '٠٩١٢ ٣٤٥-٦٧٨',
            'candidate' => ['national_number' => '٠١٢٣٤-٥٦٧٨٩٠'],
        ]))->assertCreated();

        $this->assertSame('01234567890', Candidate::firstOrFail()->national_number);
        $this->assertSame('0912345678', Application::firstOrFail()->phone_number);
    }

    public function test_the_same_person_is_recognised_by_national_number_and_their_file_is_not_overwritten(): void
    {
        $staff = $this->hiringStaff();
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertCreated();

        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload([
            'job_position_id' => $this->jobPosition('إداري')->id,
            'phone_number' => '0999999999',
            'candidate' => ['full_name' => 'اسم مختلف تماما'],
        ]))
            ->assertCreated()
            ->assertJsonPath('candidate_matched', true)
            ->assertJsonPath('data.candidate.full_name', 'أحمد محمد علي')
            ->assertJsonPath('data.phone_number', '0999999999');   // application data is per application

        $this->assertDatabaseCount('candidates', 1);
        $this->assertDatabaseCount('applications', 2);
    }

    public function test_two_people_with_the_same_name_stay_two_people(): void
    {
        $staff = $this->hiringStaff();
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertCreated();
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload(['candidate' => ['national_number' => '11111111111']]))
            ->assertCreated()
            ->assertJsonPath('candidate_matched', false);

        $this->assertDatabaseCount('candidates', 2);
    }

    public function test_a_live_application_for_the_same_position_is_refused(): void
    {
        $staff = $this->hiringStaff();
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertCreated();

        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())
            ->assertStatus(422)
            ->assertJsonPath('errors.job_position_id.0', __('recruitment.live_application_exists'));

        $this->assertDatabaseCount('applications', 1);
    }

    public function test_after_a_final_outcome_the_person_can_apply_for_the_same_position_again(): void
    {
        $staff = $this->hiringStaff();
        $id = $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->json('data.id');

        $application = Application::findOrFail($id);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::InterviewFailed, D::Outcome);

        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertCreated();

        $this->assertDatabaseCount('applications', 2);
    }

    public function test_the_database_itself_refuses_two_live_applications_for_one_position(): void
    {
        $first = $this->openApplication();
        $copy = (array) DB::table('applications')->where('id', $first->id)->first();

        $this->expectException(QueryException::class);

        DB::table('applications')->insert(array_replace($copy, ['id' => (string) Str::uuid(), 'nomination_number' => 'MANUAL-1']));
    }

    public function test_a_failed_request_leaves_nothing_behind(): void
    {
        $staff = $this->hiringStaff();
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertCreated();
        $counter = DB::table('nomination_counters')->value('last_value');

        // Same person, same position: refused after the person was found, inside the transaction.
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertStatus(422);

        $this->assertSame($counter, DB::table('nomination_counters')->value('last_value'), 'no number was used up');
        $this->assertDatabaseCount('application_status_events', 1);
    }

    // ---- residence: the area must be inside the district ----

    public function test_an_area_of_another_district_is_refused(): void
    {
        $aleppo = $this->district('حلب');
        $foreignArea = $this->area($aleppo, 'الشهباء');

        $this->asStaff($this->hiringStaff())->postJson(self::URL, $this->applicationPayload(['residence_area_id' => $foreignArea->id]))
            ->assertStatus(422)
            ->assertJsonPath('errors.residence_area_id.0', __('recruitment.area_not_in_district'));
    }

    public function test_the_database_refuses_an_area_outside_the_district(): void
    {
        $application = $this->openApplication();
        $aleppo = $this->district('حلب');

        $this->expectException(QueryException::class);

        DB::table('applications')->where('id', $application->id)->update(['residence_district_id' => $aleppo->id]);
    }

    // ---- who may read and create ----

    public function test_the_general_manager_can_read_files_but_not_create_them(): void
    {
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($manager)->getJson(self::URL)->assertOk();
        $this->asStaff($manager)->postJson(self::URL, $this->applicationPayload())->assertForbidden();
    }

    public function test_staff_without_either_permission_cannot_read_files(): void
    {
        $nobody = StaffUserFactory::new()->create();

        $this->asStaff($nobody)->getJson(self::URL)->assertForbidden();
    }

    public function test_applications_need_a_signed_in_staff_user(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, $this->applicationPayload())->assertUnauthorized();
    }

    // ---- validation ----

    public static function invalidChanges(): array
    {
        return [
            'name with digits' => [['candidate' => ['full_name' => 'احمد 123']], 'candidate.full_name'],
            'name with arabic-indic digits' => [['candidate' => ['full_name' => 'احمد ٣']], 'candidate.full_name'],
            'name too short' => [['candidate' => ['full_name' => 'ab']], 'candidate.full_name'],
            'mother name missing' => [['candidate' => ['mother_name' => null]], 'candidate.mother_name'],
            'national number too short' => [['candidate' => ['national_number' => '1234567890']], 'candidate.national_number'],
            'national number too long' => [['candidate' => ['national_number' => '123456789012']], 'candidate.national_number'],
            'national number missing' => [['candidate' => ['national_number' => null]], 'candidate.national_number'],
            'birth date in the future' => [['candidate' => ['birth_date' => '2999-01-01']], 'candidate.birth_date'],
            'birth date not a date' => [['candidate' => ['birth_date' => '01/03/1998']], 'candidate.birth_date'],
            'unknown birth place' => [['candidate' => ['birth_place_id' => 999999]], 'candidate.birth_place_id'],
            'phone with letters' => [['phone_number' => '09ab45678'], 'phone_number'],
            'phone missing' => [['phone_number' => null], 'phone_number'],
            'quran parts above 30' => [['quran_parts_memorized' => 31], 'quran_parts_memorized'],
            'study years above 15' => [['studying_years' => 16], 'studying_years'],
            'negative study years' => [['studying_years' => -1], 'studying_years'],
            'availability missing' => [['full_commitment' => null], 'full_commitment'],
            'unknown job position' => [['job_position_id' => 999999], 'job_position_id'],
            'job position not a number' => [['job_position_id' => 'nope'], 'job_position_id'],
            'health condition missing' => [['health_condition_id' => null], 'health_condition_id'],
        ];
    }

    #[DataProvider('invalidChanges')]
    public function test_invalid_requests_are_refused(array $change, string $field): void
    {
        $this->asStaff($this->hiringStaff())->postJson(self::URL, $this->applicationPayload($change))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    public function test_an_archived_list_entry_cannot_be_used_for_a_new_application(): void
    {
        $position = $this->jobPosition('قديم');
        $position->delete();

        $this->asStaff($this->hiringStaff())->postJson(self::URL, $this->applicationPayload(['job_position_id' => $position->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('job_position_id');
    }

    public function test_a_file_still_shows_an_entry_archived_later(): void
    {
        $staff = $this->hiringStaff();
        $application = $this->openApplication($staff);
        JobPosition::findOrFail($application->job_position_id)->delete();

        $this->asStaff($staff)->getJson(self::URL . "/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.job_position.name_ar', 'مدرس')
            ->assertJsonPath('data.job_position.archived', true);
    }

    public static function valuesTheDatabaseMustRefuse(): array
    {
        return [
            'quran parts above 30' => ['applications', ['quran_parts_memorized' => 31]],
            'study years above 15' => ['applications', ['studying_years' => 16]],
            'phone with letters' => ['applications', ['phone_number' => '09ab45678']],
            'national number with 10 digits' => ['candidates', ['national_number' => '1234567890']],
            'national number with letters' => ['candidates', ['national_number' => '1234567890a']],
            'name with digits' => ['candidates', ['full_name' => 'احمد 1']],
            'name with arabic-indic digits' => ['candidates', ['full_name' => 'احمد ١']],
        ];
    }

    /** Even code that skips validation (raw SQL, a future import bug) cannot store these. */
    #[DataProvider('valuesTheDatabaseMustRefuse')]
    public function test_the_database_refuses_impossible_values(string $table, array $values): void
    {
        $application = $this->openApplication();
        $id = $table === 'applications' ? $application->id : $application->candidate_id;

        $this->expectException(QueryException::class);

        DB::table($table)->where('id', $id)->update($values);
    }

    // ---- search and detail ----

    public function test_search_by_name_ignores_arabic_spelling_variants(): void
    {
        $staff = $this->hiringStaff();
        $this->openApplication($staff, ['candidate' => ['full_name' => 'أحمد علي', 'national_number' => '11111111111']]);
        $this->openApplication($staff, ['candidate' => ['full_name' => 'احمد حسن', 'national_number' => '22222222222']]);
        $this->openApplication($staff, ['candidate' => ['full_name' => 'سارة خالد', 'national_number' => '33333333333']]);

        $this->asStaff($staff)->getJson(self::URL . '?q=' . urlencode('احمد'))->assertOk()->assertJsonCount(2, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?q=' . urlencode('أحمد'))->assertJsonCount(2, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?q=' . urlencode('ساره'))->assertJsonCount(1, 'data');
    }

    public function test_search_by_nomination_number_is_exact(): void
    {
        $staff = $this->hiringStaff();
        $application = $this->openApplication($staff);

        $this->asStaff($staff)->getJson(self::URL . '?q=' . $application->nomination_number)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $application->id);
        $this->asStaff($staff)->getJson(self::URL . '?q=' . substr($application->nomination_number, 0, 6))
            ->assertJsonCount(0, 'data');
    }

    public function test_search_by_national_number_in_any_digit_style(): void
    {
        $staff = $this->hiringStaff();
        $this->openApplication($staff);

        $this->asStaff($staff)->getJson(self::URL . '?national_number=' . urlencode('٠١٢٣٤٥٦٧٨٩٠'))->assertJsonCount(1, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?national_number=00000000000')->assertJsonCount(0, 'data');
    }

    public function test_filters(): void
    {
        $staff = $this->hiringStaff();
        $a = $this->openApplication($staff);
        $otherPosition = $this->jobPosition('إداري');
        $b = $this->openApplication($staff, ['job_position_id' => $otherPosition->id, 'candidate' => ['national_number' => '44444444444']]);
        app(ApplicationLifecycle::class)->move($b, S::ExamFailed, D::Outcome);

        $this->asStaff($staff)->getJson(self::URL . '?status=exam_failed')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->asStaff($staff)->getJson(self::URL . '?status=awaiting_exam')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->asStaff($staff)->getJson(self::URL . '?job_position_id=' . $otherPosition->id)->assertJsonCount(1, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?nominating_entity_id=' . $a->nominating_entity_id)->assertJsonCount(2, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?residence_district_id=' . $a->residence_district_id)->assertJsonCount(2, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?status=nonsense')->assertStatus(422);
    }

    public function test_date_filters_include_the_whole_last_day(): void
    {
        $staff = $this->hiringStaff();
        $this->travelTo('2026-03-10 23:30:00');
        $this->openApplication($staff);
        $this->travelBack();

        $this->asStaff($staff)->getJson(self::URL . '?from=2026-03-10&to=2026-03-10')->assertJsonCount(1, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?from=2026-03-11')->assertJsonCount(0, 'data');
        $this->asStaff($staff)->getJson(self::URL . '?to=2026-03-09')->assertJsonCount(0, 'data');
    }

    public function test_the_list_is_paginated(): void
    {
        $staff = $this->hiringStaff();
        $this->openApplication($staff);

        $this->asStaff($staff)->getJson(self::URL)
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'nomination_number', 'status', 'status_label', 'candidate_name', 'national_number', 'job_position', 'nominating_entity']], 'links', 'meta']);
    }

    public function test_one_application_shows_the_whole_history_in_order(): void
    {
        $staff = $this->hiringStaff();
        $application = $this->openApplication($staff);
        app(ApplicationLifecycle::class)->move($application, S::ExamAbsent, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingExam, D::ExcuseAccepted, $staff, 'Medical certificate');

        $response = $this->asStaff($staff)->getJson(self::URL . "/{$application->id}")->assertOk();

        $this->assertSame(['awaiting_exam', 'exam_absent', 'awaiting_exam'], collect($response->json('data.history'))->pluck('to_status')->all());
        $this->assertSame([null, 'awaiting_exam', 'exam_absent'], collect($response->json('data.history'))->pluck('from_status')->all());
        $response->assertJsonPath('data.history.2.reason', 'Medical certificate')
            ->assertJsonPath('data.history.2.decision_type', 'excuse_accepted');
    }

    public function test_unknown_application_is_404(): void
    {
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->getJson(self::URL . '/' . Str::uuid())->assertNotFound();
        $this->asStaff($staff)->getJson(self::URL . '/not-a-uuid')->assertNotFound();
    }
}
