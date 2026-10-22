<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers;

    private const LOGIN = '/api/v1/staff/auth/login';
    private const ME = '/api/v1/staff/auth/me';
    private const LOGOUT = '/api/v1/staff/auth/logout';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_staff_logs_in_from_an_approved_device_with_case_insensitive_username(): void
    {
        $user = StaffUserFactory::new()
        ->withRole('recruitment_staff')
        ->create(['username' => 'sara', 'password' => 'secret-pass']);

        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $response = $this->deviceLogin($device, $key, 'Sara', 'secret-pass')
            ->assertOk()
            ->assertJsonStructure([
                'token', 'token_type', 'user' => ['id', 'username', 'roles', 'permissions']
            ])
            ->assertJsonPath('user.roles.0', 'recruitment_staff');

        $this->assertContains('reports.view', $response->json('user.permissions'));
        $this->assertNotContains('attempts.extend', $response->json('user.permissions'));
        $this->assertDatabaseHas('personal_access_tokens', ['staff_device_id' => $device->id]);
        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = StaffUserFactory::new()
        ->create(['username' => 'sara', 'password' => 'secret-pass']);

        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $this->deviceLogin($device, $key, 'sara', 'nope')
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_unknown_user_gets_the_same_failure_as_a_wrong_password(): void
    {
        $user = StaffUserFactory::new()
        ->create(['username' => 'sara', 'password' => 'secret-pass']);

        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $wrongPassword = $this->deviceLogin($device, $key, 'sara', 'nope');
        $unknownUser = $this->deviceLogin($device, $key, 'ghost', 'nope');

        $this->assertSame($wrongPassword->json('errors'), $unknownUser->json('errors'));
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = StaffUserFactory::new()
        ->inactive()
        ->create(['username' => 'off', 'password' => 'secret-pass']);

        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $this->deviceLogin($device, $key, 'off', 'secret-pass')->assertStatus(422);
    }

    public function test_login_is_rate_limited_per_username_and_ip(): void
    {
        $body = [
            'device_id' => (string) Str::uuid(),
            'challenge_id' => (string) Str::uuid(),
            'signature' => 'x',
            'username' => 'x',
            'password' => 'y',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::LOGIN, $body)->assertStatus(422);
        }

        $this->postJson(self::LOGIN, $body)->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson(self::ME)->assertUnauthorized();
    }

    public function test_me_returns_the_token_owner(): void
    {
        $user = StaffUserFactory::new()
        ->withRole('general_manager')
        ->create(['username' => 'boss']);

        $this->asStaff($user)->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('user.username', 'boss');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = StaffUserFactory::new()->create();

        $this->asStaff($user)->postJson(self::LOGOUT)->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivated_user_loses_access_even_with_an_existing_token(): void
    {
        $user = StaffUserFactory::new()->create();
        $token = $user->createToken('test')->plainTextToken;

        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson(self::ME)->assertUnauthorized();
    }

    public function test_login_with_an_arabic_username_typed_in_any_variant(): void
    {
        $user = StaffUserFactory::new()->create(['username' => 'أحمد', 'password' => 'secret-pass']);
        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $this->assertSame('احمد', $user->username);   // stored in the normalised form

        // Typed with a different alef, and with harakat: still the same person.
        $this->deviceLogin($device, $key, 'إَحْمَد', 'secret-pass')->assertOk();
    }

    public function test_staff_token_lives_for_a_day(): void
    {
        $user = StaffUserFactory::new()->create(['username' => 'sara', 'password' => 'secret-pass']);
        ['device' => $device, 'key' => $key] = $this->approvedDevice($user);

        $this->deviceLogin($device, $key, 'sara', 'secret-pass')->assertOk();

        $expiresAt = \Illuminate\Support\Carbon::parse(\Illuminate\Support\Facades\DB::table('personal_access_tokens')->value('expires_at'));

        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $expiresAt->timestamp, 60);
    }
}
