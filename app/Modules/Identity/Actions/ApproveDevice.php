<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveDevice
{
    /**
     * @param  StaffUser  $owner  the one staff user this device will belong to
     * @param  string  $fingerprint  what the approver read from the employee's screen
     * @param  StaffUser|null  $approver  null when approved from the console
     * @return StaffDevice
     */
    public function execute(StaffDevice $device, ?StaffUser $approver, string $fingerprint, StaffUser $owner): StaffDevice
    {
        if ($device->status !== DeviceStatus::Pending) {
            throw ValidationException::withMessages(['device' => ['Only a pending device can be approved.']]);
        }

        if (!$owner->is_active) {
            throw ValidationException::withMessages(['staff_user_id' => ['The device owner must be an active staff user.']]);
        }

        if (StaffDevice::where('staff_user_id', $owner->id)->where('status', DeviceStatus::Approved->value)->exists()) {
            throw ValidationException::withMessages([
                'staff_user_id' => ['This user already has an approved device. Revoke it first.'],
            ]);
        }

        // Typing the fingerprint back turns the human comparison into a real check.
        $typed = strtoupper(preg_replace('/[^0-9a-f]/i', '', $fingerprint));

        if (!hash_equals(strtoupper(substr($device->fingerprint, 0, 32)), $typed)) {
            throw ValidationException::withMessages([
                'fingerprint' => ['The fingerprint does not match the one shown by the device.'],
            ]);
        }

        DB::transaction(function () use ($device, $approver, $owner) {
            $device->forceFill([
                'status' => DeviceStatus::Approved,
                'staff_user_id' => $owner->id,
                'approved_by' => $approver?->id,
                'approved_at' => now(),
            ])->save();

            // $device->staffUsers()->syncWithoutDetaching(
            //     collect($staffUserIds)->mapWithKeys(fn(string $id) => [$id => ['assigned_by' => $approver?->id]])->all()
            // );
        });

        return $device->refresh();
    }
}
