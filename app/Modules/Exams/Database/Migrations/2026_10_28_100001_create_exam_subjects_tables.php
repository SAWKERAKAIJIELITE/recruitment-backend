<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects and their parts (analysis doc §05; diagram 08 "Subject" and "Subordinate Subject").
 *
 * A subject is scored as a whole, or from its parts (القرآن الكريم = التلاوة + التجويد). A part is assessed in the
 * online exam or marked by hand (التجويد is oral, the same day, and its mark is entered by staff; D-054). Its weight
 * and assessment here are defaults; the plan version that uses the part stores its own copy, so changing a
 * default never changes a published plan (D-053). Both are managed like the lists: integer keys, normalized unique names, archived
 * instead of deleted (D-034).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar', 150);
            $table->string('name_key', 200)->unique();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('exam_subject_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('exam_subjects')->restrictOnDelete();
            $table->string('name_ar', 150);
            $table->string('name_key', 200);
            $table->decimal('default_weight', 7, 2);
            $table->string('default_assessment', 10)->default('exam');   // exam | manual
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['subject_id', 'name_key']);
            $table->unique(['subject_id', 'id']);   // target of the plan's composite key: a part of that subject
        });

        DB::statement('ALTER TABLE exam_subject_parts ADD CONSTRAINT exam_subject_parts_weight_positive CHECK (default_weight > 0)');
        DB::statement("ALTER TABLE exam_subject_parts ADD CONSTRAINT exam_subject_parts_assessment CHECK (default_assessment IN ('exam', 'manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_subject_parts');
        Schema::dropIfExists('exam_subjects');
    }
};
