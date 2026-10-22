<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    ];
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
        //     'health_conditions',
        //     'marital_status',
        //     'job_position',
        //     'academic_qualification',
        //     'specialization',
        //     'nominating_entities'
        // ] as $tableName) {
        //     $this->createQuestionTable($tableName);
        // }

        // One counter per year for the nomination number (see NominationNumberGenerator).
        Schema::create('nomination_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('last_value');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nomination_number', 30)->unique();
            $table->string('existing_employee_number', 50)->nullable();
            $table->string('phone_number', 16);
            $table->unsignedTinyInteger('studying_years')->nullable()->index();
            $table->unsignedTinyInteger('quran_parts_memorized')->nullable()->index();
            $table->boolean('works_for_other_government_entity')->index();
            $table->boolean('full_commitment')->index();
            $table->boolean('overnight_work')->index();
            $table->foreignUuid('candidate_id')->index()->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->index()->constrained('staff_users')->restrictOnDelete();
            $table->enum('current_status', self::STATUSES)->default('awaiting_exam')->index();
            $table->text('notes')->nullable();
            $table->timestampTz('availability_declared_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE applications ADD CONSTRAINT applications_study_years_range CHECK (studying_years IS NULL OR studying_years <= 15)');
        DB::statement('ALTER TABLE applications ADD CONSTRAINT applications_quran_parts_range CHECK (quran_parts_memorized IS NULL OR quran_parts_memorized <= 30)');
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_phone_number_format CHECK (phone_number !~ '[a-zA-Z]')");

        foreach ([
            'health_conditions',
            'marital_status',
            'job_position',
            'academic_qualification',
            'specialization',
            'nominating_entities',
            'application_categories'
        ] as $tableName) {
            Schema::table('applications', function (Blueprint $table) use ($tableName) {
                $table->foreignId("{$tableName}_id")->index()->constrained($tableName)->restrictOnDelete();
            });
        }

        // A person cannot have two live applications for the same job title. The policy for repeat
        // applications is still open (recruitment cycles), so this is deliberately conservative.
        DB::statement("CREATE UNIQUE INDEX applications_one_open_per_title ON applications (candidate_id, job_position_id) WHERE current_status NOT IN ('interview_failed', 'contract_written')");


        // The history. Append-only: nothing can change or erase a row (see the trigger below).
        Schema::create('application_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->enum('from_status', self::STATUSES)->nullable();
            $table->enum('to_status', self::STATUSES);
            $table->enum('decision_type', ['created', 'outcome', 'exception', 'excuse_accepted']);
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('staff_users')->restrictOnDelete();
            // The exam attempt / interview / contract that caused this move. Those tables do not exist yet,
            // so this is a plain reference (no foreign key) that later modules fill.
            $table->string('related_type', 50)->nullable();
            $table->uuid('related_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index(['application_id', 'occurred_at']);
        });

        // Only "created" has no previous status; person-made decisions need a real reason and an actor.
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_first_has_no_from CHECK ((from_status IS NULL) = (decision_type = 'created'))");
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_decisions_are_accountable CHECK (decision_type NOT IN ('exception', 'excuse_accepted') OR (btrim(coalesce(reason, '')) <> '' AND actor_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_row_modification() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% on % is not allowed: the table is append-only', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared('CREATE TRIGGER application_status_events_append_only BEFORE UPDATE OR DELETE ON application_status_events FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()');
        DB::unprepared('CREATE TRIGGER application_status_events_no_truncate BEFORE TRUNCATE ON application_status_events FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_modification()');

    }

    public function down(): void
    {
        foreach ([
            'health_conditions',
            'marital_status',
            'job_position',
            'academic_qualification',
            'specialization',
            'nominating_entities'
        ] as $tableName) {
            // Schema::dropIfExists($tableName);
            Schema::table('applications', function (Blueprint $table) use ($tableName) {
                $table->dropConstrainedForeignId("{$tableName}_id");
            });
        }
        Schema::dropIfExists('applications');
    }
};
