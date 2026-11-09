<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** What each office sees of an application (D-086), and how received applications start (D-087). */
class OfficesTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/applications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function person(string $number, array $overrides = [], bool $start = false): Application
    {
        return $this->openApplication(null, array_replace_recursive(['candidate' => ['national_number' => $number]], $overrides), $start);
    }

    // ---- what the other offices see ----

    public function test_the_other_offices_see_who_the_candidate_is_and_where_the_application_stands(): void
    {
        $application = $this->person('01234567890', start: true);
        $identity = ['id', 'nomination_number', 'status', 'status_label', 'job_position', 'candidate', 'created_at'];

        foreach (['exams_interviews', 'contracts', 'documenting'] as $office) {
            $user = $this->staffOf($office);

            $row = $this->asStaff($user)->getJson(self::URL)->assertOk()->json('data.0');
            $this->assertSame($identity, array_keys($row), $office);
            $this->assertSame(['id', 'full_name', 'national_number'], array_keys($row['candidate']));

            $one = $this->asStaff($user)->getJson(self::URL."/{$application->id}")->assertOk()->json('data');
            $this->assertSame($identity, array_keys($one));
            $this->assertArrayNotHasKey('phone_number', $one);

            $this->asStaff($user)->getJson('/api/v1/staff/candidates')->assertForbidden();
            $this->asStaff($user)->getJson("/api/v1/staff/candidates/{$application->candidate_id}")->assertForbidden();
        }

        // Recruitment and the general manager read the full file.
        foreach (['recruitment', 'general_manager'] as $role) {
            $this->asStaff($this->staffOf($role))->getJson(self::URL."/{$application->id}")->assertOk()->assertJsonPath('data.phone_number', '0912345678')->assertJsonStructure(['data' => ['history', 'residence', 'health']]);
        }
    }

    public function test_the_other_offices_filter_by_identity_only(): void
    {
        $this->person('01234567890', start: true);
        $exams = $this->examsStaff();

        $this->asStaff($exams)->getJson(self::URL.'?national_number=01234567890&status=awaiting_exam&sort=full_name')->assertOk()->assertJsonCount(1, 'data');
        $this->asStaff($exams)->getJson(self::URL.'?phone_number=0912345678')->assertStatus(422)->assertJsonPath('errors.phone_number.0', __('recruitment.identity_only_filter'));
        $this->asStaff($exams)->getJson(self::URL.'?residence_district_id='.$this->referenceIds()['residence_district_id'])->assertStatus(422);
        $this->asStaff($exams)->getJson(self::URL.'?sort=birth_date')->assertStatus(422);
        $this->asStaff($this->hiringStaff())->getJson(self::URL.'?phone_number=0912345678')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_queues_show_identity_only(): void
    {
        $application = $this->person('01234567890', start: true);
        $exams = $this->examsStaff();

        $entry = $this->asStaff($exams)->getJson('/api/v1/staff/exams/queue')->assertOk()->json('data.0');
        $this->assertSame($application->id, $entry['id']);
        $this->assertArrayNotHasKey('phone_number', $entry);
        $this->assertArrayNotHasKey('residence', $entry);

        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        $entry = $this->asStaff($exams)->getJson('/api/v1/staff/interviews/queue')->assertOk()->json('data.0');
        $this->assertSame(['position', 'waiting_since', 'id', 'nomination_number', 'status', 'status_label', 'job_position', 'candidate', 'created_at'], array_keys($entry));

        // Recruitment does not see the exams section's queues.
        $this->asStaff($this->hiringStaff())->getJson('/api/v1/staff/exams/queue')->assertForbidden();
        $this->asStaff($this->hiringStaff())->getJson('/api/v1/staff/interviews/queue')->assertForbidden();
    }

    // ---- starting received applications ----

    public function test_an_application_opens_received_or_started_at_once(): void
    {
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload())->assertCreated()->assertJsonPath('data.status', 'received');
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload(['candidate' => ['national_number' => '22222222222'], 'start' => true]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_exam')
            ->assertJsonPath('data.history.1.decision_type', 'started')
            ->assertJsonPath('data.history.1.actor.id', $staff->id);

        // A role that opens applications but does not start them (none does by default).
        DB::table('permission_role')->where('role_id', $staff->role_id)->whereIn('permission_id', DB::table('permissions')->where('slug', 'applications.start')->select('id'))->delete();
        $staff->forgetPermissionCache();
        $this->asStaff($staff)->postJson(self::URL, $this->applicationPayload(['candidate' => ['national_number' => '33333333333'], 'start' => true]))->assertForbidden();
        $this->assertSame(0, DB::table('candidates')->where('national_number', '33333333333')->count());
    }

    public function test_starting_one_application(): void
    {
        $application = $this->person('01234567890');
        $staff = $this->hiringStaff();

        $this->asStaff($this->examsStaff())->postJson(self::URL."/{$application->id}/start")->assertForbidden();
        $this->asStaff($staff)->postJson(self::URL."/{$application->id}/start")->assertOk()->assertJsonPath('data.status', 'awaiting_exam');
        $this->asStaff($staff)->postJson(self::URL."/{$application->id}/start")->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_starting_many_by_ids_is_all_or_nothing(): void
    {
        $first = $this->person('01111111111');
        $second = $this->person('02222222222');
        $started = $this->person('03333333333', start: true);
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->postJson(self::URL.'/start', ['application_ids' => [$first->id, $started->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.application_ids.0', __('recruitment.not_received', ['numbers' => $started->nomination_number]));
        $this->assertSame(S::Received, $first->fresh()->current_status);

        $this->asStaff($staff)->postJson(self::URL.'/start', ['application_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('data.started', 2);
        $this->assertSame([S::AwaitingExam, S::AwaitingExam], [$first->fresh()->current_status, $second->fresh()->current_status]);

        $this->asStaff($staff)->postJson(self::URL.'/start', ['application_ids' => [(string) Str::uuid()]])->assertStatus(422);
        $this->asStaff($staff)->postJson(self::URL.'/start', [])->assertStatus(422)->assertJsonValidationErrors(['application_ids', 'filters']);
        $this->asStaff($staff)->postJson(self::URL.'/start', ['application_ids' => [$first->id], 'filters' => ['q' => 'x']])->assertStatus(422)->assertJsonValidationErrors('filters');
    }

    public function test_starting_the_received_applications_the_filters_match(): void
    {
        $teacher = $this->person('01111111111');
        $driver = $this->person('02222222222', ['job_position_id' => $this->jobPosition('سائق')->id]);
        $closed = $this->person('03333333333');
        app(ApplicationLifecycle::class)->move($closed, S::Closed, D::Closure, $this->hiringStaff(), 'انسحب');
        $staff = $this->hiringStaff();

        $this->asStaff($staff)->postJson(self::URL.'/start', ['filters' => ['job_position_id' => $teacher->job_position_id]])
            ->assertOk()
            ->assertJsonPath('data.started', 1)
            ->assertJsonPath('data.application_ids', [$teacher->id]);
        $this->assertSame(S::Received, $driver->fresh()->current_status);

        $this->asStaff($staff)->postJson(self::URL.'/start', ['filters' => ['job_position_id' => $teacher->job_position_id]])
            ->assertStatus(422)->assertJsonPath('errors.filters.0', __('recruitment.start_nothing'));   // nothing received left there
        $this->asStaff($staff)->postJson(self::URL.'/start', ['filters' => ['status' => 'awaiting_exam']])->assertStatus(422)->assertJsonValidationErrors('filters.status');
        $this->asStaff($staff)->postJson(self::URL.'/start', ['filters' => ['age_max' => 300]])->assertStatus(422)->assertJsonValidationErrors('filters.age_max');
    }
}
