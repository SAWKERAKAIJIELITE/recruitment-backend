<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeDevice
{
    public function execute(StaffDevice $device, ?StaffUser $actor, string $reason): StaffDevice
    {
        DB::transaction(function () use (&$device, $actor, $reason) {
            $device = StaffDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();

            if ($device->status === DeviceStatus::Revoked) {
                throw ValidationException::withMessages(['device' => ['The device is already revoked.']]);
            }

            $device->forceFill([
                'status' => DeviceStatus::Revoked,
                'revoked_by' => $actor?->id,
                'revoked_at' => now(),
                'revoke_reason' => $reason,
            ])->save();

            // Every session that came from this machine ends now.
            $ended = AccessToken::where('staff_device_id', $device->id)->delete();

            AuditTrail::record('device.revoked', $actor, ['device_id' => $device->id, 'user_id' => $device->staff_user_id, 'reason' => $reason, 'sessions_ended' => $ended]);
        });

        return $device->refresh();
    }
}
