<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment calls the candidates to their appointments (D-105): one row per call, about one exam booking or one
 * interview, with the time and place the candidate was told, and whether they were reached. For information only: a
 * call never moves an application and never blocks anything. Never changed or deleted: a mistake is followed by the
 * right call, and the latest call about the appointment as it stands decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_calls', function (Blueprint $table) {
            $table->id();   // the order of the calls
            $table->foreignUuid('application_id')->constrained('applications')->restrictOnDelete();
            $table->foreignUuid('exam_booking_id')->nullable()->constrained('exam_bookings')->restrictOnDelete();
            $table->foreignUuid('interview_id')->nullable()->constrained('interviews')->restrictOnDelete();
            // What the candidate was told: a later change of time or place needs a new call.
            $table->timestampTz('appointment_at');
            $table->string('location', 300);
            $table->boolean('reached');                 // true: informed; false: not reached (no answer, phone off)
            $table->text('note')->nullable();
            $table->foreignUuid('called_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('created_at');

            $table->index(['exam_booking_id', 'id']);
            $table->index(['interview_id', 'id']);
            $table->index(['application_id', 'id']);
        });

        DB::statement('ALTER TABLE appointment_calls ADD CONSTRAINT appointment_calls_one_appointment CHECK (num_nonnulls(exam_booking_id, interview_id) = 1)');

        // A call is a fact: never changed or erased (prevent_row_modification: the applications migration's).
        DB::unprepared('CREATE TRIGGER appointment_calls_append_only BEFORE UPDATE OR DELETE ON appointment_calls FOR EACH ROW EXECUTE FUNCTION prevent_row_modification()');

        // The booking or the interview is the application's.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION appointment_calls_same_application() RETURNS trigger AS
            $$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM exam_bookings WHERE id = NEW.exam_booking_id AND application_id = NEW.application_id
                        UNION ALL
                        SELECT 1 FROM interviews WHERE id = NEW.interview_id AND application_id = NEW.application_id
                    ) THEN
                        RAISE EXCEPTION 'The appointment of a call must be its application''s' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER appointment_calls_of_the_application BEFORE INSERT ON appointment_calls FOR EACH ROW EXECUTE FUNCTION appointment_calls_same_application()');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_calls');
        DB::unprepared('DROP FUNCTION IF EXISTS appointment_calls_same_application()');
    }
};
