<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeDevice
{
    public function execute(StaffDevice $device, ?StaffUser $actor, string $reason): StaffDevice
    {
        if ($device->status === StaffDevice::STATUS_REVOKED) {
            throw ValidationException::withMessages(['device' => ['The device is already revoked.']]);
        }

        DB::transaction(function () use ($device, $actor, $reason) {
            $device->forceFill([
                'status' => StaffDevice::STATUS_REVOKED,
                'revoked_by' => $actor?->id,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            // Every session that came from this machine ends now.
            AccessToken::where('staff_device_id', $device->id)->delete();
        });

        return $device->refresh();
    }
}
