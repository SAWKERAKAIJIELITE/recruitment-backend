<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    // private function createQuestionTable(string $tableName): void
    // {
    //     Schema::create($tableName, function (Blueprint $table) {
    //         $table->id();
    //         $table->string('name', 20);
    //     });
    // }

    public function up(): void
    {
        // foreach ([
        //     'districts',
        //     'areas',
        // ] as $tableName) {
        //     $this->createQuestionTable($tableName);
        // }

        Schema::create('candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('national_number', 20)->unique();
            // $table->string('first_name', 15);
            // $table->string('last_name', 15);
            $table->string('full_name', 150);
            $table->string('name_search_key', 200)->index();      // ArabicText::searchKey(full_name)
            $table->string('mother_name', 150);
            $table->date('birth_date');
            $table->foreignId('birth_place')->constrained('districts')->restrictOnDelete();
            $table->foreignId('residence_district')->constrained('districts')->restrictOnDelete();
            $table->foreignId('residence_area')->constrained('areas')->restrictOnDelete();
            $table->text('residence_details')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_national_number_format CHECK (national_number ~ '^[0-9]{11}$')");
        // DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_first_name_format CHECK (first_name !~ '[0-9]' AND LENGTH(first_name) >= 3)");
        // DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_last_name_format CHECK (last_name !~ '[0-9]' AND LENGTH(last_name) >= 3)");
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_full_name_format CHECK (full_name !~ '[0-9]' AND LENGTH(full_name) >= 3)");
        DB::statement("ALTER TABLE candidates ADD CONSTRAINT candidates_mother_name_format CHECK (mother_name !~ '[0-9]' AND LENGTH(mother_name) >= 3)");
    }

    public function down(): void
    {
        // foreach ([
        //     'districts',
        //     'areas',
        // ] as $tableName) {
        //     Schema::dropIfExists($tableName);
        // }
        Schema::dropIfExists('candidates');
    }
};
