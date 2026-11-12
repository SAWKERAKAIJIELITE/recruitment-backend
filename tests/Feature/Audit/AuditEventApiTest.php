<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Support\AuditEvents;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    public function test_every_event_has_an_arabic_label(): void
    {
        // Every event the code records (literal names, and both names of a ternary), and AccessLog's under access.
        $recorded = [];

        foreach (File::allFiles(app_path()) as $file) {
            $code = $file->getContents();
            preg_match_all("/AuditTrail::record\(\s*([^,]+),/", $code, $calls);

            foreach ($calls[1] as $argument) {
                preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $argument, $names);
                array_push($recorded, ...$names[1]);
            }

            preg_match_all("/AccessLog::record\(\s*'([a-z_]+)'/", $code, $access);
            array_push($recorded, ...array_map(fn(string $event) => "access.{$event}", $access[1]));
        }

        $recorded = array_values(array_unique($recorded));
        $this->assertGreaterThan(80, count($recorded));
        $this->assertSame([], array_values(array_diff($recorded, array_keys(AuditEvents::LABELS))), 'events without a label');
        $this->assertSame([], array_values(array_diff(array_keys(AuditEvents::LABELS), $recorded)), 'labels of events never recorded');
    }

    public function test_the_log_shows_labels_pages_and_its_events(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        foreach (range(1, 12) as $index) {
            AuditTrail::record('interview.recorded', $admin, ['interview_id' => "i-{$index}"]);
        }
        AuditTrail::record('auth.staff_login', $admin, ['user_id' => $admin->id]);

        $this->asStaff($admin)->getJson(self::URL . '?per_page=10')->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 13)
            ->assertJsonPath('data.0.event', 'auth.staff_login')->assertJsonPath('data.0.event_label', 'تسجيل دخول موظف')
            ->assertJsonPath('data.1.event_label', 'تسجيل نتيجة مقابلة');
        $this->asStaff($admin)->getJson(self::URL . '?per_page=5')->assertStatus(422)->assertJsonValidationErrors('per_page');

        // The catalogue for the event filter: everything with audit.view, one's own sections' operations otherwise.
        $all = collect($this->asStaff($admin)->getJson(self::URL . '/events')->assertOk()->json('data'));
        $this->assertCount(count(AuditEvents::LABELS), $all);
        $this->assertSame(['event' => 'device.approved', 'label' => 'اعتماد جهاز', 'category' => 'security'], $all->firstWhere('event', 'device.approved'));
        $mine = collect($this->asStaff(StaffUserFactory::new()->withRole('interviews')->create())->getJson(self::URL . '/events')->assertOk()->json('data'))->pluck('event');
        $this->assertTrue($mine->every(fn(string $event) => str_starts_with($event, 'interview')));
        $this->assertContains('interview_sitting.created', $mine);
        $this->asStaff($this->hiringStaff())->getJson(self::URL . '/events')->assertOk()->assertJsonMissing(['event' => 'exam_session.created']);
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
