<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use App\Modules\Identity\Support\AccessLog;
use App\Modules\Identity\Support\AutomaticRoles;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Change the access of ONE staff user: which roles they hold, and their personal grants and denials. Roles follow
 * the direct permissions (AutomaticRoles, D-070): a role whose permissions are all granted directly is added, one
 * whose permissions are all denied directly is removed.
 */
class ManageStaffAccess
{
    /**
     * Gives the role by hand: its permissions now come from the role, so the user's direct grants and denials of
     * those permissions are cleared (a role the system had added automatically becomes a role given by hand).
     */
    public function assignRole(StaffUser $target, Role $role, StaffUser $actor): StaffUser
    {
        $cleared = DB::transaction(function () use ($target, $role, $actor) {
            $assignment = ['automatic' => false, 'note' => null, 'assigned_by' => $actor->id, 'created_at' => Date::now()];

            if ($target->roles()->whereKey($role->id)->exists()) {
                $target->roles()->updateExistingPivot($role->id, $assignment);
            } else {
                $target->roles()->attach($role->id, $assignment);
            }

            $permissionIds = $role->permissions()->pluck('permissions.id');
            $cleared = $target->permissions()->whereIn('permissions.id', $permissionIds)->pluck('slug')->all();
            $target->permissions()->detach($permissionIds);

            AutomaticRoles::sync($target, $actor);
            AccessInvariants::ensureSomeoneCanManageUsers();

            return $cleared;
        });

        AccessLog::record('role_assigned', $actor, ['user_id' => $target->id, 'role' => $role->slug, 'overrides_cleared' => $cleared]);

        return $this->fresh($target);
    }

    /**
     * Takes the role away. A role the system added (all its permissions granted directly) would come straight back,
     * so taking it away takes those direct grants away with it.
     */
    public function removeRole(StaffUser $target, Role $role, StaffUser $actor): StaffUser
    {
        $revoked = DB::transaction(function () use ($target, $role, $actor) {
            $automatic = (bool) $target->roles()->whereKey($role->id)->first()?->pivot->automatic;
            $target->roles()->detach($role->id);
            $revoked = [];

            if ($automatic) {
                $grants = $target->permissions()->wherePivot('is_granted', true)->whereIn('permissions.id', $role->permissions()->pluck('permissions.id'));
                $revoked = $grants->pluck('slug')->all();
                $target->permissions()->detach($grants->pluck('permissions.id'));
            }

            AutomaticRoles::sync($target, $actor);
            AccessInvariants::ensureSomeoneCanManageUsers();

            return $revoked;
        });

        AccessLog::record('role_removed', $actor, ['user_id' => $target->id, 'role' => $role->slug, 'grants_removed' => $revoked]);

        return $this->fresh($target);
    }

    /** Grant (true) or deny (false) one permission for this user, whatever their roles say. */
    public function setPermission(StaffUser $target, Permission $permission, bool $granted, StaffUser $actor): StaffUser
    {
        DB::transaction(function () use ($target, $permission, $granted, $actor) {
            $target->permissions()->syncWithoutDetaching([
                $permission->id => ['is_granted' => $granted, 'granted_by' => $actor->id, 'note' => null],
            ]);

            AutomaticRoles::sync($target, $actor);
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
        DB::transaction(function () use ($target, $permission, $actor) {
            $target->permissions()->detach($permission->id);

            AutomaticRoles::sync($target, $actor);
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
