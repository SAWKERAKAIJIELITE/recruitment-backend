<?php

namespace App\Modules\Identity\Console;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Http\Requests\RegisterUserRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Bootstrap and break-glass path (D-101): a system administrator from the server shell. A new installation has no
 * staff user, and adding one through the API needs an administrator who is logged in from an approved device; this
 * makes the first. The same rules as the API (username, names, personal number, strong password); the password is
 * asked twice, never passed on the command line. Audited as `access.user_created` with `via: console`.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'staff:create-admin
        {username : Latin lower-case letters, dots, dashes, underscores (3 to 50)}
        {--first-name= : First name}
        {--last-name= : Last name}
        {--personal-number= : Personal number (digits)}';

    protected $description = 'Create a system administrator from the server shell (the first one, or when every administrator is locked out)';

    public function handle(): int
    {
        $role = Role::query()->where('slug', StaffRole::SystemAdmin->value)->first();

        if ($role === null) {
            $this->error('The roles are not seeded: run php artisan db:seed --force first.');

            return self::FAILURE;
        }

        if (StaffUser::query()->where('role_id', $role->id)->where('is_active', true)->exists()
            && ! $this->confirm('An active administrator exists already. Create another one?')) {
            return self::FAILURE;
        }

        $data = [
            'username' => mb_strtolower(trim((string) $this->argument('username'))),
            'first_name' => trim((string) ($this->option('first-name') ?? $this->ask('First name'))),
            'last_name' => trim((string) ($this->option('last-name') ?? $this->ask('Last name'))),
            'personal_number' => trim((string) ($this->option('personal-number') ?? $this->ask('Personal number (digits)'))),
            'password' => (string) $this->secret('Password (8 or more: upper and lower case letters, a digit, a symbol)'),
            'password_confirmation' => (string) $this->secret('The password again'),
            'is_active' => true,
            'role' => $role->slug,
        ];

        $validator = Validator::make($data, (new RegisterUserRequest)->rules(), [], trans('validation.attributes'));

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($data, $role) {
            $user = new StaffUser(collect($data)->only(['username', 'first_name', 'last_name', 'personal_number', 'password', 'is_active'])->all());
            $user->forceFill(['role_id' => $role->id, 'role_assigned_at' => Date::now()])->save();
            AuditTrail::record('access.user_created', null, ['user_id' => $user->id, 'username' => $user->username, 'role' => $role->slug, 'via' => 'console']);

            return $user;
        });

        $this->info("Administrator {$user->username} created ({$user->id}). Register a device from the frontend, then approve it with:");
        $this->line("  php artisan staff:approve-device <device-id> {$user->username} --fingerprint=<the fingerprint shown on that device>");

        return self::SUCCESS;
    }
}
