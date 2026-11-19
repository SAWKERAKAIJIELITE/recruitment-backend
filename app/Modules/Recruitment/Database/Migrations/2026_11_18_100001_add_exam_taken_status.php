<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An exam's results wait for the exams office's approval (D-104):
 *
 *  - a new status, exam_taken (أدى الاختبار بانتظار اعتماد النتيجة), between awaiting_exam and the result;
 *  - a new decision, failed_by_decision (ترسيب بقرار): a candidate who passed, failed at the approval with a reason of a
 *    type (reason_type: cheating or other) and an optional decision number.
 *
 * Every list is written out: a migration must not change with the code.
 */
return new class extends Migration
{
    private const STATUSES = [
        'received', 'awaiting_exam', 'exam_taken', 'exam_failed', 'exam_absent', 'awaiting_interview', 'interview_failed',
        'interview_absent', 'awaiting_contract', 'contract_written', 'contract_ended', 'closed',
    ];

    private const STATUSES_BEFORE = [
        'received', 'awaiting_exam', 'exam_failed', 'exam_absent', 'awaiting_interview', 'interview_failed',
        'interview_absent', 'awaiting_contract', 'contract_written', 'contract_ended', 'closed',
    ];

    private const DECISION_TYPES = ['created', 'started', 'outcome', 'exception', 'excuse_accepted', 'closure', 'correction', 'failed_by_decision'];

    private const DECISION_TYPES_BEFORE = ['created', 'started', 'outcome', 'exception', 'excuse_accepted', 'closure', 'correction'];

    public function up(): void
    {
        $this->statuses(self::STATUSES, self::DECISION_TYPES);

        Schema::table('application_status_events', function ($table) {
            // A failure by decision says what kind of reason it is (D-104): cheating or other.
            $table->string('reason_type', 20)->nullable();
        });

        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_reason_type CHECK ((decision_type = 'failed_by_decision') = (reason_type IS NOT NULL) AND (reason_type IS NULL OR reason_type IN ('cheating', 'other')))");
        // A failure by decision is made by a person, with a reason, and only after the exam was taken.
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_failed_by_decision CHECK (decision_type <> 'failed_by_decision' OR to_status = 'exam_failed')");
        $this->accountable(['exception', 'excuse_accepted', 'closure', 'correction', 'failed_by_decision'], ['exception', 'excuse_accepted', 'failed_by_decision']);
    }

    public function down(): void
    {
        // Only possible while nothing uses the new values.
        DB::statement('ALTER TABLE application_status_events DROP CONSTRAINT application_status_events_failed_by_decision');
        DB::statement('ALTER TABLE application_status_events DROP CONSTRAINT application_status_events_reason_type');
        $this->accountable(['exception', 'excuse_accepted', 'closure', 'correction'], ['exception', 'excuse_accepted']);

        Schema::table('application_status_events', function ($table) {
            $table->dropColumn('reason_type');
        });

        $this->statuses(self::STATUSES_BEFORE, self::DECISION_TYPES_BEFORE);
    }

    /** @param list<string> $statuses @param list<string> $decisions */
    private function statuses(array $statuses, array $decisions): void
    {
        $list = fn (array $values) => "'" . implode("', '", $values) . "'";

        foreach ([['applications', 'current_status'], ['application_status_events', 'to_status']] as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_{$column}_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list($statuses)}))");
        }

        DB::statement('ALTER TABLE application_status_events DROP CONSTRAINT application_status_events_decision_type_check');
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_decision_type_check CHECK (decision_type IN ({$list($decisions)}))");

        // A document type may be required from any status (D-057).
        DB::statement('ALTER TABLE document_types DROP CONSTRAINT document_types_required_from_status');
        DB::statement("ALTER TABLE document_types ADD CONSTRAINT document_types_required_from_status CHECK (required_from_status IS NULL OR required_from_status IN ({$list($statuses)}))");
    }

    /**
     * Person-made decisions need a real reason and the staff user who made them; a decision number belongs to an
     * exception, an accepted excuse or a failure by decision, and is never blank (D-100).
     *
     * @param  list<string>  $accountable
     * @param  list<string>  $numbered
     */
    private function accountable(array $accountable, array $numbered): void
    {
        $list = fn (array $values) => "'" . implode("', '", $values) . "'";

        DB::statement('ALTER TABLE application_status_events DROP CONSTRAINT application_status_events_decisions_are_accountable');
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_decisions_are_accountable CHECK (decision_type NOT IN ({$list($accountable)}) OR (btrim(coalesce(reason, '')) <> '' AND actor_id IS NOT NULL))");
        DB::statement('ALTER TABLE application_status_events DROP CONSTRAINT application_status_events_decision_number');
        DB::statement("ALTER TABLE application_status_events ADD CONSTRAINT application_status_events_decision_number CHECK (decision_number IS NULL OR (decision_type IN ({$list($numbered)}) AND btrim(decision_number) <> ''))");
    }
};
