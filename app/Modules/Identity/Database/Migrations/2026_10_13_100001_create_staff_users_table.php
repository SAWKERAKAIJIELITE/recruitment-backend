<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('personal_number')->unique();
            // $table->string('type', 20);                       // staff | candidate
            $table->string('username', 50)->unique();        // stored lowercase
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('password');
            $table->boolean('is_active')->default(true)->index();
            // $table->boolean('must_change_password')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            // Deactivated by an administrator (D-079): when, who and why. An account created inactive has none.
            $table->timestampTz('deactivated_at')->nullable();
            $table->uuid('deactivated_by')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->timestampsTz();
        });

        // After the table exists: it refers to itself.
        Schema::table('staff_users', function (Blueprint $table) {
            $table->foreign('deactivated_by')->references('id')->on('staff_users')->restrictOnDelete();
        });

        // DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (type IN ('staff', 'candidate'))");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_username_format CHECK (username ~ '^[a-z._-]{3,50}$')");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_first_name_format CHECK (first_name !~ '[0-9]' AND LENGTH(first_name) >= 3)");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_last_name_format CHECK (last_name !~ '[0-9]' AND LENGTH(last_name) >= 3)");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_deactivation_explained CHECK ((deactivated_at IS NULL AND deactivated_by IS NULL AND deactivation_reason IS NULL) OR (NOT is_active AND deactivated_at IS NOT NULL AND deactivated_by IS NOT NULL AND btrim(coalesce(deactivation_reason, '')) <> ''))");

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();   // the name Laravel's database session handler writes
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
        Schema::dropIfExists('sessions');
    }
};
