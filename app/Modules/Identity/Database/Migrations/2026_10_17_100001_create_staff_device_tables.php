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
            $table->text('public_key')->unique();  // PEM of the P-256 EC public key
            $table->char('fingerprint', 64)->unique();          // sha256 hex of the SPKI DER bytes
            $table->macAddress('reported_mac')
            // ->unique() // temporarily based on Frontend ask
            ;     // informational only (D-017)
            $table->string('os_info');
            // PostgreSQL has no ENUM from Laravel: this creates varchar + CHECK (staff_devices_status_check)
            $table->enum('status', ['pending', 'approved', 'revoked'])->default('pending');
            // The one staff user who owns this device. Set when the device is approved.
            $table->foreignUuid('staff_user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoke_reason')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
        });

        // DB::statement("ALTER TABLE staff_devices ADD CONSTRAINT staff_devices_status_check CHECK (status IN ('pending', 'approved', 'revoked'))");
        DB::statement(
            "ALTER TABLE staff_devices ADD CONSTRAINT staff_devices_approved_has_time
            CHECK (status <> 'approved' OR (approved_at IS NOT NULL AND staff_user_id IS NOT NULL))"
        );
        // Each staff user has at most ONE approved device (owner's rule). A revoked device frees the slot,
        // so a user whose laptop was lost can be given a new one.
        DB::statement("CREATE UNIQUE INDEX staff_devices_one_approved_per_user ON staff_devices (staff_user_id) WHERE status = 'approved'");
        DB::statement("ALTER TABLE staff_devices ADD CONSTRAINT staff_devices_revoked_has_time CHECK (status <> 'revoked' OR revoked_at IS NOT NULL)");

        // // Which staff users may log in from which device (OD-1)
        // Schema::create('device_assignments', function (Blueprint $table) {
        //     $table->foreignUuid('staff_device_id')->constrained()->restrictOnDelete();
        //     $table->foreignUuid('staff_user_id')->constrained()->restrictOnDelete();
        //     $table->foreignUuid('assigned_by')->index()->nullable()->constrained('staff_users')->restrictOnDelete();
        //     $table->timestampsTz();
        //     $table->primary(['staff_device_id', 'staff_user_id']);
        // });

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
        // Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('staff_devices');
    }
};
