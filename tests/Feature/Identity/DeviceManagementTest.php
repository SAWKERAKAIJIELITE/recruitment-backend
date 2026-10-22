<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Enums\DeviceStatus;
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

    private function registration(string $publicPem, array $overrides = []): array
    {
        return array_merge([
            'public_key' => $publicPem,
            'label' => 'Front desk PC',
            'mac_address' => '00:11:22:33:44:55',
            'os_info' => 'Windows 11',
        ], $overrides);
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

    public function test_registration_stores_the_mac_address_in_a_macaddr_column(): void
    {
        $pem = $this->newDeviceKey()['public_pem'];

        $this->postJson(self::REGISTER, $this->registration($pem, ['mac_address' => '00-11-22-AA-BB-CC']))->assertCreated();

        // PostgreSQL normalises every accepted spelling to lowercase with colons.
        $this->assertDatabaseHas('staff_devices', ['reported_mac' => '00:11:22:aa:bb:cc']);
    }

    public function test_registration_requires_a_mac_address(): void
    {
        $pem = $this->newDeviceKey()['public_pem'];

        $this->postJson(self::REGISTER, $this->registration($pem, ['mac_address' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mac_address');
    }

    public function test_registration_rejects_a_malformed_mac_address(): void
    {
        $pem = $this->newDeviceKey()['public_pem'];

        $this->postJson(self::REGISTER, $this->registration($pem, ['mac_address' => 'not-a-mac']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('mac_address');
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
        $owner = StaffUserFactory::new()->create();
        ['device' => $device] = $this->approvedDevice($owner);
        $device->forceFill(['status' => DeviceStatus::Revoked, 'revoked_at' => now()])->save();

        $this->postJson(self::REGISTER, $this->registration($device->public_key))
            ->assertOk()
            ->assertJsonPath('device.status', 'revoked');
    }

    public function test_status_endpoint(): void
    {
        ['device' => $device] = $this->pendingDevice();

        $this->getJson("/api/v1/staff/devices/{$device->id}/status")->assertOk()->assertExactJson(['status' => 'pending']);
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

    public function test_device_list_can_be_filtered_by_status(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $this->pendingDevice();
        $this->approvedDevice($admin);

        $this->asStaff($admin)->getJson('/api/v1/staff/devices?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->asStaff($admin)->getJson('/api/v1/staff/devices?status=nonsense')->assertStatus(422);
    }

    public function test_approval_requires_the_matching_fingerprint(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $owner = StaffUserFactory::new()->create();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", [
            'fingerprint' => 'DEAD BEEF DEAD BEEF DEAD BEEF DEAD BEEF',
            'staff_user_id' => $owner->id,
        ])->assertStatus(422)->assertJsonValidationErrors('fingerprint');

        $this->assertSame(DeviceStatus::Pending, $device->fresh()->status);
    }

    public function test_approval_makes_the_device_the_property_of_one_user(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $owner = StaffUserFactory::new()->create();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", [
            'fingerprint' => $device->displayFingerprint(),
            'staff_user_id' => $owner->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.staff_user.id', $owner->id);

        $device->refresh();

        $this->assertSame($admin->id, $device->approved_by);
        $this->assertSame($owner->id, $device->staff_user_id);
        $this->assertNotNull($device->approved_at);
    }

    public function test_an_inactive_user_cannot_own_a_device(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $owner = StaffUserFactory::new()->inactive()->create();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$device->id}/approve", [
            'fingerprint' => $device->displayFingerprint(),
            'staff_user_id' => $owner->id,
        ])->assertStatus(422)->assertJsonValidationErrors('staff_user_id');
    }

    public function test_a_device_cannot_be_approved_twice(): void
    {
        ['device' => $device] = $this->pendingDevice();
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $owner = StaffUserFactory::new()->create();
        $body = ['fingerprint' => $device->displayFingerprint(), 'staff_user_id' => $owner->id];

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
            ->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseMissing('personal_access_tokens', ['staff_device_id' => $device->id]);
        $this->assertSame('Lost laptop', $device->fresh()->revoke_reason);
    }

    public function test_database_refuses_an_approved_device_without_an_owner(): void
    {
        ['device' => $device] = $this->pendingDevice();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $device->forceFill(['status' => DeviceStatus::Approved, 'approved_at' => now()])->save();
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        \App\Modules\Identity\Models\StaffDevice::create([
            'label' => 'Sneaky',
            'public_key' => 'x',
            'fingerprint' => str_repeat('a', 64),
            'reported_mac' => '00:11:22:33:44:55',
            'status' => 'approved',
        ]);
    }

    public function test_a_user_can_own_only_one_approved_device(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $owner = StaffUserFactory::new()->create();
        $this->approvedDevice($owner);
        ['device' => $second] = $this->pendingDevice();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$second->id}/approve", [
            'fingerprint' => $second->displayFingerprint(),
            'staff_user_id' => $owner->id,
        ])->assertStatus(422)->assertJsonValidationErrors('staff_user_id');
    }

    public function test_after_revoking_the_old_device_the_user_can_get_a_new_one(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();
        $owner = StaffUserFactory::new()->create();
        ['device' => $old] = $this->approvedDevice($owner);
        ['device' => $new] = $this->pendingDevice();

        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$old->id}/revoke", ['reason' => 'Lost'])->assertOk();
        $this->asStaff($admin)->postJson("/api/v1/staff/devices/{$new->id}/approve", [
            'fingerprint' => $new->displayFingerprint(),
            'staff_user_id' => $owner->id,
        ])->assertOk();

        $this->assertSame($new->id, $owner->fresh()->device->id);
        $this->assertCount(2, $owner->devices);   // history keeps the revoked one
    }

    public function test_database_refuses_two_approved_devices_for_one_user(): void
    {
        $owner = StaffUserFactory::new()->create();
        $this->approvedDevice($owner);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->approvedDevice($owner);
    }
}
