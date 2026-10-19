<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RegisterDevice
{
    public function execute(string $publicKeyPem, string $label, ?string $mac, ?string $osInfo): StaffDevice
    {
        try {
            $key = DeviceCrypto::parsePublicKey($publicKeyPem);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'public_key' => ['The public key must be a P-256 (prime256v1) EC public key in PEM format.'],
            ]);
        }

        // Idempotent. A revoked key stays revoked: re-registering it cannot revive it.
        $existing = StaffDevice::where('fingerprint', $key['fingerprint'])->first();

        if ($existing !== null) {
            return $existing;
        }

        if (StaffDevice::where('status', StaffDevice::STATUS_PENDING)->count() >= (int) config('staff_auth.max_pending_devices')) {
            abort(429, 'Device registration is temporarily unavailable.');
        }

        try {
            return StaffDevice::create([
                'label' => $label,
                'public_key' => $key['pem'],
                'fingerprint' => $key['fingerprint'],
                'reported_mac' => $mac,
                'os_info' => $osInfo,
                'status' => StaffDevice::STATUS_PENDING,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests registered the same new key at the same moment: return the winner.
            return StaffDevice::where('fingerprint', $key['fingerprint'])->firstOrFail();
        }
    }
}
