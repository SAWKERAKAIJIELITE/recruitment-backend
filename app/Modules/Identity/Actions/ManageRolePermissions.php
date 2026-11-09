<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add or remove permissions of a ROLE (everyone holding the role is affected; D-020, D-085). The system administrator
 * holds every permission and is not edited; the general manager's role holds read-only permissions only.
 */
class ManageRolePermissions
{
    /** @param  list<string>  $permissionSlugs */
    public function add(Role $role, array $permissionSlugs, StaffUser $actor): Role
    {
        $this->assertEditable($role);
        $permissions = Permission::whereIn('slug', $permissionSlugs)->get();

        if ($role->slug === StaffRole::GeneralManager->value && $permissions->contains('read_only', false)) {
            throw ValidationException::withMessages([
                'permissions' => [__('access.manager_views_only', ['permissions' => $permissions->where('read_only', false)->pluck('slug')->sort()->implode('، ')])],
            ]);
        }

        DB::transaction(function () use ($role, $permissions, $permissionSlugs, $actor) {
            AccessInvariants::serialize();
            $role->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
            AccessInvariants::ensureSomeoneCanManageUsers();

            AccessLog::record('role_permissions_added', $actor, ['role' => $role->slug, 'permissions' => $permissionSlugs]);
        });

        return $role->load('permissions');
    }

    public function remove(Role $role, Permission $permission, StaffUser $actor): Role
    {
        $this->assertEditable($role);

        DB::transaction(function () use ($role, $permission, $actor) {
            AccessInvariants::serialize();
            $role->permissions()->detach($permission->id);
            AccessInvariants::ensureSomeoneCanManageUsers();

            AccessLog::record('role_permission_removed', $actor, ['role' => $role->slug, 'permission' => $permission->slug]);
        });

        return $role->load('permissions');
    }

    private function assertEditable(Role $role): void
    {
        if ($role->isAdministrator()) {
            throw ValidationException::withMessages(['role' => [__('access.administrator_holds_all')]]);
        }
    }
}
