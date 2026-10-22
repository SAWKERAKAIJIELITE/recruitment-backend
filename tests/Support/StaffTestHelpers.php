<?php

namespace Tests\Support;

use App\Modules\Identity\Enums\DeviceStatus;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Testing\TestResponse;

trait StaffTestHelpers
{
    /** Each test method gets a fresh instance, so the counter restarts for every test. */
    private int $nextDeviceKey = 0;

    /**
     * The next unused fixed key pair (see TestDeviceKeys for why keys are not generated here).
     *
     * @return array{key: \OpenSSLAsymmetricKey, public_pem: string}
     */
    protected function newDeviceKey(): array
    {
        $index = $this->nextDeviceKey++;

        $this->assertLessThan(TestDeviceKeys::count(), $index, 'The test needs more fixed keys: add one to TestDeviceKeys.');

        return TestDeviceKeys::get($index);
    }

    protected function sign($key, string $payload): string
    {
        openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256);   // DER, like Node's crypto.sign

        return base64_encode($signature);
    }

    /** @return array{device: StaffDevice, key: \OpenSSLAsymmetricKey} */
    protected function pendingDevice(): array
    {
        $pair = $this->newDeviceKey();
        $parsed = DeviceCrypto::parsePublicKey($pair['public_pem']);

        $device = StaffDevice::create([
            'label' => 'Pending PC',
            'public_key' => $parsed['pem'],
            'fingerprint' => $parsed['fingerprint'],
            'reported_mac' => '00:11:22:33:44:55',
        ]);

        return ['device' => $device, 'key' => $pair['key']];
    }

    /** @return array{device: StaffDevice, key: \OpenSSLAsymmetricKey} */
    protected function approvedDevice(StaffUser $owner): array
    {
        $pair = $this->newDeviceKey();
        $parsed = DeviceCrypto::parsePublicKey($pair['public_pem']);

        $device = new StaffDevice();
        $device->forceFill([
            'label' => 'Approved PC',
            'public_key' => $parsed['pem'],
            'fingerprint' => $parsed['fingerprint'],
            'reported_mac' => '00:11:22:33:44:55',
            'status' => DeviceStatus::Approved,
            'staff_user_id' => $owner->id,
            'approved_at' => now(),
        ])->save();

        return ['device' => $device, 'key' => $pair['key']];
    }

    protected function requestChallenge(StaffDevice $device): array
    {
        return $this->postJson('/api/v1/staff/auth/challenge', ['device_id' => $device->id])
            ->assertOk()
            ->json();
    }

    protected function payloadFor(array $challenge, StaffDevice $device, string $username): string
    {
        return DeviceCrypto::loginPayload(
            $challenge['challenge_id'],
            $challenge['challenge'],
            $device->id,
            $username
        );
    }

    protected function loginBody(StaffDevice $device, $key, array $challenge, string $username, string $password): array
    {
        return [
            'device_id' => $device->id,
            'challenge_id' => $challenge['challenge_id'],
            'signature' => $this->sign($key, $this->payloadFor($challenge, $device, $username)),
            'username' => $username,
            'password' => $password,
        ];
    }

    /** What a real client does: challenge, sign, log in. */
    protected function deviceLogin(StaffDevice $device, $key, string $username, string $password): TestResponse
    {
        $challenge = $this->requestChallenge($device);

        return $this->postJson('/api/v1/staff/auth/login', $this->loginBody($device, $key, $challenge, $username, $password));
    }

    /** Act as a staff user. Resets guards: the Sanctum guard caches its user between requests in one test. */
    protected function asStaff(StaffUser $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
