<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** The managed lists. Same shape on purpose; separate tables so foreign keys stay typed and checked. */
    private const TABLES = [
        // 'health_conditions',
        'marital_status',
        'job_position',
        'academic_qualification',
        'specialization',
        'nominating_entities',
        'application_categories',
        'districts',
        'areas',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name_ar', 150);
                // Normalised spelling (ArabicText::searchKey): two spellings of one name collide here.
                $table->string('name_key', 200)->unique();
                $table->timestampsTz();
                $table->softDeletesTz();   // archived, never deleted: applications keep pointing at them
            });
        }
        Schema::create('health_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            // Normalised spelling (ArabicText::searchKey): two spellings of one name collide here.
            $table->string('name_key', 200)->unique();
            $table->text('health_details')->nullable();           // encrypted by the application
            $table->timestampsTz();
            $table->softDeletesTz();   // archived, never deleted: applications keep pointing at them
        });
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
        Schema::dropIfExists('health_conditions');
    }
};
