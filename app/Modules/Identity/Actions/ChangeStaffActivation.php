<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use App\Modules\Identity\Support\AccessLog;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivating and reactivating a staff user (D-079). A deactivated user cannot log in, and every session they have
 * ends at once (their tokens are deleted; the active check on each request stops anything still in flight). Their
 * device, roles and history stay, so reactivating gives the account back as it was. Nobody deactivates themselves
 * (the one who acts holds users.manage, so someone able to manage users always stays; D-020 is checked anyway).
 */
class ChangeStaffActivation
{
    public function deactivate(StaffUser $target, string $reason, StaffUser $actor): StaffUser
    {
        if ($target->is($actor)) {
            throw ValidationException::withMessages(['user' => [__('auth.cannot_deactivate_self')]]);
        }

        DB::transaction(function () use ($target, $reason, $actor) {
            AccessInvariants::serialize();
            $target = StaffUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! $target->is_active) {
                throw ValidationException::withMessages(['user' => [__('auth.already_inactive')]]);
            }

            $target->forceFill(['is_active' => false, 'deactivated_at' => Date::now(), 'deactivated_by' => $actor->id, 'deactivation_reason' => trim($reason)])->save();
            $ended = $target->tokens()->delete();

            AccessInvariants::ensureSomeoneCanManageUsers();

            AccessLog::record('user_deactivated', $actor, ['user_id' => $target->id, 'reason' => trim($reason), 'sessions_ended' => $ended]);
        });

        return $target->fresh(['roles', 'permissions']);
    }

    public function activate(StaffUser $target, StaffUser $actor): StaffUser
    {
        DB::transaction(function () use ($target, $actor) {
            $target = StaffUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($target->is_active) {
                throw ValidationException::withMessages(['user' => [__('auth.already_active')]]);
            }

            $target->forceFill(['is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null, 'deactivation_reason' => null])->save();
            AccessLog::record('user_activated', $actor, ['user_id' => $target->id]);
        });

        return $target->fresh(['roles', 'permissions']);
    }
}
