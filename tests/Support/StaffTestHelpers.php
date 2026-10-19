<?php

namespace Tests\Support;

use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Testing\TestResponse;

trait StaffTestHelpers
{
    /** @return array{key: \OpenSSLAsymmetricKey, public_pem: string} */
    protected function newDeviceKey(): array
    {
        $options = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'config' => getenv('OPENSSL_CONF') ?: base_path('tests/openssl.cnf')
        ];

        // On Windows, PHP often cannot find openssl.cnf and key generation fails.
        // Fix: set the OPENSSL_CONF environment variable to PHP's extras/ssl/openssl.cnf.
        // if ($conf = getenv('OPENSSL_CONF')) {
        //     $options['config'] = $conf;
        // }

        $key = openssl_pkey_new($options);
        $this->assertNotFalse($key, 'openssl_pkey_new failed: ' . openssl_error_string());

        return ['key' => $key, 'public_pem' => openssl_pkey_get_details($key)['key']];
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
            'status' => StaffDevice::STATUS_PENDING,
        ]);

        return ['device' => $device, 'key' => $pair['key']];
    }

    /** @return array{device: StaffDevice, key: \OpenSSLAsymmetricKey} */
    protected function approvedDevice(StaffUser ...$users): array
    {
        $pair = $this->newDeviceKey();
        $parsed = DeviceCrypto::parsePublicKey($pair['public_pem']);

        $device = new StaffDevice();
        $device->forceFill([
            'label' => 'Approved PC',
            'public_key' => $parsed['pem'],
            'fingerprint' => $parsed['fingerprint'],
            'status' => StaffDevice::STATUS_APPROVED,
            'approved_at' => now(),
        ])->save();

        $device->staffUsers()->attach(collect($users)->pluck('id')->all());

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
            strtolower(trim($username))
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
