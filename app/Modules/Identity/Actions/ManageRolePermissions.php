<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Add or remove permissions of a ROLE (everyone holding the role is affected). */
class ManageRolePermissions
{
    /** @param  list<string>  $permissionSlugs */
    public function add(Role $role, array $permissionSlugs, StaffUser $actor): Role
    {
        $permissions = Permission::whereIn('slug', $permissionSlugs)->get();

        // The ✓* cells: these may only be granted to individual users, never to a whole role.
        $individualOnly = $permissions->where('individual_only', true)->pluck('slug');

        if ($individualOnly->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['These permissions can only be granted to individual users, not to roles: ' . $individualOnly->implode(', ')],
            ]);
        }

        $role->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());

        AccessLog::record('role_permissions_added', $actor, ['role' => $role->slug, 'permissions' => $permissionSlugs]);

        return $role->load('permissions');
    }

    public function remove(Role $role, Permission $permission, StaffUser $actor): Role
    {
        DB::transaction(function () use ($role, $permission) {
            $role->permissions()->detach($permission->id);

            AccessInvariants::ensureSomeoneCanManageUsers();
        });

        AccessLog::record('role_permission_removed', $actor, ['role' => $role->slug, 'permission' => $permission->slug]);

        return $role->load('permissions');
    }
}
