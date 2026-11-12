<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers;

    private const BASE = '/api/v1/staff';

    private StaffUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = StaffUserFactory::new()->withRole('system_admin')->create();
    }

    private function rolePermissions(string $slug): array
    {
        return Role::where('slug', $slug)->firstOrFail()->permissions()->pluck('slug')->sort()->values()->all();
    }

    // ---- who may manage access ----

    public function test_only_holders_of_users_manage_can_use_access_management(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();

        $this->asStaff($staff)->getJson(self::BASE.'/roles')->assertForbidden();
        $this->asStaff($staff)->getJson(self::BASE.'/permissions')->assertForbidden();
        $this->asStaff($this->admin)->getJson(self::BASE.'/roles')->assertOk();
    }

    public function test_permission_catalogue_marks_read_only_permissions(): void
    {
        $response = $this->asStaff($this->admin)->getJson(self::BASE.'/permissions')->assertOk();

        $bySlug = collect($response->json('data'))->keyBy('slug');

        $this->assertTrue($bySlug['reports.view']['read_only']);
        $this->assertTrue($bySlug['exams.view']['read_only']);
        $this->assertFalse($bySlug['exceptions.approve']['read_only']);
        $this->assertArrayNotHasKey('individual_only', $bySlug['reports.view']);
    }

    // ---- the offices (D-085) ----

    public function test_each_office_controls_its_own_section_only(): void
    {
        $sections = [
            'recruitment' => ['candidates.manage', 'applications.start', 'applications.close', 'imports.manage'],
            'exams_interviews' => ['exams.manage', 'exam_bank.manage', 'interviews.manage', 'exceptions.approve', 'interviews.excuse', 'attempts.extend', 'interviews.correct', 'exam_results.correct', 'pass_policies.manage'],
            'contracts' => ['contracts.manage'],
            'documenting' => ['documents.manage'],
        ];

        foreach ($sections as $office => $own) {
            $user = StaffUserFactory::new()->withRole($office)->create();
            $others = collect($sections)->except($office)->flatten()->all();

            foreach ($own as $permission) {
                $this->assertTrue($user->hasPermission($permission), "{$office} holds {$permission}");
            }

            foreach ([...$others, 'users.manage', 'vacancies.manage', 'reports.view', 'audit.view'] as $permission) {
                $this->assertFalse($user->hasPermission($permission), "{$office} does not hold {$permission}");
            }
        }

        // Recruitment sees the full file; the other offices do not.
        $this->assertTrue(StaffUserFactory::new()->withRole('recruitment')->create()->hasPermission('applications.view'));
        $this->assertFalse(StaffUserFactory::new()->withRole('exams_interviews')->create()->hasPermission('applications.view'));
    }

    public function test_the_exams_and_the_interviews_each_have_a_role_in_their_office(): void
    {
        $exams = ['exams.view', 'exam_bank.manage', 'exams.manage', 'attempts.extend', 'exam_results.correct', 'pass_policies.manage', 'exceptions.approve'];
        $interviews = ['interviews.view', 'interviews.manage', 'interviews.correct', 'interviews.excuse'];
        $examOfficer = StaffUserFactory::new()->withRole('exams')->create();
        $interviewOfficer = StaffUserFactory::new()->withRole('interviews')->create();

        $this->assertEqualsCanonicalizing([...$exams, 'audit.view_related'], $examOfficer->permissionSlugs()->all());
        $this->assertEqualsCanonicalizing([...$interviews, 'audit.view_related'], $interviewOfficer->permissionSlugs()->all());
        $this->assertEqualsCanonicalizing([...$exams, ...$interviews, 'audit.view_related'], StaffUserFactory::new()->withRole('exams_interviews')->create()->permissionSlugs()->all());

        // The routes follow: each reaches its own section only.
        $this->asStaff($examOfficer)->getJson('/api/v1/staff/exam-sessions')->assertOk();
        $this->asStaff($examOfficer)->getJson('/api/v1/staff/interviews')->assertForbidden();
        $this->asStaff($interviewOfficer)->getJson('/api/v1/staff/interviews')->assertOk();
        $this->asStaff($interviewOfficer)->getJson('/api/v1/staff/exam-sessions')->assertForbidden();

        // Both are of the Exams and Interviews office, as offices.
        $roles = collect($this->asStaff($this->admin)->getJson(self::BASE.'/roles')->assertOk()->json('data'))->keyBy('slug');
        $this->assertSame(['موظف الاختبارات', true], [$roles['exams']['label_ar'], $roles['exams']['office']]);
        $this->assertSame(['موظف المقابلات', true], [$roles['interviews']['label_ar'], $roles['interviews']['office']]);
        $this->asStaff($this->admin)->putJson(self::BASE."/users/{$examOfficer->id}/role", ['role' => 'interviews'])->assertOk()->assertJsonPath('user.role.slug', 'interviews');
    }

    public function test_the_general_manager_only_views_and_the_administrator_holds_everything(): void
    {
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();
        $readOnly = Permission::query()->where('read_only', true)->pluck('slug');

        $this->assertSame([], $manager->permissionSlugs()->diff($readOnly)->values()->all());
        foreach (['applications.view', 'exams.view', 'interviews.view', 'contracts.view', 'documents.view', 'imports.view', 'reports.view', 'audit.view'] as $permission) {
            $this->assertTrue($manager->hasPermission($permission), $permission);
        }
        $this->assertFalse($manager->hasPermission('exam_bank.manage'));   // the question bank holds the answers

        $this->assertSame(Permission::query()->orderBy('slug')->pluck('slug')->all(), $this->admin->permissionSlugs()->all());

        // A permission added later is the administrator's at once.
        Permission::query()->create(['slug' => 'later.added', 'label_ar' => 'لاحقًا', 'read_only' => false]);
        $this->assertTrue($this->admin->fresh()->hasPermission('later.added'));

        $roles = collect($this->asStaff($this->admin)->getJson(self::BASE.'/roles')->assertOk()->json('data'))->keyBy('slug');
        $this->assertTrue($roles['system_admin']['administrator']);
        $this->assertCount(Permission::query()->count(), $roles['system_admin']['permissions']);
        $this->assertTrue($roles['contracts']['office']);
        $this->assertFalse($roles['general_manager']['office']);
    }

    // ---- roles ----

    public function test_adding_a_permission_to_a_role_affects_its_members(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();
        $this->assertFalse($staff->can('devices.approve'));

        $this->asStaff($this->admin)
            ->postJson(self::BASE.'/roles/recruitment/permissions', ['permissions' => ['devices.approve']])
            ->assertOk()
            ->assertJsonPath('role.slug', 'recruitment');

        $this->assertContains('devices.approve', $this->rolePermissions('recruitment'));
        $this->assertTrue($staff->fresh()->can('devices.approve'));
    }

    public function test_the_general_manager_gets_read_only_permissions_only(): void
    {
        $this->asStaff($this->admin)
            ->postJson(self::BASE.'/roles/general_manager/permissions', ['permissions' => ['exams.view', 'contracts.manage']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
        $this->assertNotContains('contracts.manage', $this->rolePermissions('general_manager'));

        $this->asStaff($this->admin)->deleteJson(self::BASE.'/roles/general_manager/permissions/audit.view')->assertOk();
        $this->asStaff($this->admin)->postJson(self::BASE.'/roles/general_manager/permissions', ['permissions' => ['audit.view']])->assertOk();
    }

    public function test_the_administrators_role_is_not_edited(): void
    {
        $this->asStaff($this->admin)
            ->postJson(self::BASE.'/roles/system_admin/permissions', ['permissions' => ['reports.view']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
        $this->assertSame([], $this->rolePermissions('system_admin'));   // none stored: it holds all
    }

    public function test_unknown_permission_slug_is_rejected(): void
    {
        $this->asStaff($this->admin)
            ->postJson(self::BASE.'/roles/recruitment/permissions', ['permissions' => ['does.not.exist']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_removing_a_permission_from_a_role(): void
    {
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();
        $this->assertTrue($manager->can('reports.view'));

        $this->asStaff($this->admin)
            ->deleteJson(self::BASE.'/roles/general_manager/permissions/reports.view')
            ->assertOk();

        $this->assertFalse($manager->fresh()->can('reports.view'));
    }

    public function test_removing_a_permission_the_role_does_not_have_is_404(): void
    {
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE.'/roles/general_manager/permissions/users.manage')
            ->assertNotFound();
    }

    public function test_the_last_way_to_manage_users_cannot_be_removed_from_a_role(): void
    {
        $this->asStaff($this->admin)->postJson(self::BASE.'/roles/contracts/permissions', ['permissions' => ['users.manage']])->assertOk();
        $this->asStaff($this->admin)->putJson(self::BASE."/users/{$this->admin->id}/role", ['role' => 'contracts'])->assertOk();

        // The one user left able to manage users holds it through the Contracts role now.
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE.'/roles/contracts/permissions/users.manage')
            ->assertStatus(422)
            ->assertJsonValidationErrors('user')
            ->assertJsonPath('errors.user.0', __('auth.permission'));

        // The failed change was rolled back.
        $this->assertContains('users.manage', $this->rolePermissions('contracts'));
    }

    public function test_reseeding_does_not_overwrite_changes_made_through_the_api(): void
    {
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE.'/roles/general_manager/permissions/reports.view')
            ->assertOk();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertNotContains('reports.view', $this->rolePermissions('general_manager'));
    }

    // ---- one role per staff user ----

    public function test_changing_a_users_role_replaces_it(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();

        $response = $this->asStaff($this->admin)
            ->putJson(self::BASE."/users/{$staff->id}/role", ['role' => 'contracts'])
            ->assertOk()
            ->assertJsonPath('user.role.slug', 'contracts')
            ->assertJsonPath('user.role.office', true);

        $this->assertContains('contracts.manage', $response->json('user.effective_permissions'));
        $this->assertNotContains('candidates.manage', $response->json('user.effective_permissions'));
        $this->assertFalse($staff->fresh()->can('candidates.manage'));
        $this->assertSame($this->admin->id, $staff->fresh()->role_assigned_by);
        $context = json_decode(DB::table('audit_events')->where('event', 'access.role_changed')->value('context'), true);
        $this->assertSame(['recruitment', 'contracts', $staff->id], [$context['from'], $context['to'], $context['user_id']]);

        $this->asStaff($this->admin)->putJson(self::BASE."/users/{$staff->id}/role", ['role' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->asStaff($staff)->putJson(self::BASE."/users/{$staff->id}/role", ['role' => 'system_admin'])->assertForbidden();
    }

    public function test_personal_grants_are_gone(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();

        $this->asStaff($this->admin)->postJson(self::BASE."/users/{$staff->id}/permissions", ['permission' => 'reports.view', 'effect' => 'grant'])->assertNotFound();
        $this->asStaff($this->admin)->postJson(self::BASE."/users/{$staff->id}/roles", ['role' => 'contracts'])->assertNotFound();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('permission_staff_user'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('role_staff_user'));
    }

    public function test_the_last_administrator_cannot_lose_the_admin_role(): void
    {
        $this->asStaff($this->admin)
            ->putJson(self::BASE."/users/{$this->admin->id}/role", ['role' => 'general_manager'])
            ->assertStatus(422);

        $this->assertSame('system_admin', $this->admin->fresh()->role->slug);

        // With a second administrator, one may become something else.
        StaffUserFactory::new()->withRole('system_admin')->create();
        $this->asStaff($this->admin)->putJson(self::BASE."/users/{$this->admin->id}/role", ['role' => 'general_manager'])->assertOk();
        $this->assertFalse($this->admin->fresh()->can('users.manage'));
    }

    // ---- a forgotten password (D-071) ----

    public function test_an_administrator_sets_a_new_password_and_ends_every_session(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();
        $staff->createToken('one');
        $staff->createToken('two');
        $url = self::BASE."/users/{$staff->id}/password";

        $this->asStaff($this->admin)->putJson($url, ['password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->asStaff($this->admin)->putJson($url, ['password' => 'N3w-Passw0rd!', 'password_confirmation' => 'other'])->assertStatus(422);
        $this->asStaff($staff)->putJson($url, ['password' => 'N3w-Passw0rd!', 'password_confirmation' => 'N3w-Passw0rd!'])->assertForbidden();

        $this->asStaff($this->admin)->putJson($url, ['password' => 'N3w-Passw0rd!', 'password_confirmation' => 'N3w-Passw0rd!'])
            ->assertOk()->assertJsonPath('user.id', $staff->id);

        $this->assertTrue(Hash::check('N3w-Passw0rd!', $staff->fresh()->password));
        $this->assertSame(0, $staff->tokens()->count());
    }

    // ---- deactivating staff users (D-079) ----

    public function test_a_deactivated_staff_user_is_signed_out_and_can_be_reactivated(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();
        $staff->createToken('one');
        $url = self::BASE."/users/{$staff->id}";

        $this->asStaff($staff)->postJson("{$url}/deactivate", ['reason' => 'x x x'])->assertForbidden();
        $this->asStaff($this->admin)->postJson("{$url}/deactivate", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->asStaff($this->admin)->postJson("{$url}/deactivate", ['reason' => 'انتهاء التكليف'])
            ->assertOk()
            ->assertJsonPath('user.is_active', false)
            ->assertJsonPath('user.deactivation.reason', 'انتهاء التكليف')
            ->assertJsonPath('user.deactivation.by', ['id' => $this->admin->id, 'username' => $this->admin->username, 'full_name' => $this->admin->full_name])
            ->assertJsonPath('user.role.slug', 'recruitment');   // the role stays
        $this->assertSame(0, $staff->tokens()->count());
        $this->asStaff($this->admin)->postJson("{$url}/deactivate", ['reason' => 'مرة ثانية'])->assertStatus(422);

        $this->asStaff($this->admin)->postJson("{$url}/activate")->assertOk()->assertJsonPath('user.is_active', true)->assertJsonPath('user.deactivation', null);
        $this->asStaff($this->admin)->postJson("{$url}/activate")->assertStatus(422);
        $this->assertSame(['access.user_deactivated', 'access.user_activated'], DB::table('audit_events')->where('event', 'like', 'access.user_%')->orderBy('id')->pluck('event')->all());
    }

    public function test_nobody_deactivates_themselves(): void
    {
        $this->asStaff($this->admin)->postJson(self::BASE."/users/{$this->admin->id}/deactivate", ['reason' => 'تجربة'])
            ->assertStatus(422)->assertJsonPath('errors.user.0', __('auth.cannot_deactivate_self'));

        $other = StaffUserFactory::new()->withRole('system_admin')->create();
        $this->asStaff($other)->postJson(self::BASE."/users/{$this->admin->id}/deactivate", ['reason' => 'تجربة'])->assertOk();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_staff_list_and_detail(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment')->create();

        $list = $this->asStaff($this->admin)->getJson(self::BASE.'/users')->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertSame(['recruitment', 'system_admin'], collect($list->json('data'))->pluck('role')->sort()->values()->all());

        $this->asStaff($this->admin)->getJson(self::BASE."/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('user.role', ['slug' => 'recruitment', 'label' => 'مكتب التوظيف', 'office' => true])
            ->assertJsonStructure(['user' => ['id', 'username', 'full_name', 'is_active', 'role', 'role_assigned_at', 'effective_permissions']]);

        $this->asStaff($this->admin)->getJson(self::BASE.'/users/'.Str::uuid())->assertNotFound();
    }
}
