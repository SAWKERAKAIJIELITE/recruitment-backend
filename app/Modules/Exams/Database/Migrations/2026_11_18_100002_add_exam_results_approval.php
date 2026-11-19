<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A session's results are approved by the exams office once, after the session (D-104): who and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->timestampTz('results_approved_at')->nullable();
            $table->foreignUuid('results_approved_by')->nullable()->constrained('staff_users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE exam_sessions ADD CONSTRAINT exam_sessions_results_approved CHECK ((results_approved_at IS NULL) = (results_approved_by IS NULL))');

        // Approved once: never taken back.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_sessions_approved_once() RETURNS trigger AS
            $$
                BEGIN
                    IF OLD.results_approved_at IS NOT NULL AND (NEW.results_approved_at IS DISTINCT FROM OLD.results_approved_at OR NEW.results_approved_by IS DISTINCT FROM OLD.results_approved_by) THEN
                        RAISE EXCEPTION 'The results of exam session % were approved: the approval never changes', OLD.id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_sessions_results_approved_once BEFORE UPDATE ON exam_sessions FOR EACH ROW EXECUTE FUNCTION exam_sessions_approved_once()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS exam_sessions_results_approved_once ON exam_sessions');
        DB::unprepared('DROP FUNCTION IF EXISTS exam_sessions_approved_once()');
        DB::statement('ALTER TABLE exam_sessions DROP CONSTRAINT exam_sessions_results_approved');

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('results_approved_by');
            $table->dropColumn('results_approved_at');
        });
    }
};
