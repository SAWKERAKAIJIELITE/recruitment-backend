<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/** Change the role of ONE staff user (D-085): every user holds one role, and their permissions are the role's. */
class ManageStaffAccess
{
    /** Gives the user this role in place of the one they hold. Refused if nobody would be left able to manage users. */
    public function changeRole(StaffUser $target, Role $role, StaffUser $actor): StaffUser
    {
        DB::transaction(function () use ($target, $role, $actor) {
            AccessInvariants::serialize();
            $previous = $target->role()->value('slug');

            $target->forceFill(['role_id' => $role->id, 'role_assigned_by' => $actor->id, 'role_assigned_at' => Date::now()])->save();
            $target->forgetPermissionCache();

            AccessInvariants::ensureSomeoneCanManageUsers();

            AccessLog::record('role_changed', $actor, ['user_id' => $target->id, 'from' => $previous, 'to' => $role->slug]);
        });

        return $target->fresh(['role.permissions', 'deactivatedBy']);
    }
}
