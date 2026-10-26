<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applications and their status history (D-024, D-033, D-037, D-038, D-039).
 *
 * The history is the single source of truth. It is a linear chain: every event points to the event
 * before it, of the same application, and no two events can follow the same one. applications.current_status
 * is a copy of the last event's status that only the database writes (trigger), so it cannot disagree
 * with the history and filtering by status stays a plain indexed column.
 */
return new class extends Migration {
    /** Same values as App\Modules\Recruitment\Enums\ApplicationStatus (written out: migrations must not change with the code). */
    private const STATUSES = [
        'awaiting_exam',
        'exam_failed',
        'exam_absent',
        'awaiting_interview',
        'interview_failed',
        'interview_absent',
        'awaiting_contract',
        'contract_written',
        'contract_ended',
        'closed',
    ];

    private const DECISION_TYPES = ['created', 'outcome', 'exception', 'excuse_accepted', 'closure'];

    public function up(): void
    {
        // One counter per year for the nomination number (see NominationNumberGenerator).
        Schema::create('nomination_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_value');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nomination_number', 30)->unique();
            $table->foreignUuid('candidate_id')->index()->constrained()->restrictOnDelete();

            // Where the nomination comes from and what it is for.
            $table->foreignId('nominating_entity_id')->index()->constrained('nominating_entities')->restrictOnDelete();
            $table->foreignId('job_position_id')->index()->constrained('job_positions')->restrictOnDelete();
            $table->foreignId('application_category_id')->index()->constrained('application_categories')->restrictOnDelete();
            $table->string('existing_employee_number', 50)->nullable();

            // Declared by the person at application time.
            $table->foreignId('academic_qualification_id')->index()->constrained('academic_qualifications')->restrictOnDelete();
            $table->foreignId('specialization_id')->index()->constrained('specializations')->restrictOnDelete();
            $table->unsignedSmallInteger('studying_years')->nullable()->index();
            $table->boolean('studies_discontinued')->default(false);   // "3 ومنقطع": studied 3 years, then stopped (D-044)
            $table->unsignedSmallInteger('quran_parts_memorized')->nullable()->index();
            $table->foreignId('marital_status_id')->index()->constrained('marital_statuses')->restrictOnDelete();
            $table->foreignId('health_condition_id')->index()->constrained('health_conditions')->restrictOnDelete();
            $table->text('health_details')->nullable();
            $table->foreignId('residence_district_id')->index()->constrained('districts')->restrictOnDelete();
            $table->unsignedBigInteger('residence_area_id')->nullable();   // optional (D-043); when given, it must be in the district
            $table->text('residence_details')->nullable();
            $table->string('phone_number', 16);
            $table->boolean('works_for_other_government_entity')->index();
            $table->boolean('full_commitment')->index();
            $table->boolean('overnight_work')->index();
            $table->text('notes')->nullable();

            // Written by the database only (see the triggers below).
            $table->enum('current_status', self::STATUSES)->default('awaiting_exam')->index();
            $table->foreignUuid('created_by')->index()->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('created_at');

            // The area must belong to the district: the pair must exist in areas (district_id, id).
            $table->foreign(['residence_district_id', 'residence_area_id'], 'applications_residence_area_in_district')
                ->references(['district_id', 'id'])->on('areas')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE applications ADD CONSTRAINT applications_studying_years_range CHECK (studying_years IS NULL OR studying_years <= 15)');
        DB::statement('ALTER TABLE applications ADD CONSTRAINT applications_discontinued_has_years CHECK (NOT studies_discontinued OR studying_years IS NOT NULL)');
        DB::statement('ALTER TABLE applications ADD CONSTRAINT applications_quran_parts_range CHECK (quran_parts_memorized IS NULL OR quran_parts_memorized <= 30)');
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_phone_number_format CHECK (phone_number ~ '^\\+?[0-9]{6,15}$')");
        // DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_phone_number_format CHECK (phone_number !~ '[a-zA-Z]')");

        // A person has at most one live application, whatever the job position (D-037). An application stops
        // being live only when its contract ended without renewal or authorized staff closed it.
        DB::statement("CREATE UNIQUE INDEX applications_one_live_per_person ON applications (candidate_id) WHERE current_status NOT IN ('contract_ended', 'closed')");

        Schema::create('application_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            // The event this one follows. Null only for the first event ("created").
            $table->uuid('previous_event_id')->nullable()->unique();   // unique: the chain cannot fork
            $table->enum('to_status', self::STATUSES);
            $table->enum('decision_type', self::DECISION_TYPES);
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            // What caused the move (exam attempt, interview, contract, ...) is one real foreign-key column per
            // cause, added by the module that owns the cause's table (StatusEventCauseColumns, D-038).
            $table->timestampTz('occurred_at');   // when the decision happened in reality (D-039)
            $table->timestampTz('created_at');    // when it was recorded

            $table->unique(['application_id', 'id']);   // target of the composite key below
            $table->index(['application_id', 'occurred_at']);

            // The previous event must belong to the same application.
            $table->foreign(['application_id', 'previous_event_id'], 'application_status_events_previous_same_application')
                ->references(['application_id', 'id'])->on('application_status_events')->restrictOnDelete();
        });

        // Exactly one first event per application.
        DB::statement('CREATE UNIQUE INDEX application_status_events_one_first ON application_status_events (application_id) WHERE previous_event_id IS NULL');
        // Only "created" has no previous status; and a chain always starts at awaiting_exam.
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_first_is_created CHECK ((previous_event_id IS NULL) = (decision_type = 'created'))");
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_created_awaits_exam CHECK (decision_type <> 'created' OR to_status = 'awaiting_exam')");
        // Person-made decisions need a real reason and the staff user who made them.
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_decisions_are_accountable CHECK (decision_type NOT IN ('exception', 'excuse_accepted', 'closure') OR (btrim(coalesce(reason, '')) <> '' AND actor_id IS NOT NULL))");
        // A decision cannot be dated after it was recorded (D-039).
        DB::statement('ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_not_recorded_before_it_happened CHECK (occurred_at <= created_at)');

        $this->createTriggers();
    }

    private function createTriggers(): void
    {
        // History rows are never changed or erased.
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
        DB::unprepared('CREATE TRIGGER application_status_events_append_only BEFORE UPDATE OR DELETE ON application_status_events FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()');
        DB::unprepared('CREATE TRIGGER application_status_events_no_truncate BEFORE TRUNCATE ON application_status_events FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_modification()');

        // A new event must continue from the application's latest event (no appending to an old point).
        // The application row is locked first, so two simultaneous appends are serialized.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION application_status_events_append_to_tail() RETURNS trigger AS
            $$
                DECLARE
                    tail uuid;
                    tail_occurred_at timestamptz;
                BEGIN
                    PERFORM 1 FROM applications WHERE id = NEW.application_id FOR UPDATE;

                    SELECT e.id, e.occurred_at INTO tail, tail_occurred_at
                    FROM application_status_events e
                    WHERE e.application_id = NEW.application_id
                    AND NOT EXISTS (SELECT 1 FROM application_status_events n WHERE n.previous_event_id = e.id);

                    IF NEW.previous_event_id IS DISTINCT FROM tail THEN
                        RAISE EXCEPTION 'Event must follow the latest event % of application %', coalesce(tail::text, '(none)'), NEW.application_id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    -- Decisions happen in order: none can be dated before the one it follows (D-039).
                    IF tail_occurred_at IS NOT NULL AND NEW.occurred_at < tail_occurred_at THEN
                        RAISE EXCEPTION 'Event of application % is dated % but the previous event is dated %', NEW.application_id, NEW.occurred_at, tail_occurred_at
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER application_status_events_follow_tail BEFORE INSERT ON application_status_events FOR EACH ROW EXECUTE FUNCTION application_status_events_append_to_tail()');

        // The application's current status is always the status of its latest event.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION application_status_events_sync_status() RETURNS trigger AS
            $$
                BEGIN
                    UPDATE applications SET current_status = NEW.to_status, updated_at = now() WHERE id = NEW.application_id;
                    RETURN NULL;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER application_status_events_set_current_status AFTER INSERT ON application_status_events FOR EACH ROW EXECUTE FUNCTION application_status_events_sync_status()');

        // Nothing else may write current_status: a new application starts at awaiting_exam, and a direct
        // UPDATE of the column is refused. (pg_trigger_depth() is 2 only when the sync trigger above runs it.)
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION applications_guard_current_status() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'INSERT' AND NEW.current_status <> 'awaiting_exam' THEN
                        RAISE EXCEPTION 'A new application starts at awaiting_exam' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF TG_OP = 'UPDATE' AND NEW.current_status IS DISTINCT FROM OLD.current_status AND pg_trigger_depth() < 2 THEN
                        RAISE EXCEPTION 'current_status changes only by adding a status event' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER applications_current_status_guard BEFORE INSERT OR UPDATE ON applications FOR EACH ROW EXECUTE FUNCTION applications_guard_current_status()');

        // Every application has a history: checked at commit, so the application and its first event can
        // be written in either order inside one transaction.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION applications_require_first_event() RETURNS trigger AS
            $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM application_status_events WHERE application_id = NEW.id AND previous_event_id IS NULL
                    ) THEN
                        RAISE EXCEPTION 'Application % has no history: open it through ApplicationLifecycle', NEW.id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NULL;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE CONSTRAINT TRIGGER applications_have_history AFTER INSERT ON applications DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION applications_require_first_event()');
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_events');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('nomination_counters');

        foreach ([
            'prevent_row_modification',
            'application_status_events_append_to_tail',
            'application_status_events_sync_status',
            'applications_guard_current_status',
            'applications_require_first_event',
        ] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
        }
    }
};
