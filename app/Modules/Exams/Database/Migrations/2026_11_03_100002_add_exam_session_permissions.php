<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The permissions of exam sessions, for a database that already has its roles (D-020: the seeder gives a role its
 * defaults only when it creates it). A fresh database gets them from RolesAndPermissionsSeeder; this adds nothing
 * there, because the roles do not exist yet.
 *
 *  - exams.manage: sessions, bookings and hand marks; given to the recruitment staff and the system administrator;
 *  - exam_results.correct: correcting a hand mark after the result was decided; individual only (never a role).
 */
return new class extends Migration {
    private const PERMISSIONS = [
        'exams.manage' => ['جدولة جلسات الاختبار وإدخال العلامات اليدوية', false, ['recruitment_staff', 'system_admin']],
        'exam_results.correct' => ['تصحيح علامة يدوية بعد صدور نتيجة الاختبار', true, []],
    ];

    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        foreach (self::PERMISSIONS as $slug => [$label, $individualOnly, $roles]) {
            if (DB::table('permissions')->where('slug', $slug)->doesntExist()) {
                DB::table('permissions')->insert(['id' => (string) Str::uuid7(), 'slug' => $slug, 'label_ar' => $label, 'individual_only' => $individualOnly, 'created_at' => now(), 'updated_at' => now()]);
            }

            $permission = DB::table('permissions')->where('slug', $slug)->value('id');

            foreach (DB::table('roles')->whereIn('slug', $roles)->pluck('id') as $role) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permission, 'role_id' => $role]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_staff_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
