<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Seeder;

/** LOCAL DEVELOPMENT ONLY. Never run in production: the password is "password". */
class DevUsersSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['admin', 'مسؤول النظام', 'system_admin','1234'],
            ['manager', 'المدير العام', 'general_manager','5678'],
            ['staff', 'إداري التوظيف', 'recruitment_staff','9012'],
            // ['candidate1', 'مرشح تجريبي', StaffUser::TYPE_CANDIDATE, 'candidate'],
        ];

        foreach ($accounts as [$username, $name, $roleSlug,$personalNumber]) {
            $user = StaffUser::updateOrCreate(
                ['username' => $username],
                [
                    'first_name' => explode(' ', $name)[0],
                    'last_name' => explode(' ', $name)[1],
                    // 'type' => $type,
                    'password' => 'password',
                    'personal_number' => $personalNumber,
                    'is_active' => true
                ],
            );
            $user->roles()->syncWithoutDetaching([Role::where('slug', $roleSlug)->value('id')]);
        }

        // The ✓* cells: individual grants
        $this->grant('staff', ['exam_bank.manage', 'attempts.extend', 'exceptions.approve']);
        $this->grant('manager', ['attempts.extend', 'exceptions.approve', 'imports.approve', 'applications.close']);
    }

    private function grant(string $username, array $permissionSlugs): void
    {
        $user = StaffUser::where('username', $username)->firstOrFail();

        foreach (Permission::whereIn('slug', $permissionSlugs)->pluck('id') as $permissionId) {
            $user->permissions()->syncWithoutDetaching([$permissionId => ['is_granted' => true]]);
        }
    }
}
