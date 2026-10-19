<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label', 100);
            $table->text('public_key');
            $table->char('fingerprint', 64)->unique();          // sha256 hex of the SPKI DER bytes
            $table->string('reported_mac', 64)->nullable();     // informational only (D-017)
            $table->string('os_info')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignUuid('approved_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE staff_devices ADD CONSTRAINT staff_devices_status_check CHECK (status IN ('pending', 'approved', 'revoked'))");
        DB::statement("ALTER TABLE staff_devices ADD CONSTRAINT staff_devices_approved_has_time CHECK (status <> 'approved' OR approved_at IS NOT NULL)");
        DB::statement("ALTER TABLE staff_devices ADD CONSTRAINT staff_devices_revoked_has_time CHECK (status <> 'revoked' OR revoked_at IS NOT NULL)");

        // Which staff users may log in from which device (OD-1)
        Schema::create('device_assignments', function (Blueprint $table) {
            $table->foreignUuid('staff_device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('staff_user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
            $table->primary(['staff_device_id', 'staff_user_id']);
        });

        Schema::create('device_login_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('staff_device_id')->constrained()->restrictOnDelete();
            $table->string('challenge', 64);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_login_challenges');
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('staff_devices');
    }
};
