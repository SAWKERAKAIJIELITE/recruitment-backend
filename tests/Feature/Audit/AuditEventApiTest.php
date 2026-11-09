<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The audit log (D-078): written with each action, append-only, read by audit.view (all) or audit.view_related (operations). */
class AuditEventApiTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/audit-events';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-11-04 09:00:00');
    }

    public function test_actions_are_recorded_with_their_actor_and_details(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();
        $application = $this->openApplication($staff);
        $this->asStaff($staff)->patchJson("/api/v1/staff/applications/{$application->id}", ['phone_number' => '0933333333'])->assertOk();
        $this->asStaff($admin)->putJson("/api/v1/staff/users/{$staff->id}/role", ['role' => 'general_manager'])->assertOk();

        $event = DB::table('audit_events')->where('event', 'application.updated')->first();
        $this->assertSame($staff->id, $event->actor_id);
        $this->assertSame('operations', $event->category);
        $this->assertSame('127.0.0.1', $event->ip);
        $this->assertSame($application->id, json_decode($event->context, true)['application_id']);
        $this->assertSame('security', DB::table('audit_events')->where('event', 'access.role_changed')->value('category'));

        // A rolled-back action leaves no trace.
        try {
            DB::transaction(function () use ($staff) {
                AuditTrail::record('application.updated', $staff, ['application_id' => 'x']);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, DB::table('audit_events')->whereRaw("context->>'application_id' = 'x'")->count());
    }

    public function test_reading_the_log(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();   // audit.view
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();   // audit.view_related
        $application = $this->openApplication($staff);
        $this->asStaff($staff)->patchJson("/api/v1/staff/applications/{$application->id}", ['phone_number' => '0933333333'])->assertOk();
        $this->asStaff($admin)->postJson('/api/v1/staff/roles/documenting/permissions', ['permissions' => ['documents.view']])->assertOk();

        $all = $this->asStaff($admin)->getJson(self::URL)->assertOk()->json('data');
        $this->assertSame(['access.role_permissions_added', 'application.updated'], array_column($all, 'event'));   // newest first
        $this->assertSame($admin->id, $all[0]['actor']['id']);
        // Who acted, not their access (roles and permissions are read with access management).
        $this->assertSame(['id', 'personal_number', 'username', 'full_name', 'is_active'], array_keys($all[0]['actor']));
        $this->assertArrayNotHasKey('related_ids', $all[0]);

        // The related operations only: no access, device or login events, and only the user's own sections (D-086).
        $this->assertSame(['application.updated'], array_column($this->asStaff($staff)->getJson(self::URL)->assertOk()->json('data'), 'event'));
        $this->assertSame([], $this->asStaff($staff)->getJson(self::URL.'?category=security')->json('data'));
        AuditTrail::record('exam_session.created', null, ['session_id' => 'e1']);
        AuditTrail::record('examination.other', null, []);   // "exam_" is a beginning, not a pattern
        AuditTrail::record('contract.written', null, ['contract_id' => 'c1']);
        $this->assertSame(['application.updated'], array_column($this->asStaff($staff)->getJson(self::URL)->json('data'), 'event'));
        $this->assertSame(['exam_session.created'], array_column($this->asStaff($this->examsStaff())->getJson(self::URL)->json('data'), 'event'));
        $this->assertSame(['contract.written'], array_column($this->asStaff($this->contractsStaff())->getJson(self::URL)->json('data'), 'event'));
        $this->assertCount(5, $this->asStaff($this->staffOf('general_manager'))->getJson(self::URL)->json('data'));   // audit.view: everything

        // Filters.
        $this->assertCount(1, $this->asStaff($admin)->getJson(self::URL.'?event=access.')->json('data'));
        $this->assertCount(1, $this->asStaff($admin)->getJson(self::URL.'?event=application.updated')->json('data'));
        $this->assertSame(['application.updated'], array_column($this->asStaff($admin)->getJson(self::URL."?related_id={$application->id}")->json('data'), 'event'));
        $this->assertSame(['application.updated'], array_column($this->asStaff($admin)->getJson(self::URL."?actor_id={$staff->id}")->json('data'), 'event'));
        $this->assertCount(0, $this->asStaff($admin)->getJson(self::URL.'?from=2026-11-05')->json('data'));
        $this->asStaff($admin)->getJson(self::URL."?event=x';drop")->assertStatus(422);

        // Nobody else reads it.
        $this->asStaff(StaffUserFactory::new()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_the_related_id_search_uses_the_ids_of_the_event(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $candidate = $this->openApplication()->candidate_id;
        AuditTrail::record('exam_session.booked', null, ['session_id' => 7, 'booking_ids' => ['b1', 'b2'], 'reason' => 'b3']);
        AuditTrail::record('exam_attempt.started', null, ['attempt_id' => 'a1'], $candidate);
        AuditTrail::record('import.row_excluded', null, ['id' => '0', 'note' => '0']);

        $found = fn (string $id) => array_column($this->asStaff($admin)->getJson(self::URL.'?related_id='.$id)->assertOk()->json('data'), 'event');
        $this->assertSame(['exam_session.booked'], $found('7'));                     // a number, as text
        $this->assertSame(['exam_session.booked'], $found('b2'));                    // one of many
        $this->assertSame([], $found('b3'));                                         // not an id
        $this->assertSame(['exam_attempt.started'], $found($candidate));             // the acting candidate
        $this->assertSame(['import.row_excluded'], $found('0'));                     // "0" still filters
        $this->assertSame(['{7,b1,b2}'], DB::table('audit_events')->where('event', 'exam_session.booked')->pluck('related_ids')->all());
    }

    public function test_the_log_is_never_changed(): void
    {
        AuditTrail::record('application.updated', null, ['application_id' => 'a']);

        foreach (['update' => fn () => DB::table('audit_events')->update(['event' => 'x.y']), 'delete' => fn () => DB::table('audit_events')->delete()] as $case => $write) {
            try {
                DB::transaction($write);
                $this->fail("The database accepted an audit {$case}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }
}
