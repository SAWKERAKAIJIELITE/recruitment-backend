<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Exceptions\IllegalStatusTransition;
use App\Modules\Recruitment\Models\Application;
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

        $this->lifecycle->move($application, S::AwaitingInterview, D::Outcome, null, null, 'exam_attempt', (string) Str::uuid());
        $this->lifecycle->move($application, S::AwaitingContract, D::Outcome, null, null, 'interview', (string) Str::uuid());
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

    public function test_an_event_remembers_what_caused_it(): void
    {
        $application = $this->openApplication();
        $attemptId = (string) Str::uuid();

        $event = $this->lifecycle->move($application, S::ExamFailed, D::Outcome, null, null, 'exam_attempt', $attemptId);

        $this->assertSame('exam_attempt', $event->related_type);
        $this->assertSame($attemptId, $event->related_id);
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

    public function test_a_terminal_status_cannot_be_left(): void
    {
        $application = $this->openApplication();
        $this->lifecycle->move($application, S::AwaitingInterview, D::Outcome);
        $this->lifecycle->move($application, S::InterviewFailed, D::Outcome);

        $this->expectException(IllegalStatusTransition::class);

        $this->lifecycle->move($application, S::AwaitingInterview, D::Exception, $this->hiringStaff(), 'Trying anyway');
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

    public function test_a_new_application_must_start_at_awaiting_exam(): void
    {
        $application = $this->openApplication();
        $copy = (array) DB::table('applications')->where('id', $application->id)->first();

        $this->expectException(QueryException::class);

        DB::table('applications')->insert(array_replace($copy, [
            'id' => (string) Str::uuid(),
            'nomination_number' => 'MANUAL-1',
            'job_position_id' => $this->jobPosition('إداري')->id,
            'current_status' => 'awaiting_contract',
        ]));
    }

    public function test_an_application_without_history_is_refused_when_the_transaction_ends(): void
    {
        $application = $this->openApplication();
        $copy = (array) DB::table('applications')->where('id', $application->id)->first();

        DB::table('applications')->insert(array_replace($copy, [
            'id' => (string) Str::uuid(),
            'nomination_number' => 'MANUAL-2',
            'job_position_id' => $this->jobPosition('إداري')->id,
        ]));

        // The check is deferred to commit; tests never commit, so ask PostgreSQL to run it now.
        $this->expectException(QueryException::class);

        DB::statement('SET CONSTRAINTS applications_have_history IMMEDIATE');
    }
}
