<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\DeviceLoginChallenge;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginStaff
{
    /**
     * Step 2b will add device and signature verification at the top of this method.
     *
     * @return array{token: string, user: StaffUser}
     *
     * @throws ValidationException one generic message for every failure reason
     */
    public function execute(StaffLoginData $data): array
    {
        $username = Str::lower(trim($data->username));

        // Stage 1, the machine: no password is touched until the device has proved itself.
        $device = $this->proveDevice($data, $username);

        if ($device === null) {
            $this->fail('device_or_signature', $data->deviceId);
        }

        // Stage 2, the person.
        $user = StaffUser::where('username', $username)->first();

        if ($user === null) {
            Hash::make($data->password);   // burn comparable CPU time so "no such user" isn't faster
            $this->fail('unknown_user', $device->id);
        }

        $passwordMatches = Hash::check($data->password, $user->password);
        $assigned = $device->staffUsers()->whereKey($user->id)->exists();

        if (!$passwordMatches || !$user->is_active || !$assigned) {
            $this->fail(match (true) {
                !$passwordMatches => 'bad_password',
                !$user->is_active => 'inactive_user',
                default => 'not_assigned_to_device',
            }, $device->id, $user->id);
        }

        $device->forceFill(['last_seen_at' => now()])->save();
        $user->forceFill(['last_login_at' => now()])->save();

        $newToken = $user->createToken($data->tokenName);
        $newToken->accessToken->forceFill(['staff_device_id' => $device->id])->save();

        return [
            'token' => $newToken->plainTextToken,
            'user' => $user,
        ];
    }

    /** Consumes the challenge (single use) and verifies the signature. Null = not proven. */
    private function proveDevice(StaffLoginData $data, string $username): ?StaffDevice
    {
        // One atomic UPDATE: valid, unexpired, unused and bound to this device, or nothing happens.
        // The challenge is burned even if the signature turns out wrong.
        $consumed = DeviceLoginChallenge::query()
            ->whereKey($data->challengeId)
            ->where('staff_device_id', $data->deviceId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        if ($consumed !== 1) {
            return null;
        }

        $challenge = DeviceLoginChallenge::find($data->challengeId);
        $device = StaffDevice::find($data->deviceId);

        if ($challenge === null || $device === null || !$device->isApproved()) {
            return null;
        }

        // The challenge text comes from our database, never from the client.
        $payload = DeviceCrypto::loginPayload($challenge->id, $challenge->challenge, $device->id, $username);

        return DeviceCrypto::verify($device->public_key, $payload, $data->signature) ? $device : null;
    }

    private function fail(string $reason, ?string $deviceId = null, ?string $userId = null): never
    {
        // Interim audit trail until the Audit module exists. Usernames are not logged on purpose:
        // people sometimes type their password into the username field.
        Log::notice('staff_login_failed', [
            'reason' => $reason,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'ip' => request()->ip(),
        ]);

        throw ValidationException::withMessages([
            'username' => [trans('auth.failed')],
        ]);
    }
}
