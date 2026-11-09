<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalogue and the roles (D-085): four offices, each with full control of its own section only,
 * the general manager (every read-only permission) and the system administrator (every permission, always: not
 * stored, see StaffUser::permissionSlugs()).
 *
 * - The catalogue is upserted on every run.
 * - A role receives its default permissions ONLY when it is created (D-020). After that the database is the source
 *   of truth: administrators change roles through the access-management API, and re-running this seeder never
 *   overwrites their changes.
 * - Candidates are not staff and have no roles.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** slug => [label, read_only] */
    public const PERMISSIONS = [
        // Recruitment
        'applications.view' => ['عرض ملفات المرشحين وطلباتهم كاملة', true],
        'candidates.manage' => ['إدخال وتصحيح بيانات المرشحين والطلبات والقوائم', false],
        'applications.start' => ['بدء دورة الطلبات المستلمة', false],
        'applications.close' => ['إغلاق طلب الترشيح وإنهاؤه', false],
        'imports.view' => ['عرض دفعات الاستيراد', true],
        'imports.manage' => ['استيراد ملفات Excel', false],
        'imports.approve' => ['اعتماد دفعات الاستيراد', false],
        // Exams and interviews
        'exams.view' => ['عرض مواد الاختبار وخططه وجلساته ونتائجه', true],
        'exam_bank.manage' => ['بنك الأسئلة وخطط الاختبار', false],
        'exams.manage' => ['جدولة جلسات الاختبار وإدخال العلامات اليدوية', false],
        'attempts.extend' => ['تعديل مدة محاولة نشطة', false],
        'exam_results.correct' => ['تصحيح علامة يدوية بعد صدور نتيجة الاختبار', false],
        'pass_policies.manage' => ['إضافة إصدار جديد من قواعد النجاح', false],
        'exceptions.approve' => ['استثناء الرسوب / قبول العذر', false],
        'interviews.view' => ['عرض المقابلات ونتائجها', true],
        'interviews.manage' => ['إدخال المقابلات', false],
        'interviews.correct' => ['تصحيح نتيجة مقابلة مسجلة', false],
        // Contracts
        'contracts.view' => ['عرض العقود والشواغر', true],
        'contracts.manage' => ['إدخال العقود', false],
        'vacancies.manage' => ['رفع ملف الشواغر', false],
        // Documenting
        'documents.view' => ['عرض الوثائق', true],
        'documents.manage' => ['رفع الوثائق وأرشفتها وأنواعها', false],
        // Everyone's or the management's
        'reports.view' => ['لوحة المؤشرات والتقارير الشاملة', true],
        'audit.view' => ['عرض سجلات التدقيق', true],
        'audit.view_related' => ['عرض تدقيق عمليات القسم فقط', true],
        'users.manage' => ['إدارة المستخدمين والأدوار', false],
        'users.add' => ['إضافة المستخدمين', false],
        'devices.approve' => ['اعتماد الأجهزة الموثوقة وإلغاؤها', false],
    ];

    /** The defaults of each role, given when the role is created. The administrator's are not stored (it holds all). */
    public const ROLES = [
        'recruitment' => [
            'applications.view',
            'candidates.manage',
            'applications.start',
            'applications.close',
            'imports.view',
            'imports.manage',
            'imports.approve',
            'audit.view_related',
        ],
        'exams_interviews' => [
            'exams.view',
            'exam_bank.manage',
            'exams.manage',
            'attempts.extend',
            'exam_results.correct',
            'pass_policies.manage',
            'exceptions.approve',
            'interviews.view',
            'interviews.manage',
            'interviews.correct',
            'audit.view_related',
        ],
        'contracts' => [
            'contracts.view',
            'contracts.manage',
            'audit.view_related',
        ],
        'documenting' => [
            'documents.view',
            'documents.manage',
            'audit.view_related',
        ],
        'general_manager' => [
            'applications.view',
            'imports.view',
            'exams.view',
            'interviews.view',
            'contracts.view',
            'documents.view',
            'reports.view',
            'audit.view',
        ],
        'system_admin' => [],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::PERMISSIONS as $slug => [$label, $readOnly]) {
                Permission::updateOrCreate(['slug' => $slug], ['label_ar' => $label, 'read_only' => $readOnly]);
            }

            foreach (self::ROLES as $slug => $permissionSlugs) {
                $role = Role::firstOrCreate(['slug' => $slug], ['label_ar' => StaffRole::from($slug)->labelAr()]);

                if ($role->wasRecentlyCreated) {
                    $role->permissions()->sync(Permission::whereIn('slug', $permissionSlugs)->pluck('id'));
                }
            }
        });
    }
}
