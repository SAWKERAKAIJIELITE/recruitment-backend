<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Permission;
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

    public function test_roles_match_the_offices(): void
    {
        foreach (RolesAndPermissionsSeeder::ROLES as $role => $permissions) {
            if ($role === 'system_admin') {
                continue;
            }

            $this->assertEqualsCanonicalizing($permissions, StaffUserFactory::new()->withRole($role)->create()->permissionSlugs()->all(), $role);
        }

        $this->assertEqualsCanonicalizing(
            ['applications.view', 'candidates.manage', 'applications.start', 'applications.close', 'imports.view', 'imports.manage', 'audit.view_related'],
            StaffUserFactory::new()->withRole('recruitment')->create()->permissionSlugs()->all(),
        );
        $this->assertEqualsCanonicalizing(
            Permission::query()->pluck('slug')->all(),
            StaffUserFactory::new()->withRole('system_admin')->create()->permissionSlugs()->all(),
        );
    }

    public function test_every_permission_belongs_to_an_office_or_the_management(): void
    {
        $given = collect(RolesAndPermissionsSeeder::ROLES)->flatten()->unique();
        $adminOnly = ['users.manage', 'devices.approve', 'vacancies.manage'];
        // Held by no role at first: the administrator gives it to whom the owner chooses (D-102).
        $grantedLater = ['exams.offline'];

        $this->assertEqualsCanonicalizing(array_keys(RolesAndPermissionsSeeder::PERMISSIONS), [...$given->all(), ...$adminOnly, ...$grantedLater]);
        $this->assertFalse($given->contains('exams.offline'));
    }

    public function test_unknown_abilities_and_roleless_users_get_nothing(): void
    {
        $nobody = StaffUserFactory::new()->create();

        $this->assertTrue($nobody->permissionSlugs()->isEmpty());
        $this->assertFalse($nobody->can('reports.view'));
        $this->assertFalse($nobody->can('does.not.exist'));
    }
}
