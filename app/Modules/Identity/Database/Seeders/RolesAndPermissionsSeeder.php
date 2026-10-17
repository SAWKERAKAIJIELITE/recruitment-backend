<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Source of truth for the RBAC matrix (document §02).
 * Only plain ✓ cells appear in ROLES. ✓* cells are granted per user via permission_user.
 * Safe to re-run: it upserts by slug and re-syncs each role's permissions.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'reports.view' => 'مؤشرات وتقارير مفصلة',
        'imports.manage' => 'استيراد ملفات Excel',
        'candidates.manage' => 'تحرير بيانات المرشحين',
        'exam_bank.manage' => 'بنك الأسئلة وخطط الاختبار',
        'exams.take' => 'تقديم الاختبار',
        'attempts.extend' => 'تعديل مدة محاولة نشطة',
        'exceptions.approve' => 'استثناء الرسوب / قبول العذر',
        'interviews.manage' => 'إدخال المقابلات',
        'contracts.manage' => 'إدخال العقود',
        'users.manage' => 'إدارة المستخدمين والأدوار',
        'audit.view' => 'عرض سجلات التدقيق',
        'audit.view_related' => 'عرض تدقيق العمليات ذات الصلة فقط',
    ];

    private const ROLES = [
        'general_manager' => [
            'المدير العام',
            [
                'reports.view',
                'audit.view',
            ],
        ],
        'recruitment_staff' => [
            'مدير/إداري التوظيف',
            [
                'reports.view',
                'imports.manage',
                'candidates.manage',
                'interviews.manage',
                'contracts.manage',
                'audit.view_related',
            ],
        ],
        // 'candidate' => [
        //     'المرشح',
        //     [
        //         'exams.take',
        //     ],
        // ],
        'system_admin' => [
            'مسؤول النظام',
            [
                'reports.view',
                'imports.manage',
                'candidates.manage',
                'exam_bank.manage',
                'interviews.manage',
                'contracts.manage',
                'users.manage',
                'audit.view',
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::PERMISSIONS as $slug => $label) {
                Permission::updateOrCreate(['slug' => $slug], ['label_ar' => $label]);
            }

            foreach (self::ROLES as $slug => [$label, $permissionSlugs]) {
                $role = Role::updateOrCreate(['slug' => $slug], ['label_ar' => $label]);
                $role->permissions()->sync(
                    Permission::whereIn('slug', $permissionSlugs)->pluck('id')
                );
            }
        });
    }
}
