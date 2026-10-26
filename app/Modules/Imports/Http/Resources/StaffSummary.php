<?php

namespace App\Modules\Imports\Http\Resources;

use App\Modules\Identity\Models\StaffUser;

/** How a staff user appears inside import resources. */
final class StaffSummary
{
    /** @return array{id: string, username: string, full_name: string}|null */
    public static function of(?StaffUser $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'username' => $user->username, 'full_name' => $user->full_name];
    }
}
