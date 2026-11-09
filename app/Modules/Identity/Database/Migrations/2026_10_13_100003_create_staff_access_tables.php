<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every staff user holds one role: an office (Recruitment, Exams and Interviews, Contracts, Documenting), the general
 * manager or the system administrator (D-085). There are no personal grants or denials: a user's permissions are
 * their role's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->foreignUuid('role_id')->nullable()->index()->constrained('roles')->restrictOnDelete();
            $table->foreignUuid('role_assigned_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('role_assigned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_assigned_by');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('role_assigned_at');
        });
    }
};
