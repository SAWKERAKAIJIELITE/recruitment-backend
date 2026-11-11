<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/** Creates a staff account with its role, in one transaction (D-058, D-085): users.manage, the administrator's (D-092). */
class RegisterStaffUser
{
    /** @param array{username: string, first_name: string, last_name: string, personal_number: string, password: string, is_active: bool} $data */
    public function execute(array $data, Role $role, StaffUser $actor): StaffUser
    {
        $user = DB::transaction(function () use ($data, $role, $actor) {
            $user = new StaffUser($data);
            $user->forceFill(['role_id' => $role->id, 'role_assigned_by' => $actor->id, 'role_assigned_at' => Date::now()])->save();
            AccessLog::record('user_created', $actor, ['user_id' => $user->id, 'username' => $user->username, 'role' => $role->slug]);

            return $user;
        });

        return $user->load('role.permissions');
    }
}
