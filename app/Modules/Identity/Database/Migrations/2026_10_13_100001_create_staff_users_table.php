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
            // $table->string('type', 20);                       // staff | candidate
            $table->string('username', 50)->unique();        // stored lowercase
            $table->string('first_name');
            $table->string('last_name');
            // $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });

        // DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (type IN ('staff', 'candidate'))");
        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_username_format CHECK (username ~ '^[a-z._-]{3,50}$')");
        // DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_first_name_format CHECK (first_name ~ '^[a-z0-9._-]{3,50}$')");
        // DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_last_name_format CHECK (last_name ~ '^[a-z0-9._-]{3,50}$')");

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('staff_user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
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
