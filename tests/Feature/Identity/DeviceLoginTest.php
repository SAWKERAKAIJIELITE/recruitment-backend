<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

class DeviceLoginTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers;

    private const LOGIN = '/api/v1/staff/auth/login';

    private StaffUser $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = StaffUserFactory::new()
            ->withRole('recruitment_staff')
            ->create(['username' => 'sara', 'password' => 'secret-pass']);
    }

    public function test_pending_device_cannot_log_in(): void
    {
        ['device' => $device, 'key' => $key] = $this->pendingDevice();

        $this->deviceLogin($device, $key, 'sara', 'secret-pass')->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_revoked_device_cannot_log_in(): void
    {
        ['device' => $device, 'key' => $key] = $this->approvedDevice($this->user);
        $device->forceFill(['status' => StaffDevice::STATUS_REVOKED, 'revoked_at' => now()])->save();

        $this->deviceLogin($device, $key, 'sara', 'secret-pass')->assertStatus(422);
    }

    public function test_unknown_device_receives_a_challenge_of_the_same_shape(): void
    {
        $this->postJson('/api/v1/staff/auth/challenge', ['device_id' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonStructure(['challenge_id', 'challenge', 'expires_at']);
    }

    public function test_challenge_cannot_be_replayed(): void
    {
        ['device' => $device, 'key' => $key] = $this->approvedDevice($this->user);
        $challenge = $this->requestChallenge($device);
        $body = $this->loginBody($device, $key, $challenge, 'sara', 'secret-pass');

        $this->postJson(self::LOGIN, $body)->assertOk();
        $this->postJson(self::LOGIN, $body)->assertStatus(422);
    }

    public function test_expired_challenge_is_rejected(): void
    {
        ['device' => $device, 'key' => $key] = $this->approvedDevice($this->user);
        $challenge = $this->requestChallenge($device);

        $this->travel(2)->minutes();

        $this->postJson(self::LOGIN, $this->loginBody($device, $key, $challenge, 'sara', 'secret-pass'))
            ->assertStatus(422);
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        ['device' => $device] = $this->approvedDevice($this->user);
        $otherKey = $this->newDeviceKey()['key'];

        $this->deviceLogin($device, $otherKey, 'sara', 'secret-pass')->assertStatus(422);
    }

    public function test_signature_is_bound_to_the_username(): void
    {
        ['device' => $device, 'key' => $key] = $this->approvedDevice($this->user);
        $challenge = $this->requestChallenge($device);

        $body = $this->loginBody($device, $key, $challenge, 'someone-else', 'secret-pass');
        $body['username'] = 'sara';   // signed for a different username

        $this->postJson(self::LOGIN, $body)->assertStatus(422);
    }

    public function test_user_must_be_assigned_to_the_device(): void
    {
        StaffUserFactory::new()->create(['username' => 'omar', 'password' => 'omar-pass']);
        ['device' => $device, 'key' => $key] = $this->approvedDevice($this->user);   // assigned to sara only

        $this->deviceLogin($device, $key, 'omar', 'omar-pass')->assertStatus(422);
    }

    public function test_wrong_password_on_a_valid_device_is_rejected(): void
    {
        ['device' => $device, 'key' => $key] = $this->approvedDevice($this->user);

        $this->deviceLogin($device, $key, 'sara', 'wrong')->assertStatus(422);
    }

    public function test_challenge_is_bound_to_its_device(): void
    {
        ['device' => $deviceA] = $this->approvedDevice($this->user);
        ['device' => $deviceB, 'key' => $keyB] = $this->approvedDevice($this->user);

        $challengeForA = $this->requestChallenge($deviceA);

        // Device B tries to use the challenge that was issued to device A.
        $this->postJson(self::LOGIN, $this->loginBody($deviceB, $keyB, $challengeForA, 'sara', 'secret-pass'))
            ->assertStatus(422);
    }
}
