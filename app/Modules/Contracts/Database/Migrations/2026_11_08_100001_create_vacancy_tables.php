<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vacancies (D-088): the system administrator uploads a file saying how many first contracts each job position may
 * get during a period of three months. A first contract is written only while its job position has a vacancy left in
 * the plan covering its writing day; renewals use none. Plans never overlap; a plan is corrected by replacing it with
 * another file for the same period. Nothing here is deleted or edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancy_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('starts_on');
            $table->date('ends_on');                                         // included
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->text('notes')->nullable();
            // A correction: this plan takes the place of another one, for the same period.
            $table->uuid('replaces_plan_id')->nullable()->unique();
            $table->timestampTz('replaced_at')->nullable();                // set once, when another plan replaces it
            $table->foreignUuid('uploaded_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');
        });

        // After the table exists: it refers to itself.
        Schema::table('vacancy_plans', function (Blueprint $table) {
            $table->foreign('replaces_plan_id')->references('id')->on('vacancy_plans')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE vacancy_plans ADD CONSTRAINT vacancy_plans_period CHECK (ends_on > starts_on)');
        // The plans in force never overlap (a replaced plan is no longer in force).
        DB::statement("ALTER TABLE vacancy_plans ADD CONSTRAINT vacancy_plans_no_overlap EXCLUDE USING gist (daterange(starts_on, ends_on, '[]') WITH &&) WHERE (replaced_at IS NULL)");

        Schema::create('vacancy_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('plan_id')->constrained('vacancy_plans')->restrictOnDelete();
            $table->foreignId('job_position_id')->constrained('job_positions')->restrictOnDelete();
            $table->unsignedInteger('vacancies');
            $table->unsignedInteger('row_number');                          // where it is in the file
            $table->unique(['plan_id', 'job_position_id']);
        });

        DB::statement('ALTER TABLE vacancy_plan_items ADD CONSTRAINT vacancy_plan_items_vacancies CHECK (vacancies >= 0)');

        $this->createTriggers();
    }

    private function createTriggers(): void
    {
        // A plan is never deleted, and only its replaced_at is ever set, once. Its items never change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vacancy_plans_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Vacancy plans are never deleted' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.replaced_at IS NOT NULL OR NEW.replaced_at IS NULL
                        OR (to_jsonb(NEW) - 'replaced_at') IS DISTINCT FROM (to_jsonb(OLD) - 'replaced_at') THEN
                        RAISE EXCEPTION 'A vacancy plan only gets replaced, once' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER vacancy_plans_guard BEFORE UPDATE OR DELETE ON vacancy_plans FOR EACH ROW EXECUTE FUNCTION vacancy_plans_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vacancy_plan_items_frozen() RETURNS trigger AS
            $$
                BEGIN
                    RAISE EXCEPTION 'The items of a vacancy plan never change: upload a new file' USING ERRCODE = 'integrity_constraint_violation';
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER vacancy_plan_items_frozen BEFORE UPDATE OR DELETE ON vacancy_plan_items FOR EACH ROW EXECUTE FUNCTION vacancy_plan_items_frozen()');

        // A first contract needs a vacancy of its job position in the plan covering its writing day (also when its
        // writing day is corrected). The plan item is locked, so two contracts written at the same moment take the
        // last vacancy once; a plan being replaced is waited for.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION contracts_within_vacancies() RETURNS trigger AS
            $$
                DECLARE
                    position_id bigint;
                    plan RECORD;
                    allowed integer;
                    used integer;
                BEGIN
                    IF NEW.sequence <> 1 OR (TG_OP = 'UPDATE' AND NEW.written_on = OLD.written_on) THEN
                        RETURN NEW;
                    END IF;

                    SELECT job_position_id INTO position_id FROM applications WHERE id = NEW.application_id;

                    SELECT id, starts_on, ends_on INTO plan FROM vacancy_plans
                        WHERE replaced_at IS NULL AND NEW.written_on BETWEEN starts_on AND ends_on FOR SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'contract_no_vacancy_plan: no vacancy plan covers %', NEW.written_on USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT vacancies INTO allowed FROM vacancy_plan_items WHERE plan_id = plan.id AND job_position_id = position_id FOR UPDATE;

                    SELECT count(*) INTO used FROM contracts c JOIN applications a ON a.id = c.application_id
                        WHERE c.sequence = 1 AND c.id <> NEW.id AND a.job_position_id = position_id
                          AND c.written_on BETWEEN plan.starts_on AND plan.ends_on;

                    IF used >= coalesce(allowed, 0) THEN
                        RAISE EXCEPTION 'contract_no_vacancy: job position % has no vacancy left in plan %', position_id, plan.id USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER contracts_within_vacancies BEFORE INSERT OR UPDATE OF written_on ON contracts FOR EACH ROW EXECUTE FUNCTION contracts_within_vacancies()');

        // The job position of an application with a contract never changes: its contract used that position's vacancy.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION applications_position_after_contract() RETURNS trigger AS
            $$
                BEGIN
                    IF NEW.job_position_id IS DISTINCT FROM OLD.job_position_id AND EXISTS (SELECT 1 FROM contracts WHERE application_id = NEW.id) THEN
                        RAISE EXCEPTION 'application_position_after_contract: the job position of an application with a contract does not change' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER applications_position_after_contract BEFORE UPDATE OF job_position_id ON applications FOR EACH ROW EXECUTE FUNCTION applications_position_after_contract()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS applications_position_after_contract ON applications');
        DB::unprepared('DROP TRIGGER IF EXISTS contracts_within_vacancies ON contracts');
        Schema::dropIfExists('vacancy_plan_items');
        Schema::dropIfExists('vacancy_plans');
        DB::unprepared('DROP FUNCTION IF EXISTS applications_position_after_contract()');
        DB::unprepared('DROP FUNCTION IF EXISTS contracts_within_vacancies()');
        DB::unprepared('DROP FUNCTION IF EXISTS vacancy_plan_items_frozen()');
        DB::unprepared('DROP FUNCTION IF EXISTS vacancy_plans_guard()');
    }
};
