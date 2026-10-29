<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pass rules as versioned data (analysis doc §04: "the pass policy version is stored with every result, so
 * past results do not change when the policy changes"; D-052).
 *
 * A policy is never changed or removed: a new rule is a new version. Version 1 holds the settled rules and is
 * part of the schema (every environment needs it): pass mark 60, one subject tolerated from 58 up to 60, and an
 * average strictly above 70.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_pass_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->decimal('subject_pass_mark', 5, 2);        // a subject passes at this score or more
            $table->decimal('tolerance_floor', 5, 2);          // below the pass mark but at least this: tolerated
            $table->unsignedSmallInteger('max_tolerated_subjects');
            $table->decimal('average_must_exceed', 5, 2);     // strictly greater
            $table->text('description');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE exam_pass_policies ADD CONSTRAINT exam_pass_policies_marks CHECK (tolerance_floor >= 0 AND tolerance_floor <= subject_pass_mark AND subject_pass_mark <= 100 AND average_must_exceed >= 0 AND average_must_exceed < 100)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_row_modification() RETURNS trigger AS
            $$
                BEGIN
                    RAISE EXCEPTION '% on % is not allowed: the table is append-only', TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'integrity_constraint_violation';
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_pass_policies_append_only BEFORE UPDATE OR DELETE ON exam_pass_policies FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()');
        DB::unprepared('CREATE TRIGGER exam_pass_policies_no_truncate BEFORE TRUNCATE ON exam_pass_policies FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_modification()');

        DB::table('exam_pass_policies')->insert([
            'version' => 1,
            'subject_pass_mark' => '60.00',
            'tolerance_floor' => '58.00',
            'max_tolerated_subjects' => 1,
            'average_must_exceed' => '70.00',
            'description' => 'كل مادة 60% فأكثر؛ يُتسامح بمادة واحدة من 58% إلى أقل من 60%؛ المعدل العام أكبر من 70% (وثيقة التحليل §04).',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_pass_policies');   // drops its triggers; the shared function stays
    }
};
