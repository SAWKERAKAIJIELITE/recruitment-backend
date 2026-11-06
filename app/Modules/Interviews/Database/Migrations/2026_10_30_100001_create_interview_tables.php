<?php

use App\Modules\Recruitment\Support\StatusEventCauseColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interviews (analysis doc §07, §08; legacy sheet "المقابلات"; D-059, D-061).
 *
 * Every interview is booked into a sitting: a committee sitting, or a one-off sitting (one interviewer who sees the
 * candidates one by one; D-067). Both hold many candidates, each with their own time. The sitting holds the place,
 * the committee and its members (a member who is a staff account carries the account's name), so an interview
 * always has who interviews and where. Two sittings with the same
 * start, place and members are the same sitting entered twice: refused. The committee enters the result (passed, failed, absent) with its grades; the result
 * moves the application's status, with the interview as the cause of that status event. Rescheduling and every
 * version of the result are kept (append-only); interviews are never deleted.
 */
return new class extends Migration
{
    private const KINDS = ['committee', 'one_off'];

    private const STATUSES = ['scheduled', 'recorded', 'cancelled'];

    private const RESULTS = ['passed', 'failed', 'absent'];

    private const GRADES = ['excellent', 'very_good', 'good', 'acceptable', 'average', 'weak'];

    private const CAPACITIES = ['chair', 'member', 'secretary'];

    public function up(): void
    {
        Schema::create('interview_sittings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('kind', self::KINDS)->default('committee');   // one_off: a single interviewer (exactly one member)
            $table->timestampTz('starts_at');
            $table->string('location', 300);                           // place, or medium (video call, ...)
            $table->string('committee_name', 150)->nullable();
            $table->text('notes')->nullable();
            // Who sits, as a fingerprint of the members' normalized names; kept by the database at commit.
            $table->char('committee_key', 32)->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'kind']);                            // target of the members' (sitting_id, sitting_kind)
            $table->index(['kind', 'starts_at']);
        });

        $this->textKeyFunction();
        // The same start, place (spaces, letter forms of ا/ي/ه and tatweel ignored) and members: one sitting, not two.
        DB::statement('CREATE UNIQUE INDEX interview_sittings_no_duplicate ON interview_sittings (starts_at, interview_text_key(location), committee_key)');

        Schema::create('interviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->unsignedSmallInteger('round');                                     // 1, then 2 after an accepted excuse, ...
            $table->foreignUuid('sitting_id')->index()->constrained('interview_sittings')->restrictOnDelete();
            $table->timestampTz('scheduled_at');                                       // the candidate's time in the sitting
            $table->enum('status', self::STATUSES)->default('scheduled');
            // The current result; every version of it is in interview_result_revisions.
            $table->enum('result', self::RESULTS)->nullable();
            $table->timestampTz('held_at')->nullable();
            $table->enum('scientific_grade', self::GRADES)->nullable();                // التقييم العلمي
            $table->enum('general_grade', self::GRADES)->nullable();                   // التقييم العام
            $table->enum('overall_grade', self::GRADES)->nullable();                   // التقدير
            $table->text('committee_summary')->nullable();                            // خلاصة اللجنة
            $table->text('notes')->nullable();                                        // ملاحظة اللجنة (for an absence: its reason)
            $table->foreignUuid('recorded_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('recorded_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignUuid('created_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['application_id', 'round']);
            $table->index(['status', 'scheduled_at']);
            $table->index('result');
        });

        DB::statement('ALTER TABLE interviews ADD CONSTRAINT interviews_round_positive CHECK (round > 0)');
        // A result exactly when recorded, with when it was held (not after it was entered) and who entered it.
        DB::statement("ALTER TABLE interviews ADD CONSTRAINT interviews_recorded CHECK ((status = 'recorded') = (result IS NOT NULL) AND (result IS NULL OR (held_at IS NOT NULL AND recorded_by IS NOT NULL AND recorded_at IS NOT NULL AND held_at <= recorded_at)))");
        // Attended: the three grades. Absent: none.
        DB::statement("ALTER TABLE interviews ADD CONSTRAINT interviews_grades_by_result CHECK (result IS NULL OR (result = 'absent') = (scientific_grade IS NULL AND general_grade IS NULL AND overall_grade IS NULL) AND (result = 'absent' OR (scientific_grade IS NOT NULL AND general_grade IS NOT NULL AND overall_grade IS NOT NULL)))");
        DB::statement("ALTER TABLE interviews ADD CONSTRAINT interviews_cancelled CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL) AND (cancelled_at IS NULL OR (cancelled_by IS NOT NULL AND btrim(coalesce(cancel_reason, '')) <> '')))");
        // One interview waiting at a time per application.
        DB::statement("CREATE UNIQUE INDEX interviews_one_scheduled ON interviews (application_id) WHERE status = 'scheduled'");

        Schema::create('interview_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('sitting_id');
            // The sitting's kind, repeated so the database can hold a one-off sitting to one member (index below).
            $table->enum('sitting_kind', self::KINDS);
            $table->unsignedSmallInteger('position');
            $table->string('name', 150);
            $table->enum('capacity', self::CAPACITIES);                               // الصفة: رئيس اللجنة، عضو، مقرر
            // A staff account: the name is then the account's (trigger below).
            $table->foreignUuid('staff_user_id')->nullable()->index()->constrained('staff_users')->restrictOnDelete();

            $table->unique(['sitting_id', 'position']);
            // A sitting keeps its kind (trigger), so the pair always matches the sitting.
            $table->foreign(['sitting_id', 'sitting_kind'])->references(['id', 'kind'])->on('interview_sittings')->restrictOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX interview_members_one_chair ON interview_members (sitting_id) WHERE capacity = 'chair'");
        // A one-off sitting has one interviewer: at most one member here, at least one by the commit check below.
        DB::statement("CREATE UNIQUE INDEX interview_members_one_off_single ON interview_members (sitting_id) WHERE sitting_kind = 'one_off'");

        Schema::create('interview_reschedules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('interview_id')->constrained('interviews')->restrictOnDelete();
            // The sitting gives the place and the committee; the time is the candidate's own within it.
            $table->foreignUuid('from_sitting_id')->constrained('interview_sittings')->restrictOnDelete();
            $table->foreignUuid('to_sitting_id')->constrained('interview_sittings')->restrictOnDelete();
            $table->timestampTz('from_scheduled_at');
            $table->timestampTz('to_scheduled_at');
            $table->text('reason');
            $table->foreignUuid('rescheduled_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });

        DB::statement("ALTER TABLE interview_reschedules ADD CONSTRAINT interview_reschedules_reason CHECK (btrim(reason) <> '')");
        DB::statement('ALTER TABLE interview_reschedules ADD CONSTRAINT interview_reschedules_changed CHECK (from_sitting_id <> to_sitting_id OR from_scheduled_at <> to_scheduled_at)');

        Schema::create('interview_result_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('interview_id')->constrained('interviews')->restrictOnDelete();
            $table->unsignedSmallInteger('revision');                                  // 1: as recorded; then corrections
            $table->enum('result', self::RESULTS);
            $table->timestampTz('held_at');
            $table->enum('scientific_grade', self::GRADES)->nullable();
            $table->enum('general_grade', self::GRADES)->nullable();
            $table->enum('overall_grade', self::GRADES)->nullable();
            $table->text('committee_summary')->nullable();
            $table->text('notes')->nullable();
            $table->text('reason')->nullable();                                       // why it was corrected
            $table->foreignUuid('entered_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->unique(['interview_id', 'revision']);
        });

        DB::statement("ALTER TABLE interview_result_revisions ADD CONSTRAINT interview_result_revisions_correction_explained CHECK ((revision = 1) = (reason IS NULL) AND (reason IS NULL OR btrim(reason) <> ''))");

        // Evidence is never changed or removed.
        foreach (['interview_reschedules', 'interview_result_revisions'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()");
        }

        $this->interviewTriggers();
        $this->sittingTriggers();
        $this->committeeTriggers();
        $this->memberNameTriggers();

        // The interview behind a status change (D-038).
        StatusEventCauseColumns::add('interview_id', 'interviews');
    }

    /** Text compared without the differences of typing: spaces, ة/ه, ى/ي, the forms of alef, tatweel. */
    private function textKeyFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_text_key(value text) RETURNS text AS
            $$
                SELECT lower(regexp_replace(btrim(translate(value, 'أإآٱىةـ', 'اااايه')), '\s+', ' ', 'g'))
            $$ LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            SQL
        );
    }

    /** Never deleted; the time and the holding date are within the sitting. */
    private function interviewTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interviews_never_deleted() RETURNS trigger AS
            $$
                BEGIN
                    RAISE EXCEPTION 'Interviews are cancelled, never deleted' USING ERRCODE = 'integrity_constraint_violation';
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER interviews_no_delete BEFORE DELETE ON interviews FOR EACH ROW EXECUTE FUNCTION interviews_never_deleted()');

        // FOR SHARE: a sitting cannot move (or change its committee) while an interview in it is being written.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interviews_within_sitting() RETURNS trigger AS
            $$
                DECLARE
                    sitting RECORD;
                BEGIN
                    SELECT starts_at INTO sitting FROM interview_sittings WHERE id = NEW.sitting_id FOR SHARE;

                    IF NEW.scheduled_at < sitting.starts_at THEN
                        RAISE EXCEPTION 'An interview cannot be scheduled before its sitting starts' USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.held_at IS NOT NULL AND NEW.held_at < sitting.starts_at THEN
                        RAISE EXCEPTION 'An interview cannot be held before its sitting starts' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER interviews_within_sitting BEFORE INSERT OR UPDATE OF sitting_id, scheduled_at, held_at ON interviews FOR EACH ROW EXECUTE FUNCTION interviews_within_sitting()');
    }

    /** A sitting keeps its kind, and cannot start after an interview booked or held in it. */
    private function sittingTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_sittings_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF NEW.kind <> OLD.kind THEN
                        RAISE EXCEPTION 'A sitting keeps its kind' USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.starts_at > OLD.starts_at AND EXISTS (
                        SELECT 1 FROM interviews
                        WHERE sitting_id = NEW.id AND (scheduled_at < NEW.starts_at OR held_at < NEW.starts_at)
                    ) THEN
                        RAISE EXCEPTION 'The sitting would start after an interview booked or held in it' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER interview_sittings_guard BEFORE UPDATE ON interview_sittings FOR EACH ROW EXECUTE FUNCTION interview_sittings_guard()');
    }

    /**
     * Who interviewed is guaranteed: every sitting has at least one member when its transaction commits (deferred
     * check), and the members of a sitting with a recorded result never change. The committee key is computed at
     * commit too, once all the members are written, so a committee being entered is never compared half-written.
     */
    private function committeeTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_committee_key(sitting uuid) RETURNS char(32) AS
            $$
                SELECT md5(string_agg(interview_text_key(name), '|' ORDER BY interview_text_key(name)))
                FROM interview_members WHERE sitting_id = sitting
            $$ LANGUAGE sql STABLE
            SQL
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_members_key_sitting() RETURNS trigger AS
            $$
                DECLARE
                    sitting uuid;
                BEGIN
                    FOREACH sitting IN ARRAY CASE TG_OP
                        WHEN 'INSERT' THEN ARRAY[NEW.sitting_id]
                        WHEN 'DELETE' THEN ARRAY[OLD.sitting_id]
                        ELSE ARRAY[OLD.sitting_id, NEW.sitting_id]
                    END LOOP
                        UPDATE interview_sittings SET committee_key = interview_committee_key(sitting)
                        WHERE id = sitting AND committee_key IS DISTINCT FROM interview_committee_key(sitting);
                    END LOOP;

                    RETURN NULL;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE CONSTRAINT TRIGGER interview_members_key_sitting AFTER INSERT OR DELETE OR UPDATE OF sitting_id, name ON interview_members DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION interview_members_key_sitting()');

        // One check, called from a trigger on each table: each trigger reads only its own table's row (NEW of a
        // sitting, OLD of a member), so neither refers to a column the other table does not have.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_sitting_needs_members(sitting uuid) RETURNS void AS
            $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM interview_sittings WHERE id = sitting)
                        AND NOT EXISTS (SELECT 1 FROM interview_members WHERE sitting_id = sitting) THEN
                        RAISE EXCEPTION 'A sitting needs at least one committee member' USING ERRCODE = 'check_violation';
                    END IF;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_sittings_have_members() RETURNS trigger AS
            $$
                BEGIN
                    PERFORM interview_sitting_needs_members(NEW.id);
                    RETURN NULL;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_members_keep_one() RETURNS trigger AS
            $$
                BEGIN
                    PERFORM interview_sitting_needs_members(OLD.sitting_id);
                    RETURN NULL;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE CONSTRAINT TRIGGER interview_sittings_have_members AFTER INSERT ON interview_sittings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION interview_sittings_have_members()');
        DB::unprepared('CREATE CONSTRAINT TRIGGER interview_members_keep_one AFTER DELETE OR UPDATE OF sitting_id ON interview_members DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION interview_members_keep_one()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_members_frozen() RETURNS trigger AS
            $$
                DECLARE
                    sitting uuid;
                BEGIN
                    FOREACH sitting IN ARRAY CASE TG_OP
                        WHEN 'INSERT' THEN ARRAY[NEW.sitting_id]
                        WHEN 'DELETE' THEN ARRAY[OLD.sitting_id]
                        ELSE ARRAY[OLD.sitting_id, NEW.sitting_id]
                    END LOOP
                        PERFORM 1 FROM interview_sittings WHERE id = sitting FOR UPDATE;

                        IF EXISTS (SELECT 1 FROM interviews WHERE sitting_id = sitting AND status = 'recorded') THEN
                            RAISE EXCEPTION 'The committee of a sitting with a recorded result does not change' USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                    END LOOP;

                    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER interview_members_frozen BEFORE INSERT OR UPDATE OR DELETE ON interview_members FOR EACH ROW EXECUTE FUNCTION interview_members_frozen()');
    }

    /**
     * A member who is a staff account has the account's name, as the application shows it (each name with its first
     * letter capitalised): set here on every write, so it can never differ. When the account is renamed, the
     * members of sittings without a recorded result follow; a sitting with a result keeps the names it was held
     * with (its committee is the evidence).
     */
    private function memberNameTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_user_display_name(first_name text, last_name text) RETURNS text AS
            $$
                SELECT upper(left(first_name, 1)) || substr(first_name, 2) || ' ' || upper(left(last_name, 1)) || substr(last_name, 2)
            $$ LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            SQL
        );
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION interview_members_staff_name() RETURNS trigger AS
            $$
                BEGIN
                    IF NEW.staff_user_id IS NOT NULL THEN
                        SELECT staff_user_display_name(first_name, last_name) INTO NEW.name FROM staff_users WHERE id = NEW.staff_user_id;
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER interview_members_staff_name BEFORE INSERT OR UPDATE OF name, staff_user_id ON interview_members FOR EACH ROW EXECUTE FUNCTION interview_members_staff_name()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION staff_users_rename_members() RETURNS trigger AS
            $$
                BEGIN
                    UPDATE interview_members m SET name = staff_user_display_name(NEW.first_name, NEW.last_name)
                    WHERE m.staff_user_id = NEW.id
                        AND NOT EXISTS (SELECT 1 FROM interviews i WHERE i.sitting_id = m.sitting_id AND i.status = 'recorded');

                    RETURN NULL;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER staff_users_rename_members AFTER UPDATE OF first_name, last_name ON staff_users FOR EACH ROW WHEN (OLD.first_name IS DISTINCT FROM NEW.first_name OR OLD.last_name IS DISTINCT FROM NEW.last_name) EXECUTE FUNCTION staff_users_rename_members()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS staff_users_rename_members ON staff_users');
        StatusEventCauseColumns::remove('interview_id');
        Schema::dropIfExists('interview_result_revisions');
        Schema::dropIfExists('interview_reschedules');
        Schema::dropIfExists('interview_members');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('interview_sittings');

        foreach (['interviews_never_deleted', 'interviews_within_sitting', 'interview_sittings_guard', 'interview_sittings_have_members', 'interview_members_keep_one', 'interview_members_frozen', 'interview_members_key_sitting', 'interview_members_staff_name', 'staff_users_rename_members'] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS interview_sitting_needs_members(uuid)');
        DB::unprepared('DROP FUNCTION IF EXISTS interview_committee_key(uuid)');
        DB::unprepared('DROP FUNCTION IF EXISTS interview_text_key(text)');
        DB::unprepared('DROP FUNCTION IF EXISTS staff_user_display_name(text, text)');
    }
};
