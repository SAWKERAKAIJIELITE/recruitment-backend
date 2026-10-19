<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function override(StaffUser $user, string $slug, bool $granted): void
    {
        $user->permissions()->attach(
            Permission::where('slug', $slug)->value('id'),
            ['is_granted' => $granted],
        );
    }

    public function test_roles_match_the_rbac_matrix(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();

        $this->assertEqualsCanonicalizing(
            ['reports.view', 'imports.manage', 'candidates.manage', 'interviews.manage', 'contracts.manage', 'audit.view_related'],
            $staff->permissionSlugs()->all()
        );
        $this->assertEqualsCanonicalizing(['reports.view', 'audit.view'], $manager->permissionSlugs()->all());
        $this->assertEqualsCanonicalizing(
            ['devices.approve' ,'reports.view', 'imports.manage', 'candidates.manage', 'exam_bank.manage', 'interviews.manage', 'contracts.manage', 'users.manage', 'audit.view'],
            $admin->permissionSlugs()->all()
        );
    }

    public function test_starred_permissions_are_not_implicit_for_anyone(): void
    {
        foreach (['general_manager', 'recruitment_staff', 'system_admin'] as $role) {
            $user = StaffUserFactory::new()->withRole($role)->create();

            $this->assertFalse($user->can('attempts.extend'), "$role must not get attempts.extend implicitly");
            $this->assertFalse($user->can('exceptions.approve'), "$role must not get exceptions.approve implicitly");
        }
    }

    public function test_direct_grant_adds_a_permission(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();
        $this->override($staff, 'attempts.extend', true);

        $this->assertTrue($staff->fresh()->can('attempts.extend'));
    }

    public function test_direct_denial_removes_a_role_permission(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $this->assertTrue($admin->can('users.manage'));

        $this->override($admin, 'users.manage', false);

        $this->assertFalse($admin->fresh()->can('users.manage'));
    }

    public function test_unknown_abilities_and_roleless_users_get_nothing(): void
    {
        $nobody = StaffUserFactory::new()->create();

        $this->assertTrue($nobody->permissionSlugs()->isEmpty());
        $this->assertFalse($nobody->can('reports.view'));
        $this->assertFalse($nobody->can('does.not.exist'));
    }
}
