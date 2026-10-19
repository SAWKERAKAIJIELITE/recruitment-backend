<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers;

    private const REGISTER = '/api/v1/staff/devices/register';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function registration(string $publicPem): array
    {
        return [
            'public_key' => $publicPem,
            'label' => 'Front desk PC',
            'mac_address' => '00:11:22:33:44:55',
            'os_info' => 'Windows 11',
        ];
    }

    public function test_registration_creates_a_pending_device_and_is_idempotent(): void
    {
        $pem = $this->newDeviceKey()['public_pem'];

        $first = $this->postJson(self::REGISTER, $this->registration($pem))
            ->assertCreated()
            ->assertJsonPath('device.status', 'pending');
        $second = $this->postJson(self::REGISTER, $this->registration($pem))
        ->assertOk();

        $this->assertSame($first->json('device.id'), $second->json('device.id'));
        $this->assertDatabaseCount('staff_devices', 1);
        $this->assertMatchesRegularExpression('/^([0-9A-F]{4} ){7}[0-9A-F]{4}$/', $first->json('device.fingerprint'));
    }

    public function test_registration_rejects_something_that_is_not_a_p256_public_key(): void
    {
        $this->postJson(self::REGISTER, $this->registration('not a key'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('public_key');
    }

    public function test_registration_stops_when_too_many_devices_are_pending(): void
    {
        config(['staff_auth.max_pending_devices' => 1]);

        $this->postJson(self::REGISTER, $this->registration($this->newDeviceKey()['public_pem']))->assertCreated();
        $this->postJson(self::REGISTER, $this->registration($this->newDeviceKey()['public_pem']))->assertStatus(429);
    }

    public function test_a_revoked_key_cannot_be_revived_by_registering_again(): void
    {
        ['device' => $device] = $this->approvedDevice();
        $device->forceFill(['status' => StaffDevice::STATUS_REVOKED, 'revoked_at' => now()])->save();

        $this->postJson(self::REGISTER, $this->registration($device->public_key))
            ->assertOk()
            ->assertJsonPath('device.status', 'revoked');
    }

    public function test_status_endpoint(): void
    {
        ['device' => $device] = $this->pendingDevice();

        $this->getJson("/api/v1/staff/devices/{$device->id}/status")->assertOk()->assertJson(['status' => 'pending']);
        $this->getJson('/api/v1/staff/devices/' . Str::uuid() . '/status')->assertNotFound();
        $this->getJson('/api/v1/staff/devices/not-a-uuid/status')->assertNotFound();
    }

    public function test_only_holders_of_devices_approve_can_manage_devices(): void
    {
        $staff = StaffUserFactory::new()->withRole('recruitment_staff')->create();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();

        $this->asStaff($staff)->getJson('/api/v1/staff/devices')->assertForbidden();
        $this->asStaff($admin)->getJson('/api/v1/staff/devices')->assertOk();
    }

    public function test_approval_requires_the_matching_fingerprint(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $user = StaffUserFactory::new()->create();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", [
            'fingerprint' => 'DEAD BEEF DEAD BEEF DEAD BEEF DEAD BEEF',
            'staff_user_ids' => [$user->id],
        ])->assertStatus(422)->assertJsonValidationErrors('fingerprint');

        $this->assertSame('pending', $device->fresh()->status);
    }

    public function test_approval_assigns_users_and_activates_the_device(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $user = StaffUserFactory::new()->create();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", [
            'fingerprint' => $device->displayFingerprint(),
            'staff_user_ids' => [$user->id],
        ])->assertOk()->assertJsonPath('device.status', 'approved');

        $device->refresh();

        $this->assertSame($admin->id, $device->approved_by);
        $this->assertNotNull($device->approved_at);
        $this->assertDatabaseHas('device_assignments', [
            'staff_device_id' => $device->id,
            'staff_user_id' => $user->id,
            'assigned_by' => $admin->id,
        ]);
    }

    public function test_a_device_cannot_be_approved_twice(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $user = StaffUserFactory::new()->create();
        $body = ['fingerprint' => $device->displayFingerprint(), 'staff_user_ids' => [$user->id]];

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", $body)->assertOk();
        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors('device');
    }

    public function test_revoking_a_device_deletes_its_tokens(): void
    {
        $user = StaffUserFactory::new()->create(['username' => 'sara', 'password' => 'secret-pass']);
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $this->deviceLogin($device, $key, 'sara', 'secret-pass')->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['staff_device_id' => $device->id]);

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/revoke", ['reason' => 'Lost laptop'])
            ->assertOk()
            ->assertJsonPath('device.status', 'revoked');

        $this->assertDatabaseMissing('personal_access_tokens', ['staff_device_id' => $device->id]);
        $this->assertSame('Lost laptop', $device->fresh()->revoke_reason);
    }
}
