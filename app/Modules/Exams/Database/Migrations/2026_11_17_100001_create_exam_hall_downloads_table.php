<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Exams in a hall with no internet (D-102). A session is taken out to a moderator's approved laptop before it starts
 * (its bookings and questions, never the correct answers), sat in the hall, and brought back: the server marks it.
 *
 * While a session is out, nothing about it changes on the server: these guards refuse new bookings or changes to
 * them, attempts, a new draw, extensions and changes to its timing, place, plan or password, whatever route tries.
 * Only the transaction bringing that download back gets through: it names it first with
 * set_config('recruitment.hall_upload', <download id>, true), which ends with the transaction. A cancel ends the
 * "out" state first, then writes.
 */
return new class extends Migration
{
    private const STATUSES = ['out', 'returned', 'cancelled', 'replaced'];

    public function up(): void
    {
        Schema::create('exam_hall_downloads', function (Blueprint $table) {
            $table->uuid('id')->primary();   // the download id the upload sends back
            $table->foreignUuid('session_id')->constrained('exam_sessions')->restrictOnDelete();
            $table->foreignUuid('downloaded_by')->constrained('staff_users')->restrictOnDelete();
            // The laptop that took it out: its key seals what comes back.
            $table->foreignUuid('staff_device_id')->constrained('staff_devices')->restrictOnDelete();
            $table->timestampTz('downloaded_at');
            $table->jsonb('booking_ids');     // the bookings given to the laptop: the upload lists exactly these
            $table->jsonb('question_ids');    // the session's drawn questions (exam_session_questions ids)
            $table->enum('status', self::STATUSES)->default('out');

            // Brought back.
            $table->timestampTz('returned_at')->nullable();
            $table->foreignUuid('returned_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->foreignUuid('returned_device_id')->nullable()->constrained('staff_devices')->restrictOnDelete();
            $table->char('upload_sha256', 64)->nullable();   // the sealed bundle: the same upload again changes nothing
            $table->json('upload_summary')->nullable();      // the answer given, given again to a repeat (json: keys keep their order)
            $table->timestampTz('sealed_at')->nullable();
            $table->timestampTz('hall_started_at')->nullable();   // the hall's real start
            $table->text('start_changed_reason')->nullable();
            $table->jsonb('committee')->nullable();               // who signed the hall's paper attendance sheet
            $table->unsignedInteger('attendance_count')->nullable();   // signatures on it

            // Cancelled (the laptop lost, broken, or the session must change).
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->boolean('questions_exposed')->default(false);

            // Taken out again before the start: this copy no longer counts.
            $table->timestampTz('replaced_at')->nullable();

            $table->timestampsTz();
            $table->index(['session_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX exam_hall_downloads_one_out ON exam_hall_downloads (session_id) WHERE status = 'out'");
        DB::statement("ALTER TABLE exam_hall_downloads ADD CONSTRAINT exam_hall_downloads_returned CHECK ((status = 'returned') = (returned_at IS NOT NULL AND returned_by IS NOT NULL AND upload_sha256 IS NOT NULL AND upload_summary IS NOT NULL AND hall_started_at IS NOT NULL))");
        DB::statement("ALTER TABLE exam_hall_downloads ADD CONSTRAINT exam_hall_downloads_cancelled CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(coalesce(cancel_reason, '')) <> ''))");
        DB::statement("ALTER TABLE exam_hall_downloads ADD CONSTRAINT exam_hall_downloads_replaced CHECK ((status = 'replaced') = (replaced_at IS NOT NULL))");
        DB::statement("ALTER TABLE exam_hall_downloads ADD CONSTRAINT exam_hall_downloads_sha256 CHECK (upload_sha256 IS NULL OR upload_sha256 ~ '^[0-9a-f]{64}$')");

        Schema::table('exam_sessions', function (Blueprint $table) {
            // The planned start, kept when a hall began later (the session's starts_at is then the real one).
            $table->timestampTz('planned_starts_at')->nullable();
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->foreignUuid('hall_download_id')->nullable()->constrained('exam_hall_downloads')->restrictOnDelete();
            // Shown on the candidate's phone at hand-in; the server computes the same from what came back.
            $table->string('receipt_code', 14)->nullable();
        });

        $this->guards();

        // The permission, for installations updated without db:seed; the seeder declares it too. No role holds it:
        // the administrator gives it to whom the owner chooses (D-102).
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'slug' => 'exams.offline',
                'label_ar' => 'إخراج جلسة اختبار إلى قاعة بلا إنترنت وإعادة نتائجها',
                'read_only' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_session_frozen(session uuid) RETURNS boolean
                LANGUAGE sql STABLE
                AS $$
                    SELECT EXISTS (
                        SELECT 1 FROM exam_hall_downloads
                        WHERE session_id = session AND status = 'out'
                          AND id::text IS DISTINCT FROM current_setting('recruitment.hall_upload', true)
                    )
                $$
            SQL
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_hall_frozen() RETURNS trigger AS
            $$
                DECLARE
                    session uuid := CASE TG_OP WHEN 'DELETE' THEN OLD.session_id ELSE NEW.session_id END;
                BEGIN
                    IF exam_session_frozen(session) THEN
                        RAISE EXCEPTION 'An exam session taken out to a hall does not change until it is brought back or cancelled (%)', TG_TABLE_NAME
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN CASE TG_OP WHEN 'DELETE' THEN OLD ELSE NEW END;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );

        foreach ([
            'exam_bookings' => 'INSERT OR UPDATE',
            'exam_attempts' => 'INSERT',
            'exam_session_questions' => 'INSERT OR UPDATE OR DELETE',
            'exam_session_extensions' => 'INSERT',
        ] as $table => $events) {
            DB::unprepared("CREATE TRIGGER {$table}_hall_frozen BEFORE {$events} ON {$table} FOR EACH ROW EXECUTE FUNCTION exam_hall_frozen()");
        }

        // The session itself: only its notes may change while it is out.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_sessions_hall_frozen() RETURNS trigger AS
            $$
                BEGIN
                    IF exam_session_frozen(NEW.id) AND (
                        NEW.starts_at <> OLD.starts_at OR NEW.late_minutes <> OLD.late_minutes OR NEW.duration_minutes <> OLD.duration_minutes
                        OR NEW.extra_minutes <> OLD.extra_minutes OR NEW.location <> OLD.location OR NEW.password <> OLD.password
                        OR NEW.plan_version_id <> OLD.plan_version_id OR NEW.planned_starts_at IS DISTINCT FROM OLD.planned_starts_at
                    ) THEN
                        RAISE EXCEPTION 'An exam session taken out to a hall keeps its plan, time, place and password' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_sessions_hall_frozen BEFORE UPDATE ON exam_sessions FOR EACH ROW EXECUTE FUNCTION exam_sessions_hall_frozen()');

        // A download is never deleted; it leaves "out" once (returned, cancelled or replaced), then stays as it is.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exam_hall_downloads_guard() RETURNS trigger AS
            $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Hall downloads are never deleted' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.status <> 'out' THEN
                        RAISE EXCEPTION 'A hall download that is %, stays as it is', OLD.status USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.session_id <> OLD.session_id OR NEW.staff_device_id <> OLD.staff_device_id OR NEW.downloaded_by <> OLD.downloaded_by
                        OR NEW.downloaded_at <> OLD.downloaded_at OR NEW.booking_ids <> OLD.booking_ids OR NEW.question_ids <> OLD.question_ids THEN
                        RAISE EXCEPTION 'A hall download keeps what was taken out' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql
            SQL
        );
        DB::unprepared('CREATE TRIGGER exam_hall_downloads_guard BEFORE UPDATE OR DELETE ON exam_hall_downloads FOR EACH ROW EXECUTE FUNCTION exam_hall_downloads_guard()');
    }

    public function down(): void
    {
        foreach (['exam_bookings', 'exam_attempts', 'exam_session_questions', 'exam_session_extensions'] as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_hall_frozen ON {$table}");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS exam_sessions_hall_frozen ON exam_sessions');

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hall_download_id');
            $table->dropColumn('receipt_code');
        });

        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn('planned_starts_at');
        });

        Schema::dropIfExists('exam_hall_downloads');

        foreach (['exam_hall_frozen()', 'exam_sessions_hall_frozen()', 'exam_hall_downloads_guard()', 'exam_session_frozen(uuid)'] as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}");
        }

        DB::table('permissions')->where('slug', 'exams.offline')->delete();
    }
};
