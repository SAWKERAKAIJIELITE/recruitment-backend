<?php

namespace Tests\Feature\Contracts;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Models\ContractType;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Contracts: the first contract, renewals, corrections, the end without renewal (D-064). */
class ContractApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private StaffUser $staff;

    private ContractType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-10-31 10:00:00');
        $this->staff = $this->hiringStaff();   // recruitment_staff holds contracts.manage
        $this->type = ContractType::create(['name_ar' => 'عقد سنوي']);
    }

    /** An application whose interview passed (decided on 2026-10-01). */
    private function awaitingContract(string $number = '01234567890'): Application
    {
        $this->travelTo('2026-10-01 09:00:00');
        $application = $this->openApplication(null, ['candidate' => ['national_number' => $number]]);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingContract, D::Outcome);
        $this->travelTo('2026-10-31 10:00:00');

        return $application;
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'contract_type_id' => $this->type->id,
            'reference_number' => 'ع/2026/' . Str::random(4),
            'written_on' => '2026-10-20',
            'starts_on' => '2026-11-01',
            'ends_on' => '2027-10-31',
        ];
    }

    private function write(Application $application, array $overrides = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/staff/applications/{$application->id}/contracts", $this->body($overrides));
    }

    private function written(Application $application, array $overrides = []): string
    {
        return $this->write($application, $overrides)->assertCreated()->json('data.id');
    }

    private function renew(string $contract, array $body = []): TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/staff/contracts/{$contract}/renew", $body + ['contract_type_id' => $this->type->id, 'reference_number' => 'ت/' . Str::random(5), 'ends_on' => '2028-10-31']);
    }

    // ---- the first contract ----

    public function test_the_first_contract_moves_the_application_with_the_contract_as_cause(): void
    {
        $application = $this->awaitingContract();
        Log::spy();

        $this->write($application, ['reference_number' => 'ع/٢٠٢٦/15', 'notes' => 'نموذج الإدارة'])
            ->assertCreated()
            ->assertJsonPath('data.sequence', 1)
            ->assertJsonPath('data.reference_number', 'ع/2026/15')
            ->assertJsonPath('data.type.name_ar', 'عقد سنوي')
            ->assertJsonPath('data.state.value', 'upcoming')
            ->assertJsonPath('data.application.status', 'contract_written')
            ->assertJsonPath('data.created_by.id', $this->staff->id);

        $event = DB::table('application_status_events')->where('application_id', $application->id)->where('to_status', 'contract_written')->sole();
        $this->assertSame('outcome', $event->decision_type);
        $this->assertNotNull($event->contract_id);
        $this->assertStringStartsWith('2026-10-20 23:59:59', $event->occurred_at);                // dated the day it was written
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event) => $event === 'contract.written')->once();
    }

    public function test_written_today_by_default_and_starting_that_day(): void
    {
        $this->write($this->awaitingContract(), ['written_on' => null, 'starts_on' => null, 'ends_on' => null])
            ->assertCreated()
            ->assertJsonPath('data.written_on', '2026-10-31')
            ->assertJsonPath('data.starts_on', '2026-10-31')
            ->assertJsonPath('data.ends_on', null)
            ->assertJsonPath('data.state.value', 'active')
            ->assertJsonPath('data.days_left', null);
    }

    public function test_only_after_a_passed_interview_and_once(): void
    {
        $application = $this->awaitingContract();
        $early = $this->openApplication(null, ['candidate' => ['national_number' => '22222222222']]);

        $this->write($early)->assertStatus(422)->assertJsonPath('errors.application.0', __('contracts.not_awaiting_contract', ['status' => S::AwaitingExam->labelAr()]));
        $this->written($application);
        $this->write($application)->assertStatus(422)->assertJsonValidationErrors('application');
    }

    public function test_the_rules_of_a_contract(): void
    {
        $application = $this->awaitingContract();
        $other = $this->awaitingContract('22222222222');
        $this->written($other, ['reference_number' => 'ع/1']);

        $this->write($application, ['reference_number' => ' ع/1 '])->assertStatus(422)->assertJsonPath('errors.reference_number.0', __('contracts.reference_taken'));
        $this->write($application, ['ends_on' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors('ends_on');
        $this->write($application, ['written_on' => '2026-11-05'])->assertStatus(422)->assertJsonValidationErrors('written_on');            // in the future
        $this->write($application, ['written_on' => '2026-09-20'])->assertStatus(422)->assertJsonPath('errors.written_on.0', __('contracts.written_before_previous_decision'));
        $this->write($application, ['contract_type_id' => 999])->assertStatus(422)->assertJsonValidationErrors('contract_type_id');
        $this->write($application, ['document_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('errors.document_id.0', __('contracts.document_not_of_application'));
        $this->assertSame('awaiting_contract', $application->fresh()->current_status->value);   // nothing was kept
    }

    // ---- renewals ----

    public function test_a_renewal_continues_the_chain_from_the_end_of_the_previous_contract(): void
    {
        $application = $this->awaitingContract();
        $first = $this->written($application);

        $second = $this->renew($first)
            ->assertCreated()
            ->assertJsonPath('data.sequence', 2)
            ->assertJsonPath('data.renews_contract_id', $first)
            ->assertJsonPath('data.starts_on', '2027-11-01')                                      // the day after the end
            ->assertJsonPath('data.application.status', 'contract_written')
            ->json('data.id');

        $this->asStaff($this->staff)->getJson("/api/v1/staff/contracts/{$first}")->assertJsonPath('data.state.value', 'renewed')->assertJsonPath('data.renewed_by_contract_id', $second);
        $this->renew($first)->assertStatus(422)->assertJsonPath('errors.contract.0', __('contracts.not_latest'));
        $this->renew($second, ['starts_on' => '2028-10-01'])->assertStatus(422)->assertJsonValidationErrors('starts_on');
        $this->renew($second, ['ends_on' => '2029-10-31'])->assertCreated()->assertJsonPath('data.sequence', 3);

        $this->asStaff($this->staff)->getJson("/api/v1/staff/applications/{$application->id}/contracts")
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.sequence', 1)->assertJsonPath('data.2.sequence', 3);
        $this->assertSame(1, DB::table('application_status_events')->where('application_id', $application->id)->whereNotNull('contract_id')->count());   // renewals do not move the application
    }

    public function test_a_contract_without_end_date_is_not_renewed_and_a_late_renewal_is_allowed(): void
    {
        $open = $this->written($this->awaitingContract('11111111111'), ['ends_on' => null]);
        $this->renew($open)->assertStatus(422)->assertJsonPath('errors.contract.0', __('contracts.renew_without_end'));

        $expired = $this->written($this->awaitingContract('22222222222'), ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-25']);
        $this->asStaff($this->staff)->getJson("/api/v1/staff/contracts/{$expired}")->assertJsonPath('data.state.value', 'expired')->assertJsonPath('data.days_left', -6);
        $this->renew($expired)->assertCreated()->assertJsonPath('data.starts_on', '2026-10-26');
    }

    // ---- corrections ----

    public function test_correcting_a_contract_is_audited_and_keeps_the_chain(): void
    {
        $first = $this->written($this->awaitingContract());
        $second = $this->renew($first)->json('data.id');
        Log::spy();

        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$first}", ['reference_number' => 'ع/مصحح', 'reason' => 'خطأ في النسخ'])
            ->assertOk()->assertJsonPath('data.reference_number', 'ع/مصحح');
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event, array $context) => $event === 'contract.corrected'
            && $context['reason'] === 'خطأ في النسخ' && $context['changes']['reference_number']['to'] === 'ع/مصحح')->once();

        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$first}", ['ends_on' => '2027-12-31', 'reason' => 'سبب'])
            ->assertStatus(422)->assertJsonPath('errors.ends_on.0', __('contracts.ends_after_renewal', ['date' => '2027-11-01']));
        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$second}", ['starts_on' => '2027-10-01', 'reason' => 'سبب'])->assertStatus(422)->assertJsonValidationErrors('starts_on');
        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$first}", ['reference_number' => 'ع/مصحح', 'reason' => 'سبب'])->assertStatus(422)->assertJsonValidationErrors('reason');   // nothing changes
        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$first}", ['notes' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_a_new_end_date_makes_the_reminder_due_again(): void
    {
        $contract = $this->written($this->awaitingContract(), ['starts_on' => '2026-10-21', 'ends_on' => '2026-11-10']);
        Contract::query()->whereKey($contract)->update(['expiry_reminded_at' => now()]);

        $this->asStaff($this->staff)->patchJson("/api/v1/staff/contracts/{$contract}", ['ends_on' => '2026-11-12', 'reason' => 'تمديد مصحح'])
            ->assertOk()->assertJsonPath('data.expiry_reminded_at', null)->assertJsonPath('data.state.value', 'ending_soon');
    }

    // ---- the end ----

    public function test_ending_the_latest_contract_ends_the_application(): void
    {
        $application = $this->awaitingContract();
        $first = $this->written($application, ['starts_on' => '2026-10-21', 'ends_on' => '2026-10-30']);
        $url = "/api/v1/staff/contracts/{$first}/end";

        $this->asStaff($this->staff)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->asStaff($this->staff)->postJson($url, ['reason' => 'x x', 'ended_on' => '2026-11-02'])->assertStatus(422)->assertJsonValidationErrors('ended_on');   // in the future
        $this->asStaff($this->staff)->postJson($url, ['reason' => 'انتهت المدة ولم يُجدَّد', 'ended_on' => '2026-10-30'])
            ->assertOk()
            ->assertJsonPath('data.state.value', 'ended')
            ->assertJsonPath('data.ended.on', '2026-10-30')
            ->assertJsonPath('data.ended.by.id', $this->staff->id)
            ->assertJsonPath('data.application.status', 'contract_ended');

        $event = DB::table('application_status_events')->where('application_id', $application->id)->where('to_status', 'contract_ended')->sole();
        $this->assertSame($first, $event->contract_id);
        $this->assertSame('انتهت المدة ولم يُجدَّد', $event->reason);

        $this->renew($first)->assertStatus(422)->assertJsonValidationErrors('application');                                        // ended: no renewal
        $this->asStaff($this->staff)->postJson('/api/v1/staff/applications', $this->applicationPayload())->assertCreated();   // nominated again
    }

    public function test_only_the_latest_contract_ends(): void
    {
        $first = $this->written($this->awaitingContract());
        $this->renew($first)->assertCreated();

        $this->asStaff($this->staff)->postJson("/api/v1/staff/contracts/{$first}/end", ['reason' => 'سبب'])
            ->assertStatus(422)->assertJsonPath('errors.contract.0', __('contracts.not_latest'));
    }

    // ---- reading ----

    public function test_contracts_are_listed_by_state_and_found_by_reference_or_name(): void
    {
        $this->written($this->awaitingContract('11111111111'), ['reference_number' => 'R-1', 'starts_on' => '2026-10-21', 'ends_on' => '2026-11-10']);   // ending soon
        $this->written($this->awaitingContract('22222222222'), ['reference_number' => 'R-2', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-29']);   // expired
        $this->written($this->awaitingContract('33333333333'), ['reference_number' => 'R-3', 'starts_on' => '2026-10-21', 'ends_on' => '2027-06-30']);   // active
        $renewed = $this->written($this->awaitingContract('44444444444'), ['reference_number' => 'R-4', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-30']);
        $this->renew($renewed, ['reference_number' => 'R-5', 'starts_on' => '2026-11-05'])->assertCreated();                                               // R-4 renewed, R-5 upcoming
        $reader = StaffUserFactory::new()->withRole('general_manager')->create();
        $found = fn(string $query) => array_column($this->asStaff($reader)->getJson('/api/v1/staff/contracts?' . $query)->assertOk()->json('data'), 'reference_number');

        $this->assertSame(['R-2', 'R-4', 'R-1', 'R-3', 'R-5'], $found(''));                         // soonest end first
        $this->assertSame(['R-1'], $found('state=ending_soon'));
        $this->assertSame(['R-2'], $found('state=expired'));
        $this->assertSame(['R-3'], $found('state=active'));
        $this->assertSame(['R-4'], $found('state=renewed'));
        $this->assertSame(['R-5'], $found('state=upcoming'));
        $this->assertSame(['R-2', 'R-1', 'R-3', 'R-5'], $found('latest=1'));
        $this->assertSame(['R-3'], $found('q=R-3'));
        $this->assertSame(['R-1', 'R-3'], $found('ends_from=2026-11-01&ends_to=2027-12-31'));
        $this->asStaff($reader)->getJson('/api/v1/staff/contracts?state=soon')->assertStatus(422);
    }

    public function test_permissions(): void
    {
        $application = $this->awaitingContract();
        $reader = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($reader)->postJson("/api/v1/staff/applications/{$application->id}/contracts", $this->body())->assertForbidden();
        $this->asStaff($reader)->getJson("/api/v1/staff/applications/{$application->id}/contracts")->assertOk();
        $this->asStaff(StaffUserFactory::new()->create())->getJson('/api/v1/staff/contracts')->assertForbidden();
        $this->asStaff($this->staff)->getJson('/api/v1/staff/reference/contract-types')->assertOk()->assertJsonPath('data.0.name_ar', 'عقد سنوي');
    }

    // ---- database ----

    public function test_the_database_keeps_the_chain_and_the_end_consistent(): void
    {
        $application = $this->awaitingContract();
        $first = $this->written($application);
        $other = $this->written($this->awaitingContract('22222222222'));
        $refused = function (callable $write) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted the write.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $renewal = fn(array $values) => DB::table('contracts')->insert($values + [
            'id' => (string) Str::uuid7(), 'application_id' => $application->id, 'sequence' => 2, 'renews_contract_id' => $first,
            'contract_type_id' => $this->type->id, 'reference_number' => 'DB-' . Str::random(6), 'written_on' => '2026-10-31',
            'starts_on' => '2027-11-01', 'ends_on' => '2028-10-31', 'created_by' => $this->staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ended = ['ended_on' => '2026-10-31', 'end_reason' => 'سبب', 'ended_by' => $this->staff->id, 'ended_at' => now()];

        $refused(fn() => DB::table('contracts')->where('id', $first)->delete());
        $refused(fn() => DB::table('contracts')->where('id', $first)->update(['ends_on' => '2026-10-01']));                // before it starts
        $refused(fn() => DB::table('contracts')->where('id', $first)->update(['ended_on' => '2026-10-31']));               // no reason, no one
        $refused(fn() => DB::table('contracts')->where('id', $first)->update(['ended_on' => '2026-01-01'] + $ended));      // before it was written
        $refused(fn() => DB::table('contracts')->where('id', $first)->update(['reference_number' => ' ']));
        $refused(fn() => $renewal(['sequence' => 3]));                                                                     // skips a number
        $refused(fn() => $renewal(['starts_on' => '2027-10-01']));                                                          // before the end
        $refused(fn() => $renewal(['renews_contract_id' => $other]));                                                       // another application's
        $refused(fn() => $renewal(['renews_contract_id' => null]));                                                         // number 2 renews something
        $refused(fn() => DB::table('contracts')->where('id', $first)->update(['document_id' => (string) Str::uuid()]));    // not a document of it

        $renewal([]);
        $refused(fn() => $renewal(['reference_number' => 'DB-twice']));                                                    // renewed once
        $refused(fn() => DB::table('contracts')->where('id', $first)->update($ended));                                     // only the latest ends
        $refused(fn() => DB::table('contracts')->where('id', $first)->update(['ends_on' => '2027-12-31']));                // after its renewal starts
    }
}
