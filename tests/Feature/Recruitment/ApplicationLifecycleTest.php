<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Contracts\StatusChangeCause;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Support\StatusEventCauseColumns;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RecruitmentTestHelpers;
use Tests\TestCase;

class ApplicationLifecycleTest extends TestCase
{
    use RefreshDatabase, RecruitmentTestHelpers;

    private ApplicationLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->lifecycle = app(ApplicationLifecycle::class);
    }

    /** @return list<string> */
    private function statusesOf(Application $application): array
    {
        return $application->fresh()->history()->map(fn($event) => $event->to_status->value)->all();
    }

    /** A raw history row, for tests that go around the application code. */
    private function rawEvent(Application $application, ?string $previousId, string $to, string $type = 'outcome', array $extra = []): array
    {
        return array_replace([
            'id' => (string) Str::uuid(),
            'application_id' => $application->id,
            'previous_event_id' => $previousId,
            'to_status' => $to,
            'decision_type' => $type,
            'occurred_at' => now(),
            'created_at' => now(),
        ], $extra);
    }

    // ---- moves ----

    public function test_a_new_application_starts_awaiting_exam_with_a_created_event(): void
    {
        $actor = $this->hiringStaff();
        $application = $this->openApplication($actor);

        $this->assertSame(S::AwaitingExam, $application->current_status);

        $event = $application->history()->sole();
        $this->assertNull($event->previous_event_id);
        $this->assertNull($event->fromStatus());
        $this->assertSame(S::AwaitingExam, $event->to_status);
        $this->assertSame(D::Created, $event->decision_type);
        $this->assertSame($actor->id, $event->actor_id);
    }

    public function test_the_happy_path_to_a_written_contract_is_recorded_step_by_step(): void
    {
        $application = $this->openApplication();

        $this->lifecycle->move($application, S::AwaitingInterview, D::Outcome);
        $this->lifecycle->move($application, S::AwaitingContract, D::Outcome);
        $this->lifecycle->move($application, S::ContractWritten, D::Outcome);

        $this->assertSame(S::ContractWritten, $application->current_status, 'the caller\'s model is reloaded');
        $this->assertSame(
            ['awaiting_exam', 'awaiting_interview', 'awaiting_contract', 'contract_written'],
            $this->statusesOf($application),
        );
    }

    public function test_each_event_knows_the_status_it_came_from(): void
    {
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::ExamAbsent, D::Outcome);
        $this->lifecycle->move($application, S::AwaitingExam, D::ExcuseAccepted, $this->hiringStaff(), 'Medical certificate');

        $this->assertSame(
            [null, 'awaiting_exam', 'exam_absent'],
            $application->fresh()->history()->map(fn($event) => $event->fromStatus()?->value)->all(),
        );
    }

    public function test_the_history_order_follows_the_chain_even_with_equal_timestamps(): void
    {
        $this->freezeTime();
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::AwaitingInterview, D::Outcome);
        $this->lifecycle->move($application, S::InterviewAbsent, D::Outcome);
        $this->lifecycle->move($application, S::AwaitingInterview, D::ExcuseAccepted, $this->hiringStaff(), 'Accepted');

        $this->assertSame(
            ['awaiting_exam', 'awaiting_interview', 'interview_absent', 'awaiting_interview'],
            $this->statusesOf($application),
        );
    }

    public function test_an_illegal_jump_is_refused_and_changes_nothing(): void
    {
        $application = $this->openApplication();

        try {
            $this->lifecycle->move($application, S::ContractWritten, D::Outcome);
            $this->fail('The jump should have been refused.');
        } catch (IllegalStatusTransition) {
        }

        $this->assertSame(S::AwaitingExam, $application->fresh()->current_status);
        $this->assertSame(1, $application->statusEvents()->count());
    }

    public function test_the_decision_type_must_match_the_move(): void
    {
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::ExamFailed, D::Outcome);

        // Failing can be overturned only by an exception, not by an ordinary outcome.
        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::AwaitingInterview, D::Outcome);
    }

    public function test_an_exception_overturns_a_failure_and_needs_a_reason_and_an_actor(): void
    {
        $actor = $this->hiringStaff();
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::ExamFailed, D::Outcome);

        try {
            $this->lifecycle->move($application, S::AwaitingInterview, D::Exception, $actor, '   ');
            $this->fail('A blank reason should be refused.');
        } catch (IllegalStatusTransition) {
        }

        $this->assertSame(S::ExamFailed, $application->fresh()->current_status);

        $event = $this->lifecycle->move($application, S::AwaitingInterview, D::Exception, $actor, '  Approved by the general manager ');

        $this->assertSame(S::AwaitingInterview, $application->fresh()->current_status);
        $this->assertSame('Approved by the general manager', $event->reason);
    }

    public function test_an_exception_without_an_actor_is_refused(): void
    {
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::ExamFailed, D::Outcome);

        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::AwaitingInterview, D::Exception, null, 'No one signed this');
    }

    public function test_an_accepted_excuse_reopens_the_exam(): void
    {
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::ExamAbsent, D::Outcome);

        $this->lifecycle->move($application, S::AwaitingExam, D::ExcuseAccepted, $this->hiringStaff(), 'Medical certificate');

        $this->assertSame(S::AwaitingExam, $application->fresh()->current_status);
        $this->assertSame(3, $application->statusEvents()->count());   // the absence stays in the history
    }

    public function test_a_closed_application_cannot_be_reopened(): void
    {
        $actor = $this->hiringStaff();
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::ExamFailed, D::Outcome);
        $this->lifecycle->move($application, S::Closed, D::Closure, $actor, 'No exception was requested');

        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::AwaitingInterview, D::Exception, $actor, 'Trying anyway');
    }

    public function test_closing_needs_a_reason_and_an_actor(): void
    {
        $application = $this->openApplication();

        try {
            $this->lifecycle->move($application, S::Closed, D::Closure, null, 'No one signed this');
            $this->fail('A closure without an actor should be refused.');
        } catch (IllegalStatusTransition) {
        }

        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::Closed, D::Closure, $this->hiringStaff(), '');
    }

    public function test_every_status_before_a_written_contract_can_be_closed(): void
    {
        foreach (S::cases() as $status) {
            $closable = $status->decisionRequiredFor(S::Closed) === D::Closure;
            $expected = ! in_array($status, [S::ContractWritten, S::ContractEnded, S::Closed], true);

            $this->assertSame($expected, $closable, $status->value);
        }
    }

    public function test_a_written_contract_ends_only_through_contract_ended(): void
    {
        $application = $this->openApplication();
        foreach ([S::AwaitingInterview, S::AwaitingContract, S::ContractWritten] as $to) {
            $this->lifecycle->move($application, $to, D::Outcome);
        }

        try {
            $this->lifecycle->move($application, S::Closed, D::Closure, $this->hiringStaff(), 'Not allowed');
            $this->fail('A written contract cannot be closed.');
        } catch (IllegalStatusTransition) {
        }

        $this->lifecycle->move($application, S::ContractEnded, D::Outcome);

        $this->assertSame(S::ContractEnded, $application->current_status);
        $this->assertFalse($application->current_status->isLive());
    }

    // ---- when the decision happened (D-039) ----

    public function test_a_decision_can_be_dated_when_it_really_happened(): void
    {
        $this->travelTo('2026-03-01 09:00:00');
        $application = $this->openApplication();
        $this->travelTo('2026-03-04 12:00:00');

        $event = $this->lifecycle->move($application, S::AwaitingInterview, D::Outcome, occurredAt: now()->parse('2026-03-02 10:15:30'));

        $this->assertSame('2026-03-02 10:15:30', $event->fresh()->occurred_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-04 12:00:00', $event->fresh()->created_at->format('Y-m-d H:i:s'));
    }

    public function test_a_decision_cannot_be_dated_in_the_future(): void
    {
        $application = $this->openApplication();

        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::ExamFailed, D::Outcome, occurredAt: now()->addMinute());
    }

    public function test_a_decision_cannot_be_dated_before_the_previous_one(): void
    {
        $this->travelTo('2026-03-01 09:00:00');
        $application = $this->openApplication();
        $this->travelBack();

        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::ExamFailed, D::Outcome, occurredAt: now()->parse('2026-02-28 23:59:59'));
    }

    public function test_the_database_refuses_an_event_dated_after_it_was_recorded(): void
    {
        $application = $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($application, $application->statusEvents()->sole()->id, 'exam_failed', 'outcome', [
            'occurred_at' => now()->addHour(),
            'created_at' => now(),
        ]));
    }

    public function test_the_database_refuses_an_event_dated_before_the_previous_one(): void
    {
        $application = $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($application, $application->statusEvents()->sole()->id, 'exam_failed', 'outcome', [
            'occurred_at' => now()->subYear(),
        ]));
    }

    // ---- what caused a move (D-038) ----

    /** A stand-in for a later module's table (exam attempts, interviews, ...). DDL rolls back with the test. */
    private function createCauseTable(string $table): void
    {
        DB::statement("CREATE TABLE {$table} (id uuid PRIMARY KEY)");
        StatusEventCauseColumns::add(Str::singular($table) . '_id', $table);
    }

    private function causeOf(string $table): StatusChangeCause
    {
        $id = (string) Str::uuid();
        DB::table($table)->insert(['id' => $id]);

        $column = Str::singular($table) . '_id';

        return new class($id, $column) implements StatusChangeCause {
            private static string $column;

            public function __construct(private readonly string $id, string $column)
            {
                self::$column = $column;
            }

            public static function statusEventColumn(): string
            {
                return self::$column;
            }

            public function getKey()
            {
                return $this->id;
            }
        };
    }

    public function test_a_move_records_its_cause_in_the_cause_column(): void
    {
        $this->createCauseTable('trial_attempts');
        $application = $this->openApplication();
        $cause = $this->causeOf('trial_attempts');

        $event = $this->lifecycle->move($application, S::ExamFailed, D::Outcome, cause: $cause);

        $this->assertSame($cause->getKey(), DB::table('application_status_events')->where('id', $event->id)->value('trial_attempt_id'));
    }

    public function test_a_cause_column_is_a_real_foreign_key(): void
    {
        $this->createCauseTable('trial_attempts');
        $application = $this->openApplication();
        $cause = $this->causeOf('trial_attempts');
        $this->lifecycle->move($application, S::ExamFailed, D::Outcome, cause: $cause);

        // The record the history points to cannot be deleted.
        $this->expectException(QueryException::class);

        DB::table('trial_attempts')->where('id', $cause->getKey())->delete();
    }

    public function test_an_event_has_at_most_one_cause(): void
    {
        $this->createCauseTable('trial_attempts');
        $this->createCauseTable('trial_interviews');
        $this->assertSame(['trial_attempt_id', 'trial_interview_id'], StatusEventCauseColumns::columns());

        $application = $this->openApplication();
        $attempt = $this->causeOf('trial_attempts');
        $interview = $this->causeOf('trial_interviews');

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($application, $application->statusEvents()->sole()->id, 'exam_failed', 'outcome', [
            'trial_attempt_id' => $attempt->getKey(),
            'trial_interview_id' => $interview->getKey(),
        ]));
    }

    public function test_removing_a_cause_column(): void
    {
        $this->createCauseTable('trial_attempts');
        $this->createCauseTable('trial_interviews');

        StatusEventCauseColumns::remove('trial_interview_id');

        $this->assertSame(['trial_attempt_id'], StatusEventCauseColumns::columns());
    }

    public function test_unsafe_cause_column_names_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StatusEventCauseColumns::add('x; DROP TABLE applications; --_id', 'applications');
    }

    public function test_a_stale_model_cannot_slip_a_second_move_through(): void
    {
        $application = $this->openApplication();
        $stale = Application::findOrFail($application->id);   // loaded while still awaiting_exam

        $this->lifecycle->move($application, S::ExamFailed, D::Outcome);

        // The lifecycle reads the status from the locked row, not from the stale model.
        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($stale, S::AwaitingInterview, D::Outcome);
    }

    // ---- what the database itself guarantees ----

    public function test_history_cannot_be_updated(): void
    {
        $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->update(['reason' => 'rewriting history']);
    }

    public function test_history_cannot_be_deleted(): void
    {
        $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->delete();
    }

    public function test_the_current_status_cannot_be_written_directly(): void
    {
        $application = $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('applications')->where('id', $application->id)->update(['current_status' => 'contract_written']);
    }

    public function test_other_columns_of_an_application_can_still_be_updated(): void
    {
        $application = $this->openApplication();

        DB::table('applications')->where('id', $application->id)->update(['notes' => 'corrected']);

        $this->assertSame('corrected', $application->fresh()->notes);
    }

    public function test_adding_an_event_sets_the_current_status(): void
    {
        $application = $this->openApplication();
        $first = $application->statusEvents()->sole();

        DB::table('application_status_events')->insert($this->rawEvent($application, $first->id, 'exam_failed'));

        $this->assertSame(S::ExamFailed, $application->fresh()->current_status);
    }

    public function test_an_event_must_follow_the_latest_event(): void
    {
        $application = $this->openApplication();
        $first = $application->statusEvents()->sole();
        $this->lifecycle->move($application, S::ExamAbsent, D::Outcome);

        // Appending after the first event again would fork the history.
        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($application, $first->id, 'awaiting_interview'));
    }

    public function test_a_second_first_event_is_refused(): void
    {
        $application = $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($application, null, 'awaiting_exam', 'created'));
    }

    public function test_an_event_cannot_follow_an_event_of_another_application(): void
    {
        $mine = $this->openApplication();
        $other = $this->openApplication(null, [
            'job_position_id' => $this->jobPosition('إداري')->id,
            'candidate' => ['national_number' => '99999999999'],
        ]);

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($mine, $other->statusEvents()->sole()->id, 'exam_failed'));
    }

    public function test_only_the_created_event_has_no_previous_event(): void
    {
        $application = $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert(
            $this->rawEvent($application, $application->statusEvents()->sole()->id, 'awaiting_exam', 'created'),
        );
    }

    public function test_the_database_refuses_an_exception_without_a_reason(): void
    {
        $application = $this->openApplication();
        $failed = $this->lifecycle->move($application, S::ExamFailed, D::Outcome);

        $this->expectException(QueryException::class);

        DB::table('application_status_events')->insert($this->rawEvent($application, $failed->id, 'awaiting_interview', 'exception'));
    }

    /** A copy of an application's row for another person (one live application per person, D-037). */
    private function rowForAnotherPerson(Application $application, array $changes): array
    {
        $person = (array) DB::table('candidates')->where('id', $application->candidate_id)->first();
        $person = array_replace($person, ['id' => (string) Str::uuid(), 'national_number' => '77777777777']);
        DB::table('candidates')->insert($person);

        $row = (array) DB::table('applications')->where('id', $application->id)->first();

        return array_replace($row, ['id' => (string) Str::uuid(), 'candidate_id' => $person['id']], $changes);
    }

    public function test_a_new_application_must_start_at_awaiting_exam(): void
    {
        $application = $this->openApplication();

        $this->expectException(QueryException::class);

        DB::table('applications')->insert($this->rowForAnotherPerson($application, [
            'nomination_number' => 'MANUAL-1',
            'current_status' => 'awaiting_contract',
        ]));
    }

    public function test_an_application_without_history_is_refused_when_the_transaction_ends(): void
    {
        $application = $this->openApplication();

        DB::table('applications')->insert($this->rowForAnotherPerson($application, ['nomination_number' => 'MANUAL-2']));

        // The check is deferred to commit; tests never commit, so ask PostgreSQL to run it now.
        $this->expectException(QueryException::class);

        DB::statement('SET CONSTRAINTS applications_have_history IMMEDIATE');
    }
}
