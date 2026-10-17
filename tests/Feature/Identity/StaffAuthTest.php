<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = '/api/v1/staff/auth/login';
    private const ME = '/api/v1/staff/auth/me';
    private const LOGOUT = '/api/v1/staff/auth/logout';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_staff_logs_in_with_case_insensitive_username(): void
    {
        StaffUserFactory::new()->withRole('recruitment_staff')
            ->create(['username' => 'sara', 'password' => 'secret-pass']);

        $response = $this->postJson(self::LOGIN, ['username' => 'Sara', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'username', 'roles', 'permissions']])
            ->assertJsonPath('user.roles.0', 'recruitment_staff');

        $this->assertContains('reports.view', $response->json('user.permissions'));
        $this->assertNotContains('attempts.extend', $response->json('user.permissions'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        StaffUserFactory::new()->create(['username' => 'sara', 'password' => 'secret-pass']);

        $this->postJson(self::LOGIN, ['username' => 'sara', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_unknown_user_gets_the_same_failure_as_a_wrong_password(): void
    {
        StaffUserFactory::new()->create(['username' => 'sara', 'password' => 'secret-pass']);

        $wrongPassword = $this->postJson(self::LOGIN, ['username' => 'sara', 'password' => 'nope']);
        $unknownUser = $this->postJson(self::LOGIN, ['username' => 'ghost', 'password' => 'nope']);

        $this->assertSame($wrongPassword->json('errors'), $unknownUser->json('errors'));
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        StaffUserFactory::new()->inactive()->create(['username' => 'off', 'password' => 'secret-pass']);

        $this->postJson(self::LOGIN, ['username' => 'off', 'password' => 'secret-pass'])
            ->assertStatus(422);
    }

    public function test_login_is_rate_limited_per_username_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::LOGIN, ['username' => 'x', 'password' => 'y'])->assertStatus(422);
        }

        $this->postJson(self::LOGIN, ['username' => 'x', 'password' => 'y'])->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson(self::ME)->assertUnauthorized();
    }

    public function test_me_returns_the_token_owner(): void
    {
        $user = StaffUserFactory::new()->withRole('general_manager')->create(['username' => 'boss']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson(self::ME)
            ->assertOk()
            ->assertJsonPath('user.username', 'boss');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = StaffUserFactory::new()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson(self::LOGOUT)->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_deactivated_user_loses_access_even_with_an_existing_token(): void
    {
        $user = StaffUserFactory::new()->create();
        $token = $user->createToken('test')->plainTextToken;

        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson(self::ME)->assertUnauthorized();
    }
}
