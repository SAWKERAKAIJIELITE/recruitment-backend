<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        return Role::where('slug', $slug)->firstOrFail()->permissions()->pluck('slug')->all();
    }

    // ---- who may manage access ----

    public function test_only_holders_of_users_manage_can_use_access_management(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();

        $this->asStaff($staff)->getJson(self::BASE . '/roles')->assertForbidden();
        $this->asStaff($staff)->getJson(self::BASE . '/permissions')->assertForbidden();
        $this->asStaff($this->admin)->getJson(self::BASE . '/roles')->assertOk();
    }

    public function test_permission_catalogue_marks_individual_only_permissions(): void
    {
        $response = $this->asStaff($this->admin)->getJson(self::BASE . '/permissions')->assertOk();

        $bySlug = collect($response->json('data'))->keyBy('slug');

        $this->assertTrue($bySlug['attempts.extend']['individual_only']);
        $this->assertTrue($bySlug['exceptions.approve']['individual_only']);
        $this->assertFalse($bySlug['reports.view']['individual_only']);
    }

    // ---- roles ----

    public function test_adding_a_permission_to_a_role_affects_its_members(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();
        $this->assertFalse($staff->can('devices.approve'));

        $this->asStaff($this->admin)
            ->postJson(self::BASE . '/roles/recruitment_staff/permissions', ['permissions' => ['devices.approve']])
            ->assertOk()
            ->assertJsonPath('role.slug', 'recruitment_staff');

        $this->assertContains('devices.approve', $this->rolePermissions('recruitment_staff'));
        $this->assertTrue($staff->fresh()->can('devices.approve'));
    }

    public function test_individual_only_permissions_cannot_be_given_to_a_role(): void
    {
        $this->asStaff($this->admin)
            ->postJson(self::BASE . '/roles/recruitment_staff/permissions', ['permissions' => ['attempts.extend']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions');

        $this->assertNotContains('attempts.extend', $this->rolePermissions('recruitment_staff'));
    }

    public function test_unknown_permission_slug_is_rejected(): void
    {
        $this->asStaff($this->admin)
            ->postJson(self::BASE . '/roles/recruitment_staff/permissions', ['permissions' => ['does.not.exist']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_removing_a_permission_from_a_role(): void
    {
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();
        $this->assertTrue($manager->can('reports.view'));

        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . '/roles/general_manager/permissions/reports.view')
            ->assertOk();

        $this->assertFalse($manager->fresh()->can('reports.view'));
    }

    public function test_removing_a_permission_the_role_does_not_have_is_404(): void
    {
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . '/roles/general_manager/permissions/users.manage')
            ->assertNotFound();
    }

    public function test_the_last_way_to_manage_users_cannot_be_removed_from_a_role(): void
    {
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . '/roles/system_admin/permissions/users.manage')
            ->assertStatus(422)
            ->assertJsonValidationErrors('permission');

        // The failed change was rolled back.
        $this->assertContains('users.manage', $this->rolePermissions('system_admin'));
    }

    public function test_reseeding_does_not_overwrite_changes_made_through_the_api(): void
    {
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . '/roles/general_manager/permissions/reports.view')
            ->assertOk();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertNotContains('reports.view', $this->rolePermissions('general_manager'));
    }

    // ---- one staff user ----

    public function test_granting_an_individual_only_permission_to_one_user(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();

        $response = $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/permissions", ['permission' => 'attempts.extend', 'effect' => 'grant'])
            ->assertOk();

        $this->assertContains('attempts.extend', $response->json('user.effective_permissions'));
        $this->assertTrue($staff->fresh()->can('attempts.extend'));
        $this->assertDatabaseHas('permission_staff_user', [
            'staff_user_id' => $staff->id,
            'is_granted' => true,
            'granted_by' => $this->admin->id,
        ]);
    }

    public function test_denying_a_permission_overrides_the_role_and_clearing_restores_it(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();
        $this->assertTrue($staff->can('reports.view'));

        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/permissions", ['permission' => 'reports.view', 'effect' => 'deny'])
            ->assertOk()
            ->assertJsonPath('user.overrides.0', ['permission' => 'reports.view', 'effect' => 'deny']);
        $this->assertFalse($staff->fresh()->can('reports.view'));

        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . "/users/{$staff->id}/permissions/reports.view")
            ->assertOk()
            ->assertJsonPath('user.overrides', []);
        $this->assertTrue($staff->fresh()->can('reports.view'));
    }

    public function test_changing_an_existing_override_updates_it(): void
    {
        $staff = StaffUserFactory::new()->create();

        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/permissions", ['permission' => 'reports.view', 'effect' => 'deny'])
            ->assertOk();
        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/permissions", ['permission' => 'reports.view', 'effect' => 'grant'])
            ->assertOk()
            ->assertJsonPath('user.overrides.0.effect', 'grant');

        $this->assertDatabaseCount('permission_staff_user', 1);
    }

    public function test_clearing_an_override_that_does_not_exist_is_404(): void
    {
        $staff = StaffUserFactory::new()->create();

        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . "/users/{$staff->id}/permissions/reports.view")
            ->assertNotFound();
    }

    public function test_an_override_needs_a_valid_effect_and_permission(): void
    {
        $staff = StaffUserFactory::new()->create();

        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/permissions", ['permission' => 'reports.view', 'effect' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effect');
        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/permissions", ['permission' => 'nope', 'effect' => 'grant'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('permission');
    }

    public function test_an_admin_cannot_deny_themselves_the_last_way_to_manage_users(): void
    {
        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$this->admin->id}/permissions", ['permission' => 'users.manage', 'effect' => 'deny'])
            ->assertStatus(422);

        $this->assertDatabaseCount('permission_staff_user', 0);
    }

    public function test_with_a_second_administrator_one_may_lose_the_permission(): void
    {
        $second = StaffUserFactory::new()->withRole('system_admin')->create();

        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$second->id}/permissions", ['permission' => 'users.manage', 'effect' => 'deny'])
            ->assertOk();

        $this->assertFalse($second->fresh()->can('users.manage'));
    }

    public function test_assigning_and_removing_a_role(): void
    {
        $staff = StaffUserFactory::new()->create();

        $this->asStaff($this->admin)
            ->postJson(self::BASE . "/users/{$staff->id}/roles", ['role' => 'general_manager'])
            ->assertOk()
            ->assertJsonPath('user.roles', ['general_manager']);
        $this->assertTrue($staff->fresh()->can('audit.view'));

        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . "/users/{$staff->id}/roles/general_manager")
            ->assertOk()
            ->assertJsonPath('user.roles', []);
        $this->assertFalse($staff->fresh()->can('audit.view'));
    }

    public function test_removing_a_role_the_user_does_not_hold_is_404(): void
    {
        $staff = StaffUserFactory::new()->create();

        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . "/users/{$staff->id}/roles/general_manager")
            ->assertNotFound();
    }

    public function test_the_last_administrator_cannot_lose_the_admin_role(): void
    {
        $this->asStaff($this->admin)
            ->deleteJson(self::BASE . "/users/{$this->admin->id}/roles/system_admin")
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->roles()->where('slug', 'system_admin')->exists());
    }

    public function test_staff_list_and_detail(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();

        $list = $this->asStaff($this->admin)->getJson(self::BASE . '/users')->assertOk();
        $this->assertCount(2, $list->json('data'));

        $this->asStaff($this->admin)->getJson(self::BASE . "/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('user.roles', ['recruitment_staff'])
            ->assertJsonStructure(['user' => ['id', 'username', 'full_name', 'is_active', 'roles', 'overrides', 'effective_permissions']]);

        $this->asStaff($this->admin)->getJson(self::BASE . '/users/' . Str::uuid())->assertNotFound();
    }
}
