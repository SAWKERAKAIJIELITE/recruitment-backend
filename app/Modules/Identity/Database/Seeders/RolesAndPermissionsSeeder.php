<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default RBAC matrix (analysis doc §02).
 *
 * - The permission catalogue is upserted on every run.
 * - A role receives its default permissions ONLY when it is created. After that the database is the
 *   source of truth: administrators change roles through the access-management API, and re-running this
 *   seeder never overwrites their changes.
 * - Only plain ✓ cells appear in ROLES. ✓* cells are individual-only permissions, granted per user.
 * - Candidates are not staff and have no roles.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    private const PERMISSIONS = [
        'reports.view' => 'مؤشرات وتقارير مفصلة',
        'imports.manage' => 'استيراد ملفات Excel',
        'imports.approve' => 'اعتماد دفعات الاستيراد',
        'candidates.manage' => 'تحرير بيانات المرشحين',
        'exam_bank.manage' => 'بنك الأسئلة وخطط الاختبار',
        'exams.take' => 'تقديم الاختبار',
        'attempts.extend' => 'تعديل مدة محاولة نشطة',
        'exceptions.approve' => 'استثناء الرسوب / قبول العذر',
        'applications.close' => 'إغلاق طلب الترشيح وإنهاؤه',
        'interviews.manage' => 'إدخال المقابلات',
        'contracts.manage' => 'إدخال العقود',
        'users.manage' => 'إدارة المستخدمين والأدوار',
        'users.add' => 'إضافة المستخدمين',
        'audit.view' => 'عرض سجلات التدقيق',
        'audit.view_related' => 'عرض تدقيق العمليات ذات الصلة فقط',
        'devices.approve' => 'اعتماد الأجهزة الموثوقة وإلغاؤها',
    ];

    /** The ✓* cells: may be granted to individual users, never attached to a role. */
    private const INDIVIDUAL_ONLY = ['attempts.extend', 'exceptions.approve', 'applications.close', 'imports.approve'];

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
                'users.add',
                'audit.view',
                'devices.approve',
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::PERMISSIONS as $slug => $label) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['label_ar' => $label, 'individual_only' => in_array($slug, self::INDIVIDUAL_ONLY, true)]
                );
            }

            foreach (self::ROLES as $slug => [$label, $permissionSlugs]) {
                $role = Role::firstOrCreate(['slug' => $slug], ['label_ar' => $label]);
                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync(
                        Permission::whereIn('slug', $permissionSlugs)->pluck('id')
                    );
                }
            }
        });
    }
}
