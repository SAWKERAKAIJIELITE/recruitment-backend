<?php

use App\Modules\Recruitment\Support\StatusEventCauseColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exam sessions and attempts (analysis doc §06, §07, §17; D-056, D-073 to D-075).
 *
 * A session sits one published plan version at a time and place. Its questions are drawn once from the bank and
 * frozen; every candidate gets the same questions in their own order. Candidates are booked into it (applications
 * awaiting their exam) and log in with their national number and the session's password. A candidate may start
 * from the session's start until the late window closes; every attempt ends with the session (start + duration +
 * any extension for everyone), unless that attempt alone was given more time. Not started when the late window
 * closes: absent. Every saved answer is kept; parts marked by hand get their marks per attempt; once the online part
 * is closed and every hand mark entered, the result is decided and moves the application (the booking is the cause
 * of that event). Nothing here is deleted, except a session nobody was ever booked into.
 */
return new class extends Migration
{
    private const BOOKING_STATUSES = ['booked', 'cancelled', 'absent', 'attended'];

    private const ATTEMPT_STATUSES = ['in_progress', 'submitted'];

    private const CLOSED_BY = ['candidate', 'timeout'];

    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_version_id')->constrained('exam_plan_versions')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->string('location', 300);
            $table->unsignedSmallInteger('duration_minutes');          // the plan's, copied: the session's own length
            $table->unsignedSmallInteger('late_minutes');              // starting allowed until starts_at + late_minutes
            $table->unsignedInteger('extra_minutes')->default(0);      // extensions for everyone, added up
            $table->string('password');                                // the session's password, hashed
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('starts_at');
        });

        // A late window of at least a minute: with none, nobody could ever start (the start is already too late).
        DB::statement('ALTER TABLE exam_sessions ADD CONSTRAINT exam_sessions_timing CHECK (duration_minutes > 0 AND late_minutes > 0 AND late_minutes < duration_minutes)');

        Schema::create('exam_session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('exam_sessions')->restrictOnDelete();
            $table->unsignedSmallInteger('position');                  // the session's own order; attempts reorder it
            $table->foreignId('question_version_id')->constrained('exam_question_versions')->restrictOnDelete();
            // The plan item it was drawn for: a subject scored as a whole, or one of its exam parts.
            $table->foreignId('plan_subject_id')->constrained('exam_plan_subjects')->restrictOnDelete();
            $table->foreignId('plan_part_id')->nullable()->constrained('exam_plan_subject_parts')->restrictOnDelete();

            $table->unique(['session_id', 'position']);
            $table->unique(['session_id', 'question_version_id']);
            $table->unique(['id', 'session_id']);                       // target of the attempt items' composite key
        });

        Schema::create('exam_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('exam_sessions')->restrictOnDelete();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->unsignedSmallInteger('round');                     // 1, then 2 after an accepted excuse, ...
            $table->enum('status', self::BOOKING_STATUSES)->default('booked');
            // When the application moved because of it (absence, or the result); until then it is undecided.
            $table->timestampTz('decided_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('staff_users')->restrictOnDelete();   // null: by the system
            $table->text('cancel_reason')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['application_id', 'round']);
            $table->unique(['id', 'session_id']);                       // target of the attempt's composite key
            $table->index(['session_id', 'status']);
        });

        DB::statement('ALTER TABLE exam_bookings ADD CONSTRAINT exam_bookings_round_positive CHECK (round > 0)');
        DB::statement("ALTER TABLE exam_bookings ADD CONSTRAINT exam_bookings_cancelled CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL) AND (cancelled_at IS NULL OR btrim(coalesce(cancel_reason, '')) <> ''))");
        DB::statement("ALTER TABLE exam_bookings ADD CONSTRAINT exam_bookings_decided CHECK ((status IN ('booked', 'cancelled')) <= (decided_at IS NULL) AND (status <> 'absent' OR decided_at IS NOT NULL))");
        // One undecided booking at a time per application.
        DB::statement("CREATE UNIQUE INDEX exam_bookings_one_open ON exam_bookings (application_id) WHERE status IN ('booked', 'attended') AND decided_at IS NULL");

        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('booking_id')->unique();                       // one attempt per booking, never reopened
            $table->uuid('session_id');
            $table->enum('status', self::ATTEMPT_STATUSES)->default('in_progress');
            $table->timestampTz('started_at');
            $table->timestampTz('deadline_at');                         // the server's deadline (the session's end, moved by extensions)
            $table->unsignedInteger('extra_minutes')->default(0);       // given to this attempt alone
            $table->timestampTz('submitted_at')->nullable();
            $table->enum('closed_by', self::CLOSED_BY)->nullable();
            $table->string('start_ip', 45)->nullable();
            $table->timestampsTz();

            $table->foreign(['booking_id', 'session_id'])->references(['id', 'session_id'])->on('exam_bookings')->restrictOnDelete();
            $table->unique(['id', 'session_id']);
            $table->index(['session_id', 'status']);
        });

        DB::statement("ALTER TABLE exam_attempts ADD CONSTRAINT exam_attempts_closed CHECK ((status = 'submitted') = (submitted_at IS NOT NULL) AND (submitted_at IS NULL) = (closed_by IS NULL) AND (submitted_at IS NULL OR (submitted_at >= started_at AND submitted_at <= deadline_at)) AND deadline_at > started_at)");

        Schema::create('exam_attempt_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('attempt_id');
            $table->uuid('session_id');
            $table->unsignedSmallInteger('position');                  // where this candidate sees it (1 ...)
            $table->unsignedBigInteger('session_question_id');
            $table->unsignedSmallInteger('selected_option')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->boolean('is_correct')->nullable();                  // set when the attempt closes

            // The question belongs to the attempt's session.
            $table->foreign(['attempt_id', 'session_id'])->references(['id', 'session_id'])->on('exam_attempts')->restrictOnDelete();
            $table->foreign(['session_question_id', 'session_id'])->references(['id', 'session_id'])->on('exam_session_questions')->restrictOnDelete();
            $table->unique(['attempt_id', 'position']);
            $table->unique(['attempt_id', 'session_question_id']);
        });

        Schema::create('exam_answer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_item_id')->constrained('exam_attempt_items')->restrictOnDelete();
            $table->unsignedSmallInteger('selected_option')->nullable();   // null: the answer was cleared
            $table->string('ip', 45)->nullable();
            $table->timestampTz('saved_at');
        });

        Schema::create('exam_session_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('session_id')->constrained('exam_sessions')->restrictOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->text('reason');
            $table->foreignUuid('extended_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });

        // Every move of an attempt's deadline: its own extension, or its share of one for everyone.
        Schema::create('exam_attempt_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('attempt_id')->constrained('exam_attempts')->restrictOnDelete();
            $table->foreignId('session_extension_id')->nullable()->constrained('exam_session_extensions')->restrictOnDelete();
            $table->unsignedSmallInteger('minutes');
            $table->text('reason');
            $table->timestampTz('previous_deadline');
            $table->timestampTz('new_deadline');
            $table->foreignUuid('extended_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });

        foreach (['exam_session_extensions', 'exam_attempt_extensions'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_explained CHECK (minutes > 0 AND btrim(reason) <> '')");
        }
        DB::statement('ALTER TABLE exam_attempt_extensions ADD CONSTRAINT exam_attempt_extensions_moved CHECK (new_deadline > previous_deadline)');

        // A hand-marked part (التجويد) of one attempt; a correction is a new revision with a reason.
        Schema::create('exam_manual_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('attempt_id')->constrained('exam_attempts')->restrictOnDelete();
            $table->foreignId('plan_part_id')->constrained('exam_plan_subject_parts')->restrictOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->decimal('mark', 7, 2);
            $table->text('reason')->nullable();
            $table->foreignUuid('entered_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['attempt_id', 'plan_part_id', 'revision']);
        });

        DB::statement("ALTER TABLE exam_manual_marks ADD CONSTRAINT exam_manual_marks_values CHECK (mark >= 0 AND revision > 0 AND (revision = 1) = (reason IS NULL) AND (reason IS NULL OR btrim(reason) <> ''))");

        // The decided result of an attempt: the engine's output under the plan's pass policy. A correction of a hand
        // mark adds a revision.
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('attempt_id')->constrained('exam_attempts')->restrictOnDelete();
            $table->unsignedSmallInteger('revision');
            $table->foreignId('pass_policy_id')->constrained('exam_pass_policies')->restrictOnDelete();
            $table->boolean('passed');
            $table->decimal('average', 7, 2);                           // for display; the decision used exact values
            $table->jsonb('outcome');                                   // subjects, bands, reasons, tolerated subjects
            $table->text('reason')->nullable();                         // why it was revised
            $table->foreignUuid('decided_by')->nullable()->constrained('staff_users')->restrictOnDelete();   // null: the system
            $table->timestampTz('created_at');

            $table->unique(['attempt_id', 'revision']);
        });

        DB::statement("ALTER TABLE exam_results ADD CONSTRAINT exam_results_revision CHECK (revision > 0 AND (revision = 1) = (reason IS NULL) AND (reason IS NULL OR btrim(reason) <> ''))");

        foreach (['exam_answer_revisions', 'exam_session_extensions', 'exam_attempt_extensions', 'exam_manual_marks', 'exam_results'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()");
        }

        $this->sessionTriggers();
        $this->bookingTriggers();
        $this->attemptTriggers();
        $this->markTriggers();

        // The exam behind a status change (D-038): the booking (an absence has no attempt).
        StatusEventCauseColumns::add('exam_booking_id', 'exam_bookings');

        // A candidate's token belongs to one booking: the session they logged in for.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('exam_booking_id')->nullable()->constrained('exam_bookings')->restrictOnDelete();
        });
    }

    /**
     * A session sits a published plan. Once an attempt started: its plan, start, length and late window stay, an
     * extension for everyone only adds time, and its drawn questions stay as they are.
     */
    private function sessionTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_sessions_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'INSERT' OR NEW.plan_version_id <> OLD.plan_version_id THEN
                        IF NOT EXISTS (SELECT 1 FROM exam_plan_versions WHERE id = NEW.plan_version_id AND status = 'published') THEN
                            RAISE EXCEPTION 'An exam session sits a published plan version' USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.extra_minutes < OLD.extra_minutes THEN
                            RAISE EXCEPTION 'An extension is never taken back' USING ERRCODE = 'check_violation';
                        END IF;

                        IF EXISTS (SELECT 1 FROM exam_attempts WHERE session_id = NEW.id) AND (
                            NEW.plan_version_id <> OLD.plan_version_id OR NEW.starts_at <> OLD.starts_at
                            OR NEW.duration_minutes <> OLD.duration_minutes OR NEW.late_minutes <> OLD.late_minutes
                        ) THEN
                            RAISE EXCEPTION 'A session that has started keeps its plan, start, length and late window' USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_sessions_guard BEFORE INSERT OR UPDATE ON exam_sessions FOR EACH ROW EXECUTE FUNCTION exam_sessions_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_session_questions_frozen() RETURNS trigger AS
            $$
                DECLARE
                    session uuid := CASE TG_OP WHEN 'DELETE' THEN OLD.session_id ELSE NEW.session_id END;
                BEGIN
                    IF TG_OP = 'UPDATE' THEN
                        RAISE EXCEPTION 'A drawn question is replaced by drawing again, never changed' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM exam_attempts WHERE session_id = session) THEN
                        RAISE EXCEPTION 'The questions of a session that has started are frozen' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_session_questions_frozen BEFORE INSERT OR UPDATE OR DELETE ON exam_session_questions FOR EACH ROW EXECUTE FUNCTION exam_session_questions_frozen()');
    }

    /** A booking is never deleted and moves only forward: booked → cancelled | absent | attended; decided once. */
    private function bookingTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_bookings_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Exam bookings are cancelled, never deleted' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.session_id <> OLD.session_id OR NEW.application_id <> OLD.application_id OR NEW.round <> OLD.round THEN
                        RAISE EXCEPTION 'A booking keeps its session, application and round' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.status <> OLD.status AND OLD.status <> 'booked' THEN
                        RAISE EXCEPTION 'A booking that is %, stays so', OLD.status USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.decided_at IS NOT NULL AND NEW.decided_at IS DISTINCT FROM OLD.decided_at THEN
                        RAISE EXCEPTION 'A booking is decided once' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_bookings_guard BEFORE UPDATE OR DELETE ON exam_bookings FOR EACH ROW EXECUTE FUNCTION exam_bookings_guard()');
    }

    /**
     * An attempt is never deleted or reopened; its deadline only moves later. Its answers change only while it is
     * in progress; when it closes, each item is marked right or wrong once.
     */
    private function attemptTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_attempts_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Exam attempts are never deleted' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.status = 'submitted' AND (NEW.status <> 'submitted' OR NEW.submitted_at <> OLD.submitted_at OR NEW.closed_by <> OLD.closed_by
                        OR NEW.deadline_at <> OLD.deadline_at OR NEW.extra_minutes <> OLD.extra_minutes OR NEW.start_ip IS DISTINCT FROM OLD.start_ip) THEN
                        RAISE EXCEPTION 'A closed attempt is never reopened or changed' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.deadline_at < OLD.deadline_at OR NEW.started_at <> OLD.started_at OR NEW.booking_id <> OLD.booking_id THEN
                        RAISE EXCEPTION 'An attempt keeps its start and booking, and its deadline only moves later' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_attempts_guard BEFORE UPDATE OR DELETE ON exam_attempts FOR EACH ROW EXECUTE FUNCTION exam_attempts_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_attempt_items_guard() RETURNS trigger AS
            $$
                DECLARE
                    attempt_status text;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'The questions of an attempt are never removed' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.position <> OLD.position OR NEW.session_question_id <> OLD.session_question_id OR NEW.attempt_id <> OLD.attempt_id THEN
                        RAISE EXCEPTION 'An attempt keeps the order of its questions' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    SELECT status INTO attempt_status FROM exam_attempts WHERE id = NEW.attempt_id;

                    IF (NEW.selected_option IS DISTINCT FROM OLD.selected_option OR NEW.answered_at IS DISTINCT FROM OLD.answered_at) AND attempt_status <> 'in_progress' THEN
                        RAISE EXCEPTION 'The answers of a closed attempt do not change' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.is_correct IS DISTINCT FROM OLD.is_correct AND (attempt_status <> 'submitted' OR OLD.is_correct IS NOT NULL) THEN
                        RAISE EXCEPTION 'An answer is marked once, when its attempt closes' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_attempt_items_guard BEFORE UPDATE OR DELETE ON exam_attempt_items FOR EACH ROW EXECUTE FUNCTION exam_attempt_items_guard()');
    }

    /** A hand mark is for a manual part of the attempt's plan, within that part's scale. */
    private function markTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_manual_marks_valid() RETURNS trigger AS
            $$
                DECLARE
                    part RECORD;
                BEGIN
                    SELECT p.assessment, p.max_score INTO part
                    FROM exam_plan_subject_parts p
                    JOIN exam_sessions s ON s.plan_version_id = p.plan_version_id
                    JOIN exam_attempts a ON a.session_id = s.id
                    WHERE p.id = NEW.plan_part_id AND a.id = NEW.attempt_id;

                    IF NOT FOUND OR part.assessment <> 'manual' THEN
                        RAISE EXCEPTION 'A hand mark is for a manual part of the attempt''s plan' USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.mark > part.max_score THEN
                        RAISE EXCEPTION 'A mark cannot exceed its part''s maximum (%)', part.max_score USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_manual_marks_valid BEFORE INSERT ON exam_manual_marks FOR EACH ROW EXECUTE FUNCTION exam_manual_marks_valid()');
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_booking_id');
        });
        StatusEventCauseColumns::remove('exam_booking_id');

        foreach (['exam_results', 'exam_manual_marks', 'exam_attempt_extensions', 'exam_session_extensions', 'exam_answer_revisions', 'exam_attempt_items', 'exam_attempts', 'exam_bookings', 'exam_session_questions', 'exam_sessions'] as $table) {
            Schema::dropIfExists($table);
        }

        foreach (['exam_sessions_guard', 'exam_session_questions_frozen', 'exam_bookings_guard', 'exam_attempts_guard', 'exam_attempt_items_guard', 'exam_manual_marks_valid'] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
