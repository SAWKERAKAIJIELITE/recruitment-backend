<?php

namespace Tests\Feature\Notifications;

use App\Modules\Contracts\Models\ContractType;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
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

/** The counts beside the sidebar's entries (D-099). */
class BadgesTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/badges';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-11-11 08:00:00');
        $this->referenceIds();
        $this->openVacancies();
    }

    private function at(string $number, S ...$moves): Application
    {
        $application = $this->openApplication(null, ['candidate' => ['national_number' => $number]]);

        foreach ($moves as $status) {
            app(ApplicationLifecycle::class)->move($application, $status, D::Outcome);
        }

        return $application;
    }

    public function test_each_office_sees_its_sections_counts(): void
    {
        $this->at('01111111111', S::AwaitingInterview);                                          // to book
        $booked = $this->at('02222222222', S::AwaitingInterview);
        $later = $this->at('03333333333', S::AwaitingInterview);
        $this->at('04444444444', S::AwaitingInterview, S::AwaitingContract);                     // awaiting its contract
        $written = $this->at('05555555555', S::AwaitingInterview, S::AwaitingContract);
        $exams = $this->examsStaff();
        $sitting = $this->asStaff($exams)->postJson('/api/v1/staff/interview-sittings', [
            'starts_at' => '2026-11-11T07:00:00Z', 'ends_at' => '2026-11-11T15:00:00Z', 'location' => 'القاعة', 'committee_name' => 'لجنة',
            'members' => [['name' => 'محمد السالم', 'capacity' => 'chair']],
        ])->assertCreated()->json('data.id');
        $this->asStaff($exams)->postJson("/api/v1/staff/applications/{$booked->id}/interviews", ['sitting_id' => $sitting, 'scheduled_at' => '2026-11-11T07:30:00Z'])->assertCreated();   // its time has come
        $this->asStaff($exams)->postJson("/api/v1/staff/applications/{$later->id}/interviews", ['sitting_id' => $sitting, 'scheduled_at' => '2026-11-11T12:00:00Z'])->assertCreated();   // not yet
        $contracts = $this->contractsStaff();
        $this->asStaff($contracts)->postJson("/api/v1/staff/applications/{$written->id}/contracts", [
            'contract_type_id' => ContractType::create(['name_ar' => 'عقد سنوي'])->id, 'reference_number' => 'ع/' . Str::random(4), 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-20',
        ])->assertCreated();                                                                     // ends within 14 days

        $this->asStaff($exams)->getJson(self::URL)->assertOk()->assertExactJson(['data' => [
            'notifications' => ['unread' => DB::table('notifications')->where('notifiable_id', $exams->id)->whereNull('read_at')->count()],
            'interviews' => ['to_book' => 1, 'to_record' => 1, 'total' => 2],
            'contracts' => null,
        ]]);
        $this->asStaff($contracts)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.interviews', null)
            ->assertJsonPath('data.contracts', ['awaiting_contract' => 1, 'ending_soon' => 1, 'expired' => 0]);

        // The general manager sees both; Recruitment neither; the bell for everyone.
        $manager = $this->asStaff(StaffUserFactory::new()->withRole('general_manager')->create())->getJson(self::URL)->assertOk();
        $this->assertSame(2, $manager->json('data.interviews.total'));
        $this->assertSame(1, $manager->json('data.contracts.awaiting_contract'));
        $this->asStaff($this->hiringStaff())->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.interviews', null)->assertJsonPath('data.contracts', null)->assertJsonStructure(['data' => ['notifications' => ['unread']]]);
    }
}
