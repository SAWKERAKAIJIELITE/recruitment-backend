<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RegisterDevice
{
    public function execute(string $publicKeyPem, string $label, string $mac, string $osInfo): StaffDevice
    {
        try {
            $key = DeviceCrypto::parsePublicKey($publicKeyPem);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'public_key' => [__('access.public_key_format')],
            ]);
        }

        // Idempotent. A revoked key stays revoked: re-registering it cannot revive it.
        $existing = StaffDevice::where('fingerprint', $key['fingerprint'])->first();

        if ($existing !== null) {
            return $existing;
        }

        if (StaffDevice::where('status', DeviceStatus::Pending->value)->count() >= (int) config('staff_auth.max_pending_devices')) {
            abort(429, __('access.registration_unavailable'));
        }

        try {
            // status is not passed: the model default is "pending" and status is not mass assignable.
            return StaffDevice::create([
                'label' => $label,
                'public_key' => $key['pem'],
                'fingerprint' => $key['fingerprint'],
                'reported_mac' => $mac,
                'os_info' => $osInfo,
            ]);
            // $device = StaffDevice::create([
            //     'label' => $label,
            //     'public_key' => $key['pem'],
            //     'fingerprint' => $key['fingerprint'],
            //     'reported_mac' => $mac,
            //     'os_info' => $osInfo,
            //     // 'status' => DeviceStatus::Pending,
            // ]);
            // $device->status = DeviceStatus::Pending;
            // return $device;
        } catch (UniqueConstraintViolationException) {
            // Two requests registered the same new key at the same moment: return the winner.
            return StaffDevice::where('fingerprint', $key['fingerprint'])->firstOrFail();
        }
    }
}
