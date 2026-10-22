<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 50)->unique();
            $table->string('label_ar', 100);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 100)->unique();
            $table->string('label_ar', 150);
            // true = may only be granted to individual users, never attached to a role (the ✓* cells)
            $table->boolean('individual_only')->default(false);
            $table->timestampsTz();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUuid('permission_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('role_id')->constrained()->restrictOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
