<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Adding staff users (D-058). */
class StaffUserRegistrationTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/new';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);   // the "uncompromised" check, offline
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'username' => 'new.clerk',
            'first_name' => 'سامر',
            'last_name' => 'الأحمد',
            'personal_number' => '004512',
            'is_active' => true,
            'role' => 'recruitment_staff',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ];
    }

    /** A user who may add users but not manage access (users.add granted on its own). */
    private function adder(string $role = 'recruitment_staff'): StaffUser
    {
        $user = StaffUserFactory::new()->withRole($role)->create();
        $user->permissions()->attach(Permission::where('slug', 'users.add')->value('id'), ['is_granted' => true]);

        return $user;
    }

    public function test_an_account_is_created_with_its_role_and_audited(): void
    {
        Log::spy();

        $this->asStaff(StaffUserFactory::new()->withRole('system_admin')->create())->postJson(self::URL, $this->body())
            ->assertCreated()
            ->assertJsonPath('user.username', 'new.clerk')
            ->assertJsonPath('user.roles', ['recruitment_staff']);

        $user = StaffUser::where('username', 'new.clerk')->sole();
        $this->assertSame(['recruitment_staff'], $user->roles->pluck('slug')->all());
        $this->assertSame('004512', $user->personal_number);
        $this->assertNotSame('Str0ng!Pass', $user->password);   // hashed
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event) => str_contains($event, 'user_created'))->once();
    }

    public function test_without_users_manage_only_roles_within_ones_own_permissions(): void
    {
        $adder = $this->adder();   // recruitment_staff + users.add

        $this->asStaff($adder)->postJson(self::URL, $this->body(['role' => 'system_admin']))->assertForbidden();
        $this->asStaff($adder)->postJson(self::URL, $this->body(['role' => 'general_manager', 'username' => 'gm.two', 'personal_number' => '7777']))->assertForbidden();   // audit.view is not the adder's
        $this->assertSame(0, StaffUser::whereIn('username', ['new.clerk', 'gm.two'])->count());

        $this->asStaff($adder)->postJson(self::URL, $this->body())->assertCreated();   // the adder's own role
    }

    public function test_the_personal_number_is_digits_only_and_unique(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();

        foreach (['-1234', '12.50', '1e10', '123', 'abcd'] as $bad) {
            $this->asStaff($admin)->postJson(self::URL, $this->body(['personal_number' => $bad]))->assertStatus(422)->assertJsonValidationErrors('personal_number');
        }

        $this->asStaff($admin)->postJson(self::URL, $this->body())->assertCreated();
        $this->asStaff($admin)->postJson(self::URL, $this->body(['username' => 'other.one']))->assertStatus(422)->assertJsonValidationErrors('personal_number');
    }

    public function test_the_password_must_be_strong_and_confirmed(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();

        $this->asStaff($admin)->postJson(self::URL, $this->body(['password' => 'weakpass', 'password_confirmation' => 'weakpass']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->asStaff($admin)->postJson(self::URL, $this->body(['password_confirmation' => 'Other!Pass1']))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_adding_users_needs_users_add(): void
    {
        $this->asStaff(StaffUserFactory::new()->withRole('recruitment_staff')->create())->postJson(self::URL, $this->body())->assertForbidden();
    }
}
