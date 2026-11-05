<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\DB;

/**
 * A forgotten password (owner, Step 10; D-071): an administrator sets a new one and tells the user. Every session
 * of the user ends (all their tokens are deleted), so whoever held the old password is signed out. The user's device
 * stays approved: logging in still needs it.
 */
class ResetStaffPassword
{
    public function execute(StaffUser $target, string $password, StaffUser $actor): StaffUser
    {
        $ended = DB::transaction(function () use ($target, $password) {
            $target->forceFill(['password' => $password])->save();

            return $target->tokens()->delete();
        });

        AccessLog::record('password_reset', $actor, ['user_id' => $target->id, 'sessions_ended' => $ended]);

        return $target->fresh(['roles', 'permissions']);
    }
}
