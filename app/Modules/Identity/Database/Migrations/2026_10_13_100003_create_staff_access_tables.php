<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('role_staff_user', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('staff_user_id')->constrained('staff_users')->restrictOnDelete();
            $table->primary(['role_id', 'staff_user_id']);
        });

        // Per-user override: is_granted = true adds a permission, false removes one the role gives.
        Schema::create('permission_staff_user', function (Blueprint $table) {
            $table->foreignUuid('staff_user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('permission_id')->constrained()->restrictOnDelete();
            $table->boolean('is_granted')->default(true);
            $table->foreignUuid('granted_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
            $table->primary(['staff_user_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_staff_user');
        Schema::dropIfExists('role_staff_user');
    }
};
