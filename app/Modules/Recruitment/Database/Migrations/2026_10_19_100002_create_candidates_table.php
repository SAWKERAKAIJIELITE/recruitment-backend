<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The person (D-024). Only facts that never change between two applications live here; everything
 * declared at application time (residence, contact, education, availability, health) is on applications.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('national_number', 20)->unique();
            // $table->string('first_name', 15);
            // $table->string('last_name', 15);
            $table->string('full_name', 150);
            $table->string('name_search_key', 200)->index();     // ArabicText::searchKey(full_name)
            $table->string('mother_name', 150);
            $table->date('birth_date');
            $table->foreignId('birth_place_id')->constrained('districts')->restrictOnDelete();
            // $table->foreignId('residence_district')->constrained('districts')->restrictOnDelete();
            // $table->foreignId('residence_area')->constrained('areas')->restrictOnDelete();
            // $table->text('residence_details')->nullable();
            // A deactivated person cannot be nominated (D-049); who did it, when and why.
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignUuid('deactivated_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->text('deactivation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_national_number_format CHECK (national_number ~ '^[0-9]{11,20}$')");
        // Neither ASCII nor Arabic-Indic digits in a name.
        // DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_first_name_format CHECK (first_name !~ '[0-9]' AND LENGTH(first_name) >= 3)");
        // DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_last_name_format CHECK (last_name !~ '[0-9]' AND LENGTH(last_name) >= 3)");
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_full_name_format CHECK (full_name !~ '[0-9٠-٩۰-۹]' AND char_length(btrim(full_name)) >= 3)");
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_mother_name_format CHECK (mother_name !~ '[0-9٠-٩۰-۹]' AND char_length(btrim(mother_name)) >= 3)");
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_birth_date_range CHECK (birth_date > DATE '1950-01-01')");
        // Active: no deactivation data at all. Inactive: when, who and a real reason, all three (D-049).
        DB::statement(<<<'SQL'
            ALTER TABLE candidates ADD CONSTRAINT candidates_deactivation_explained CHECK (
                (is_active AND deactivated_at IS NULL AND deactivated_by IS NULL AND deactivation_reason IS NULL)
                OR (NOT is_active AND deactivated_at IS NOT NULL AND deactivated_by IS NOT NULL AND btrim(coalesce(deactivation_reason, '')) <> '')
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
