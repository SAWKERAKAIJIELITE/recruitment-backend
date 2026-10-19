<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveDevice
{
    /**
     * @param  list<string>  $staffUserIds  users allowed to log in from this device
     * @param  string  $fingerprint  what the approver read from the employee's screen
     * @param  StaffUser|null  $approver  null when approved from the console
     */
    public function execute(StaffDevice $device, ?StaffUser $approver, string $fingerprint, array $staffUserIds): StaffDevice
    {
        if ($device->status !== StaffDevice::STATUS_PENDING) {
            throw ValidationException::withMessages(['device' => ['Only a pending device can be approved.']]);
        }

        // Typing the fingerprint back turns the human comparison into a real check.
        $typed = strtoupper(preg_replace('/[^0-9a-f]/i', '', $fingerprint));

        if (!hash_equals(strtoupper(substr($device->fingerprint, 0, 32)), $typed)) {
            throw ValidationException::withMessages([
                'fingerprint' => ['The fingerprint does not match the one shown by the device.'],
            ]);
        }

        DB::transaction(function () use ($device, $approver, $staffUserIds) {
            $device->forceFill([
                'status' => StaffDevice::STATUS_APPROVED,
                'approved_by' => $approver?->id,
                'approved_at' => now(),
            ])->save();

            $device->staffUsers()->syncWithoutDetaching(
                collect($staffUserIds)->mapWithKeys(fn(string $id) => [$id => ['assigned_by' => $approver?->id]])->all()
            );
        });

        return $device->refresh();
    }
}
