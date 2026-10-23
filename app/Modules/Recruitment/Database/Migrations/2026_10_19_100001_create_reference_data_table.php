<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The managed lists (the hidden "_Lists" sheet of the Excel template).
 *
 * Same shape on purpose, but separate tables so every foreign key stays typed and checked (D-024).
 * Keys are bigint auto-increment, not UUID: the lists are small and readable by every staff user,
 * so a guess-proof key protects nothing (D-034). Entries are archived (soft deleted), never removed.
 */
return new class extends Migration {
    /** Lists whose names are unique across the whole list. */
    private const FLAT_LISTS = [
        'districts',                  // المحافظات
        'nominating_entities',        // الجهات المرشِّحة
        'job_positions',              // المسميات الوظيفية
        'application_categories',     // الفئات
        'academic_qualifications',    // المؤهلات العلمية
        'specializations',            // الاختصاصات
        'marital_statuses',           // الحالات الاجتماعية
        'health_conditions',          // الحالات الصحية
    ];

    public function up(): void
    {
        foreach (self::FLAT_LISTS as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name_ar', 150);
                // Normalized spelling (ArabicText::searchKey): two spellings of one name collide here.
                $table->string('name_key', 200)->unique();
                $table->timestampsTz();
                $table->softDeletesTz();   // archived, never deleted: applications keep pointing at them
            });
        }

        // Areas (neighbourhoods) live inside a district. The same area name may exist in two districts,
        // so the name is unique per district only.
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('districts')->restrictOnDelete();
            $table->string('name_ar', 150);
            $table->string('name_key', 200);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['district_id', 'name_key']);
            // Target of the composite foreign key on applications: an application's area must belong
            // to the application's district.
            $table->unique(['district_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');

        foreach (array_reverse(self::FLAT_LISTS) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
