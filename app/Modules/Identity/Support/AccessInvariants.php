<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Rules that every access change must leave true. Call inside the same transaction as the change. */
final class AccessInvariants
{
    public const MANAGE_USERS = 'users.manage';

    /**
     * Access changes run one at a time (a transaction-level advisory lock, released at commit): two administrators
     * removing each other's users.manage at the same moment would otherwise both pass the check below, and an
     * automatic role could be computed from a role being changed at the same time.
     */
    public static function serialize(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('staff_access'))");
    }

    /**
     * Without this, an administrator could remove the last way anyone can manage access and the
     * system could only be repaired by editing the database.
     *
     * @throws ValidationException
     */
    public static function ensureSomeoneCanManageUsers(): void
    {
        $someoneCan = StaffUser::query()
            ->where('is_active', true)
            ->get()
            ->contains(fn(StaffUser $user) => $user->hasPermission(self::MANAGE_USERS));

        if (!$someoneCan) {
            throw ValidationException::withMessages([
                'permission' => ['This change would leave no active staff user able to manage users.'],
            ]);
        }
    }
}
