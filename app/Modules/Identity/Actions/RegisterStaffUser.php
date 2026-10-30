<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a staff account with its first role, in one transaction (D-058).
 *
 * Whoever holds users.add without users.manage may only give a role whose permissions they hold themselves:
 * otherwise users.add would be a way to create a system administrator, i.e. to obtain any permission.
 */
class RegisterStaffUser
{
    /** @param array{username: string, first_name: string, last_name: string, personal_number: string, password: string, is_active: bool} $data */
    public function execute(array $data, Role $role, StaffUser $actor): StaffUser
    {
        if (! $actor->hasPermission('users.manage')) {
            $beyond = $role->permissions()->pluck('slug')->diff($actor->permissionSlugs());

            if ($beyond->isNotEmpty()) {
                throw new AuthorizationException(__('auth.role_beyond_own', ['permissions' => $beyond->sort()->implode('، ')]));
            }
        }

        $user = DB::transaction(function () use ($data, $role) {
            $user = StaffUser::create($data);
            $user->roles()->attach($role->id);

            return $user;
        });

        AccessLog::record('user_created', $actor, ['user_id' => $user->id, 'username' => $user->username, 'role' => $role->slug]);

        return $user->load('roles', 'permissions');
    }
}
