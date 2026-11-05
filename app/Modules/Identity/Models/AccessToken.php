<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum's token model, extended so a token can be tied to the device it was issued to (staff), or to the exam
 * booking it was issued for (candidates, D-072).
 *
 * @property ?string $exam_booking_id
 */
class AccessToken extends PersonalAccessToken
{
    // Required: Eloquent would otherwise infer "access_tokens" from this class name.
    protected $table = 'personal_access_tokens';

    public function device(): BelongsTo
    {
        return $this->belongsTo(StaffDevice::class, 'staff_device_id');
    }
}
