<?php

namespace App\Modules\Audit\Support;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Log;

/**
 * The single place where "who did what" is recorded. Today it writes to the application log;
 * when the Audit module gets its table, only this class changes.
 */
final class AuditTrail
{
    public static function record(string $event, ?StaffUser $actor, array $context = []): void
    {
        Log::notice($event, ['actor_id' => $actor?->id] + $context);
    }
}
