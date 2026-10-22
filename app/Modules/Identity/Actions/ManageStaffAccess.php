<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\DB;

/** Change the access of ONE staff user: which roles they hold, and their personal grants and denials. */
class ManageStaffAccess
{
    public function assignRole(StaffUser $target, Role $role, StaffUser $actor): StaffUser
    {
        $target->roles()->syncWithoutDetaching([$role->id]);

        AccessLog::record('role_assigned', $actor, ['user_id' => $target->id, 'role' => $role->slug]);

        return $this->fresh($target);
    }

    public function removeRole(StaffUser $target, Role $role, StaffUser $actor): StaffUser
    {
        DB::transaction(function () use ($target, $role) {
            $target->roles()->detach($role->id);

            AccessInvariants::ensureSomeoneCanManageUsers();
        });

        AccessLog::record('role_removed', $actor, ['user_id' => $target->id, 'role' => $role->slug]);

        return $this->fresh($target);
    }

    /** Grant (true) or deny (false) one permission for this user, whatever their roles say. */
    public function setPermission(StaffUser $target, Permission $permission, bool $granted, StaffUser $actor): StaffUser
    {
        DB::transaction(function () use ($target, $permission, $granted, $actor) {
            $target->permissions()->syncWithoutDetaching([
                $permission->id => ['is_granted' => $granted, 'granted_by' => $actor->id],
            ]);

            AccessInvariants::ensureSomeoneCanManageUsers();
        });

        AccessLog::record($granted ? 'permission_granted' : 'permission_denied', $actor, [
            'user_id' => $target->id,
            'permission' => $permission->slug,
        ]);

        return $this->fresh($target);
    }

    /** Remove the personal override: the user goes back to whatever their roles give. */
    public function clearPermission(StaffUser $target, Permission $permission, StaffUser $actor): StaffUser
    {
        DB::transaction(function () use ($target, $permission) {
            $target->permissions()->detach($permission->id);

            AccessInvariants::ensureSomeoneCanManageUsers();
        });

        AccessLog::record('permission_override_cleared', $actor, ['user_id' => $target->id, 'permission' => $permission->slug]);

        return $this->fresh($target);
    }

    /** Reload so the response shows the new state, not the cached permissions of the old one. */
    private function fresh(StaffUser $user): StaffUser
    {
        return $user->fresh(['roles', 'permissions']);
    }
}
