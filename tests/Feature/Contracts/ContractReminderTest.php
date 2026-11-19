<?php

namespace Tests\Feature\Contracts;

use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Models\ContractType;
use App\Modules\Contracts\Notifications\ContractEndingSoon;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Reminders two weeks before a contract ends, and the staff notifications API (D-065). */
class ContractReminderTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private StaffUser $writer;

    private int $number = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-10-31 06:00:00');
        $this->writer = $this->contractsStaff();
        $this->referenceIds();
        $this->openVacancies();
    }

    /** A first contract written by $by (default: the writer), ending on $endsOn. */
    private function contract(?string $endsOn, ?StaffUser $by = null): Contract
    {
        $this->number++;
        $this->travelTo('2026-10-01 09:00:00');
        $application = $this->openApplication(null, ['candidate' => ['national_number' => sprintf('%011d', $this->number)]]);
        app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingContract, D::Outcome);
        $this->travelTo('2026-10-31 06:00:00');

        return app(ManageContracts::class)->write($application, [
            'contract_type_id' => (ContractType::query()->first() ?? ContractType::create(['name_ar' => 'عقد سنوي']))->id,
            'reference_number' => "R-{$this->number}",
            'written_on' => '2026-10-15',
            'starts_on' => '2026-10-16',
            'ends_on' => $endsOn,
        ], $by ?? $this->writer);
    }

    private function remind(): int
    {
        $this->artisan('contracts:remind-expiring')->assertSuccessful();

        return $this->reminders()->count();
    }

    /** Leaves the reminders only: the moves of the applications are notified too (D-089). */
    private function forgetMoves(): void
    {
        DB::table('notifications')->where('type', '<>', ContractEndingSoon::class)->delete();
    }

    /** The reminders only (the moves of the applications are notified too, D-089). */
    private function reminders(): Builder
    {
        return DB::table('notifications')->where('type', ContractEndingSoon::class);
    }

    /** @return list<string> who got a reminder */
    private function recipients(): array
    {
        return $this->reminders()->orderBy('notifiable_id')->pluck('notifiable_id')->unique()->values()->all();
    }

    public function test_a_contract_ending_within_two_weeks_reminds_its_writer_and_every_contracts_manager_once(): void
    {
        $other = $this->contractsStaff();                                                                   // contracts.manage through the role
        $admin = $this->staffOf('system_admin');                                                            // holds every permission
        $inactive = StaffUserFactory::new()->withRole('contracts')->create(['is_active' => false]);
        $recruitment = $this->hiringStaff();                                                                // another office
        $writerWithoutPermission = $this->contractsStaff();
        $reader = StaffUserFactory::new()->withRole('general_manager')->create();                          // reads, does not manage
        $contract = $this->contract('2026-11-14', $writerWithoutPermission);
        $writerWithoutPermission->forceFill(['role_id' => Role::where('slug', 'general_manager')->value('id')])->save();   // moved to another role since

        $this->remind();

        foreach ([$this->writer, $other, $admin, $writerWithoutPermission] as $user) {
            $this->assertContains($user->id, $this->recipients());
        }

        foreach ([$inactive, $recruitment, $reader] as $user) {
            $this->assertNotContains($user->id, $this->recipients());
        }

        $this->assertSame(1, $this->reminders()->where('notifiable_id', $this->writer->id)->count());   // one each, even when both writer and manager
        $this->assertNotNull($contract->fresh()->expiry_reminded_at);

        $count = $this->reminders()->count();
        $this->travel(1)->days();
        $this->assertSame($count, $this->remind());                                                        // once
    }

    public function test_only_contracts_that_end_soon_and_are_still_the_latest(): void
    {
        $this->contract('2026-11-15');                                                                       // 15 days: not yet
        $this->contract(null);                                                                               // no end date
        $expired = $this->contract('2026-10-30');                                                            // passed: shown as expired, no reminder
        $renewed = $this->contract('2026-11-05');
        app(ManageContracts::class)->renew($renewed, ['contract_type_id' => $renewed->contract_type_id, 'reference_number' => 'R-renewal', 'written_on' => '2026-10-31', 'starts_on' => '2026-11-06', 'ends_on' => '2027-11-05'], $this->writer);

        $this->assertSame(0, $this->remind());
        $this->assertNull($expired->fresh()->expiry_reminded_at);

        $this->travelTo('2026-11-01 06:00:00');                                                             // the first one is now 14 days away
        $this->assertGreaterThan(0, $this->remind());
        $this->assertSame(['R-1'], Contract::query()->whereNotNull('expiry_reminded_at')->pluck('reference_number')->all());
    }

    public function test_the_notification_says_which_contract_and_when(): void
    {
        $contract = $this->contract('2026-11-10');
        $this->forgetMoves();
        $this->remind();

        $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'contract_ending_soon')
            ->assertJsonPath('data.0.title', 'عقد ينتهي قريباً')
            ->assertJsonPath('data.0.details.contract_id', $contract->id)
            ->assertJsonPath('data.0.details.ends_on', '2026-11-10')
            ->assertJsonPath('data.0.details.days_left', 10)
            ->assertJsonPath('data.0.read_at', null);
        $this->assertStringContainsString('2026-11-10', $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications')->json('data.0.message'));
    }

    public function test_each_user_reads_and_marks_only_their_own_notifications(): void
    {
        $this->contract('2026-11-10');
        $this->contract('2026-11-12');
        $other = $this->contractsStaff();
        $this->forgetMoves();
        $this->remind();
        $mine = $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications')->json('data.0.id');
        $theirs = $this->asStaff($other)->getJson('/api/v1/staff/notifications')->json('data.0.id');

        $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications/unread-count')->assertOk()->assertJsonPath('data.unread', 2);
        $this->asStaff($this->writer)->postJson("/api/v1/staff/notifications/{$theirs}/read")->assertNotFound();
        $this->asStaff($this->writer)->postJson("/api/v1/staff/notifications/{$mine}/read")->assertOk()->assertJsonPath('data.id', $mine);
        $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications/unread-count')->assertJsonPath('data.unread', 1);
        $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications?unread=1')->assertJsonCount(1, 'data');

        $this->asStaff($this->writer)->postJson('/api/v1/staff/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 1);
        $this->asStaff($this->writer)->getJson('/api/v1/staff/notifications/unread-count')->assertJsonPath('data.unread', 0);
        $this->asStaff($other)->getJson('/api/v1/staff/notifications/unread-count')->assertJsonPath('data.unread', 2);   // untouched
    }

    public function test_notifications_need_a_signed_in_staff_user(): void
    {
        $this->getJson('/api/v1/staff/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/staff/notifications/unread-count')->assertUnauthorized();
    }

    public function test_the_reminder_runs_every_day(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn($event) => str_contains((string) $event->command, 'contracts:remind-expiring'));

        $this->assertCount(1, $events);
        $this->assertSame('0 6 * * *', $events->first()->expression);
    }
}
