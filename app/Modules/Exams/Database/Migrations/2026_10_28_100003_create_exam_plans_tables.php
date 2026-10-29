<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam plans (analysis doc §05 "Exam Plan Version"; diagram 08 "Exam BluePrint", "Subject_Job").
 *
 * A job position's plan says which subjects are examined, how many easy, medium and hard questions each subject
 * (or part) gets, the parts' weights, which parts are marked by hand, the duration of the single session and the
 * pass policy (D-053, D-054). Plans are versioned: a draft is edited freely; publishing freezes it, and the
 * previously published version of that job position is retired in the same transaction. At most one version per
 * job position is published, and at most one is a draft (partial unique indexes). The database refuses any change
 * to a published or retired version and to its subjects and parts (triggers), because sessions and results point
 * at it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('exam_plan_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('job_position_id')->constrained('job_positions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('duration_minutes')->nullable();   // the exam session (all online questions)
            $table->foreignId('pass_policy_id')->constrained('exam_pass_policies')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['job_position_id', 'version']);
        });

        DB::statement("ALTER TABLE exam_plan_versions ADD CONSTRAINT exam_plan_versions_status CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement('ALTER TABLE exam_plan_versions ADD CONSTRAINT exam_plan_versions_version_positive CHECK (version > 0)');
        DB::statement('ALTER TABLE exam_plan_versions ADD CONSTRAINT exam_plan_versions_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes > 0)');
        // Who published it and when, exactly for published and retired versions; retired ones say when.
        DB::statement("ALTER TABLE exam_plan_versions ADD CONSTRAINT exam_plan_versions_publication CHECK ((status = 'draft') = (published_at IS NULL AND published_by IS NULL) AND (status = 'retired') = (retired_at IS NOT NULL))");
        // A published plan has its session duration.
        DB::statement("ALTER TABLE exam_plan_versions ADD CONSTRAINT exam_plan_versions_published_has_duration CHECK (status = 'draft' OR duration_minutes IS NOT NULL)");
        DB::statement("CREATE UNIQUE INDEX exam_plan_versions_one_published ON exam_plan_versions (job_position_id) WHERE status = 'published'");
        // One draft at a time per job position: the next version is prepared in one place.
        DB::statement("CREATE UNIQUE INDEX exam_plan_versions_one_draft ON exam_plan_versions (job_position_id) WHERE status = 'draft'");

        Schema::create('exam_plan_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('plan_version_id')->constrained('exam_plan_versions')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('exam_subjects')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            // A subject scored as a whole: its questions per difficulty. Null when scored from parts.
            $table->unsignedSmallInteger('easy_count')->nullable();
            $table->unsignedSmallInteger('medium_count')->nullable();
            $table->unsignedSmallInteger('hard_count')->nullable();

            $table->unique(['plan_version_id', 'subject_id']);
            $table->unique(['id', 'plan_version_id', 'subject_id']);        // target of the parts' composite key
        });

        // Every difficulty at least once, or (scored from parts, or a draft not filled in yet) none (D-054).
        // coalesce: a comparison with NULL is NULL, and a CHECK that is NULL passes.
        DB::statement('ALTER TABLE exam_plan_subjects ADD CONSTRAINT exam_plan_subjects_difficulties CHECK ((easy_count IS NULL AND medium_count IS NULL AND hard_count IS NULL) OR (coalesce(easy_count, 0) > 0 AND coalesce(medium_count, 0) > 0 AND coalesce(hard_count, 0) > 0))');

        Schema::create('exam_plan_subject_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_subject_id');
            $table->uuid('plan_version_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('part_id');
            $table->unsignedSmallInteger('position');
            $table->decimal('weight', 7, 2);
            $table->string('assessment', 10);                     // exam | manual
            $table->unsignedSmallInteger('easy_count')->nullable();
            $table->unsignedSmallInteger('medium_count')->nullable();
            $table->unsignedSmallInteger('hard_count')->nullable();
            $table->decimal('max_score', 7, 2)->nullable();        // manual: the scale staff mark it out of

            $table->unique(['plan_subject_id', 'part_id']);
            // The plan subject it belongs to (same version, same subject), and a part of that subject.
            $table->foreign(['plan_subject_id', 'plan_version_id', 'subject_id'], 'exam_plan_subject_parts_plan_subject')
                ->references(['id', 'plan_version_id', 'subject_id'])->on('exam_plan_subjects')->cascadeOnDelete();
            $table->foreign(['subject_id', 'part_id'], 'exam_plan_subject_parts_part_of_subject')
                ->references(['subject_id', 'id'])->on('exam_subject_parts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE exam_plan_subject_parts ADD CONSTRAINT exam_plan_subject_parts_weight_positive CHECK (weight > 0)');
        // An exam part has questions of every difficulty (or none yet in a draft) and no manual scale; a manual
        // part has a positive scale and no questions.
        DB::statement(<<<'SQL'
            ALTER TABLE exam_plan_subject_parts ADD CONSTRAINT exam_plan_subject_parts_assessment CHECK (
                (assessment = 'exam' AND max_score IS NULL
                    AND ((easy_count IS NULL AND medium_count IS NULL AND hard_count IS NULL)
                        OR (coalesce(easy_count, 0) > 0 AND coalesce(medium_count, 0) > 0 AND coalesce(hard_count, 0) > 0)))
                OR (assessment = 'manual' AND coalesce(max_score, 0) > 0 AND easy_count IS NULL AND medium_count IS NULL AND hard_count IS NULL)
            )
            SQL);

        $this->createTriggers();
    }

    private function createTriggers(): void
    {
        // A version that is no longer a draft never changes, except: published -> retired (and its date).
        // Only drafts are deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_plan_versions_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.status <> 'draft' THEN
                            RAISE EXCEPTION 'Exam plan version % is %: only drafts are deleted', OLD.id, OLD.status
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                        RETURN OLD;
                    END IF;

                    IF OLD.status = 'draft' AND NEW.status = 'retired' THEN
                        RAISE EXCEPTION 'A draft exam plan is published before it is retired' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.status <> 'draft' AND NOT (
                        OLD.status = 'published' AND NEW.status = 'retired'
                        AND (to_jsonb(NEW) - ARRAY['status', 'retired_at', 'updated_at']) = (to_jsonb(OLD) - ARRAY['status', 'retired_at', 'updated_at'])
                    ) THEN
                        RAISE EXCEPTION 'Exam plan version % is % and cannot change', OLD.id, OLD.status
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_plan_versions_frozen BEFORE UPDATE OR DELETE ON exam_plan_versions FOR EACH ROW EXECUTE FUNCTION exam_plan_versions_guard()');

        // The subjects and parts of a version change only while it is a draft. When the version itself is being
        // deleted (only drafts can be), its rows go with it.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_plan_content_guard() RETURNS trigger AS
            $$
                DECLARE
                    version_status text;
                    affected uuid;
                BEGIN
                    FOREACH affected IN ARRAY (CASE TG_OP
                        WHEN 'INSERT' THEN ARRAY[NEW.plan_version_id]
                        WHEN 'DELETE' THEN ARRAY[OLD.plan_version_id]
                        ELSE ARRAY[OLD.plan_version_id, NEW.plan_version_id] END)
                    LOOP
                        SELECT status INTO version_status FROM exam_plan_versions WHERE id = affected;

                        IF FOUND AND version_status <> 'draft' THEN
                            RAISE EXCEPTION 'Exam plan version % is %: its subjects and parts cannot change', affected, version_status
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                    END LOOP;

                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_plan_subjects_frozen BEFORE INSERT OR UPDATE OR DELETE ON exam_plan_subjects FOR EACH ROW EXECUTE FUNCTION exam_plan_content_guard()');
        DB::unprepared('CREATE TRIGGER exam_plan_subject_parts_frozen BEFORE INSERT OR UPDATE OR DELETE ON exam_plan_subject_parts FOR EACH ROW EXECUTE FUNCTION exam_plan_content_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_plan_subject_parts');
        Schema::dropIfExists('exam_plan_subjects');
        Schema::dropIfExists('exam_plan_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS exam_plan_content_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS exam_plan_versions_guard()');
    }
};
