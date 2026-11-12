<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/** LOCAL DEVELOPMENT ONLY. Never run in production: the password is "password". One account per role (D-085). */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Known passwords: never in production, whoever calls this seeder.
        if (app()->environment('production')) {
            $this->command?->warn('DevUsersSeeder does not run in production.');

            return;
        }

        $accounts = [
            ['admin', 'مسؤول النظام', 'system_admin', '1234'],
            ['manager', 'المدير العام', 'general_manager', '5678'],
            ['staff', 'موظف التوظيف', 'recruitment', '9012'],
            ['staff.two', 'معتمد الاستيراد', 'recruitment', '9013'],
            ['exams', 'موظف الاختبارات', 'exams_interviews', '3456'],
            ['exams.only', 'موظف الاختبارات', 'exams', '3457'],
            ['interviews', 'موظف المقابلات', 'interviews', '3458'],
            ['contracts', 'موظف العقود', 'contracts', '7890'],
            ['documents', 'موظف التوثيق', 'documenting', '2468'],
        ];

        foreach ($accounts as [$username, $name, $roleSlug, $personalNumber]) {
            $user = StaffUser::updateOrCreate(
                ['username' => $username],
                [
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[1],
                    'password' => 'password',
                    'personal_number' => $personalNumber,
                    'is_active' => true,
                ],
            );
            $user->forceFill(['role_id' => Role::where('slug', $roleSlug)->value('id'), 'role_assigned_at' => Date::now()])->save();
        }
    }
}
