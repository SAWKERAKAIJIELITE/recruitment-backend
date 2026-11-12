<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The first administrator, from the server shell (D-101). */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_QUESTION = 'Password (8 or more: upper and lower case letters, a digit, a symbol)';

    private function create(string $username, string $password, ?string $again = null, array $options = [])
    {
        return $this->artisan('staff:create-admin', ['username' => $username] + $options + ['--first-name' => 'مدير', '--last-name' => 'النظام', '--personal-number' => '100200'])
            ->expectsQuestion(self::PASSWORD_QUESTION, $password)
            ->expectsQuestion('The password again', $again ?? $password);
    }

    public function test_the_first_administrator_is_made_from_the_shell(): void
    {
        $this->artisan('staff:create-admin', ['username' => 'root'])->expectsOutputToContain('db:seed')->assertFailed();   // no roles yet
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->create(' Root.Admin ', 'Str0ng!Pass')->expectsOutputToContain('staff:approve-device')->assertSuccessful();

        $admin = StaffUser::query()->where('username', 'root.admin')->sole();
        $this->assertSame('system_admin', $admin->role->slug);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('Str0ng!Pass', $admin->password));
        $this->assertTrue($admin->hasPermission('users.manage'));
        $event = DB::table('audit_events')->where('event', 'access.user_created')->sole();
        $this->assertNull($event->actor_id);
        $this->assertSame('console', json_decode($event->context, true)['via']);
    }

    public function test_the_api_rules_apply_and_a_second_one_is_confirmed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->create('root', 'weak')->assertFailed();
        $this->create('root', 'Str0ng!Pass', 'Other!Pass1')->assertFailed();
        $this->create('Root Admin', 'Str0ng!Pass')->assertFailed();                                      // not a valid username
        $this->assertSame(0, StaffUser::query()->count());

        $this->create('root', 'Str0ng!Pass')->assertSuccessful();
        $this->artisan('staff:create-admin', ['username' => 'second'])->expectsConfirmation('An active administrator exists already. Create another one?', 'no')->assertFailed();
        $this->artisan('staff:create-admin', ['username' => 'root', '--first-name' => 'مدير', '--last-name' => 'آخر', '--personal-number' => '100201'])
            ->expectsConfirmation('An active administrator exists already. Create another one?', 'yes')
            ->expectsQuestion(self::PASSWORD_QUESTION, 'Str0ng!Pass')
            ->expectsQuestion('The password again', 'Str0ng!Pass')
            ->assertFailed();                                                                              // the username is taken
        $this->assertSame(1, StaffUser::query()->count());
    }
}
