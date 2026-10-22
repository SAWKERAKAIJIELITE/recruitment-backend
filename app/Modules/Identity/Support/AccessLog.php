<?php

namespace App\Modules\Identity\Support;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Log;

/**
 * Single seam for "who changed whose access". Today it writes to the application log;
 */
final class AccessLog
{
    public static function record(string $event, StaffUser $actor, array $context = []): void
    {
        // Log::notice("access.{$event}", ['actor_id' => $actor->id] + $context);
        AuditTrail::record("access.{$event}", $actor, $context);
    }
}
