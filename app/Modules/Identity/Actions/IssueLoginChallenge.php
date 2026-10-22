<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\DeviceLoginChallenge;
use App\Modules\Identity\Models\StaffDevice;
use Illuminate\Support\Str;

class IssueLoginChallenge
{
    /**
     * @return array{challenge_id: string, challenge: string, expires_at: string}
     * */
    public function execute(string $deviceId): array
    {
        $expiresAt = now()->addSeconds((int) config('staff_auth.challenge_ttl_seconds'));
        $device = StaffDevice::find($deviceId);

        if ($device === null || !$device->isApproved()) {
            // Same shape as a real answer, nothing stored: a caller cannot learn whether a device
            // exists or is approved. The login that follows will simply fail.
            return [
                'challenge_id' => (string) Str::uuid(),
                'challenge' => base64_encode(random_bytes(32)),
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        }

        $challenge = DeviceLoginChallenge::create([
            'staff_device_id' => $device->id,
            'challenge' => base64_encode(random_bytes(32)),
            'expires_at' => $expiresAt,
        ]);

        return [
            'challenge_id' => $challenge->id,
            'challenge' => $challenge->challenge,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
