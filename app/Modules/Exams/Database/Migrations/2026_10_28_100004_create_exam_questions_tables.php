<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The question bank (analysis doc §05 "Question", "Question Version"; diagram 08 "Question"; D-055).
 *
 * A question belongs to a subject, optionally to one of its parts, and has a difficulty. What it says (prompt,
 * options, correct option) lives in versions: correcting a question adds a version, and a version is never
 * changed or removed (append-only), because an exam session freezes the versions it uses. Questions are archived,
 * never deleted. Single correct answer among 2 to 6 options, kept in the order they were written (options are not
 * shuffled, D-056).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('exam_subjects')->restrictOnDelete();
            $table->unsignedBigInteger('part_id')->nullable();
            $table->string('difficulty', 10);
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // A part of that subject (MATCH SIMPLE: no check when part_id is null).
            $table->foreign(['subject_id', 'part_id'], 'exam_questions_part_of_subject')
                ->references(['subject_id', 'id'])->on('exam_subject_parts')->restrictOnDelete();
            $table->index(['subject_id', 'part_id', 'difficulty']);
        });

        DB::statement("ALTER TABLE exam_questions ADD CONSTRAINT exam_questions_difficulty CHECK (difficulty IN ('easy', 'medium', 'hard'))");

        Schema::create('exam_question_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('exam_questions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('prompt');
            $table->jsonb('options');                  // ["…", "…", …] in display order
            $table->unsignedSmallInteger('correct_option');   // index into options, from 0
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['question_id', 'version']);
        });

        DB::statement("ALTER TABLE exam_question_versions ADD CONSTRAINT exam_question_versions_prompt CHECK (btrim(prompt) <> '')");
        DB::statement("ALTER TABLE exam_question_versions ADD CONSTRAINT exam_question_versions_options CHECK (jsonb_typeof(options) = 'array' AND jsonb_array_length(options) BETWEEN 2 AND 6)");
        DB::statement('ALTER TABLE exam_question_versions ADD CONSTRAINT exam_question_versions_correct_option CHECK (correct_option < jsonb_array_length(options))');
        DB::statement('ALTER TABLE exam_question_versions ADD CONSTRAINT exam_question_versions_version_positive CHECK (version > 0)');

        DB::unprepared('CREATE TRIGGER exam_question_versions_append_only BEFORE UPDATE OR DELETE ON exam_question_versions FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()');
        DB::unprepared('CREATE TRIGGER exam_question_versions_no_truncate BEFORE TRUNCATE ON exam_question_versions FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_question_versions');
        Schema::dropIfExists('exam_questions');
    }
};
