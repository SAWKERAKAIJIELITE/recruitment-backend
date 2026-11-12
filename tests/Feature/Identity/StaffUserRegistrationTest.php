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
            'role' => 'recruitment',
            'password' => 'Str0ng!Pass',
            'password_confirmation' => 'Str0ng!Pass',
        ];
    }

    public function test_an_account_is_created_with_its_role_and_audited(): void
    {
        Log::spy();

        $this->asStaff(StaffUserFactory::new()->withRole('system_admin')->create())->postJson(self::URL, $this->body())
            ->assertCreated()
            ->assertJsonPath('user.username', 'new.clerk')
            ->assertJsonPath('user.role.slug', 'recruitment');

        $user = StaffUser::where('username', 'new.clerk')->sole();
        $this->assertSame('recruitment', $user->role->slug);
        $this->assertNotNull($user->role_assigned_at);
        $this->assertSame('004512', $user->personal_number);
        $this->assertNotSame('Str0ng!Pass', $user->password);   // hashed
        Log::shouldHaveReceived('notice')->withArgs(fn (string $event) => str_contains($event, 'user_created'))->once();
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

    public function test_the_messages_are_arabic_with_arabic_field_names(): void
    {
        $admin = StaffUserFactory::new()->withRole('system_admin')->create();

        $errors = $this->asStaff($admin)->postJson(self::URL, ['password' => 'abc', 'password_confirmation' => 'abd', 'username' => 'Ab'])->assertStatus(422)->json('errors');

        $this->assertSame('الحقل الرقم الذاتي مطلوب.', $errors['personal_number'][0]);
        $this->assertSame('الحقل الاسم الأول مطلوب.', $errors['first_name'][0]);
        $this->assertContains(__('validation.custom.username.regex'), $errors['username']);
        $this->assertSame([
            'تأكيد كلمة المرور غير مطابق.',
            'يجب ألا تقل كلمة المرور عن 8 أحرف.',
            'يجب أن تحتوي كلمة المرور حرفًا كبيرًا وحرفًا صغيرًا على الأقل.',
            'يجب أن تحتوي كلمة المرور رمزًا واحدًا على الأقل (مثل ! @ # $).',
            'يجب أن تحتوي كلمة المرور رقمًا واحدًا على الأقل.',
        ], $errors['password']);
    }

    public function test_adding_users_is_the_administrators(): void
    {
        foreach (['recruitment', 'general_manager', 'exams_interviews'] as $role) {
            $this->asStaff(StaffUserFactory::new()->withRole($role)->create())->postJson(self::URL, $this->body())
                ->assertForbidden()
                ->assertJsonPath('message', __('auth.forbidden'));
        }

        $this->assertFalse(Permission::query()->where('slug', 'users.add')->exists());   // users.manage adds them (D-092)
    }
}
