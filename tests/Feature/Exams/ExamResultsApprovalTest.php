<?php

namespace Tests\Feature\Exams;

use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use App\Modules\Reports\Queries\CandidateResults;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExamTestHelpers;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/**
 * A session's results wait for the exams office's approval (D-104): a decided result keeps the application in
 * exam_taken; the approval, once, after the session, passes and fails them as the marks say, and fails by decision the
 * passes it names, with a reason. Nothing that passed goes back.
 *
 * The plan: الحديث النبوي (5 questions, every correct option 0) and القرآن الكريم from parts (التلاوة: 5 questions;
 * التجويد marked by hand out of 40); 120 minutes. Sessions start at 08:00 on 10 November.
 */
class ExamResultsApprovalTest extends TestCase
{
    use ExamTestHelpers, RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/exam-sessions';

    private const PASSWORD = 'qalam-2026';

    private StaffUser $exams;

    private ExamPlanVersion $plan;

    private int $tajweed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-11-10 07:00:00');
        $this->exams = $this->staffOf('exams');
        $position = JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']);
        $this->plan = $this->publishedPlan($position, $this->planContent([$this->subject()], $this->quran()), $this->examManager());
        $this->tajweed = (int) DB::table('exam_plan_subject_parts')->where('plan_version_id', $this->plan->id)->where('assessment', 'manual')->value('id');
    }

    // ---- helpers ----

    private function examSession(string $startsAt = '2026-11-10T08:00:00Z'): string
    {
        return $this->asStaff($this->exams)->postJson(self::URL, ['plan_version_id' => $this->plan->id, 'starts_at' => $startsAt, 'location' => 'قاعة الحاسوب', 'password' => self::PASSWORD])
            ->assertCreated()->json('data.id');
    }

    private function candidate(string $number): Application
    {
        return $this->openApplication($this->exams, ['candidate' => ['national_number' => $number]]);
    }

    /** Logs in, starts at 08:00 (or $at), answers ($right of the ten right) and hands in; marks التجويد when $tajweed is given. */
    private function sit(string $session, Application $application, int $right, ?string $tajweed = '36', string $at = '2026-11-10 08:00:00'): void
    {
        $this->travelTo($at);
        $this->app['auth']->forgetGuards();
        $token = $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => $application->candidate->national_number, 'password' => self::PASSWORD])->assertOk()->json('token');
        $as = function () use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token);
        };
        $as()->postJson('/api/v1/candidate/exam/start')->assertOk();

        foreach ($as()->getJson('/api/v1/candidate/exam/questions')->assertOk()->json('data') as $index => $question) {
            $as()->putJson("/api/v1/candidate/exam/answers/{$question['position']}", ['option' => $index < $right ? 0 : 1])->assertOk();
        }

        $as()->postJson('/api/v1/candidate/exam/submit')->assertOk();

        if ($tajweed !== null) {
            $this->mark($application, $tajweed)->assertOk();
        }
    }

    private function mark(Application $application, string $mark, ?string $reason = null, ?StaffUser $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->exams)->postJson("/api/v1/staff/exam-bookings/{$this->bookingOf($application)}/marks", ['marks' => [['part_id' => $this->tajweed, 'mark' => $mark]]] + ($reason === null ? [] : ['reason' => $reason]));
    }

    private function bookingOf(Application $application): string
    {
        return DB::table('exam_bookings')->where('application_id', $application->id)->orderByDesc('round')->value('id');
    }

    private function approve(string $session, array $failures = [], ?StaffUser $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->exams)->postJson(self::URL . "/{$session}/results-approval", $failures === [] ? [] : ['failures' => $failures]);
    }

    private function statusOf(Application $application): S
    {
        return $application->fresh()->current_status;
    }

    private function latestEvent(Application $application): object
    {
        return $application->fresh()->history()->last();
    }

    // ---- the approval ----

    public function test_decided_results_wait_and_the_approval_passes_and_fails_them_once(): void
    {
        $session = $this->examSession();
        [$pass, $fail, $absent] = [$this->candidate('01111111111'), $this->candidate('02222222222'), $this->candidate('03333333333')];
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$pass->id, $fail->id, $absent->id]])->assertCreated();

        $this->sit($session, $pass, 10);
        $this->sit($session, $fail, 2);
        $this->travelTo('2026-11-10 08:30:00');

        // Decided, neither passed nor failed: taken, dated the hand-in.
        $this->assertSame([S::ExamTaken, S::ExamTaken], [$this->statusOf($pass), $this->statusOf($fail)]);
        $this->assertSame('2026-11-10 08:00:00', $this->latestEvent($pass)->occurred_at->format('Y-m-d H:i:s'));
        $interviews = $this->staffOf('interviews');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $interviews->id)->count());   // nothing has passed
        $this->assertSame(1, $this->asStaff($this->exams)->getJson('/api/v1/staff/badges')->json('data.exams.results_to_approve'));
        $this->asStaff($this->exams)->getJson(self::URL . "/{$session}")->assertOk()
            ->assertJsonPath('data.results_approval', null)->assertJsonPath('data.awaiting_approval', 2);
        $this->asStaff($this->exams)->getJson(self::URL . '?results=awaiting_approval')->assertOk()->assertJsonCount(1, 'data');

        // Not before the session ends.
        $this->assertSame(__('exams.approval.not_ended'), $this->approve($session)->assertStatus(422)->json('errors.session.0'));
        $this->assertNull(DB::table('exam_sessions')->where('id', $session)->value('results_approved_at'));

        // Approved: as the marks say, dated the approval, by its author; the absent one stays absent.
        $this->travelTo('2026-11-10 10:30:00');
        $this->approve($session)->assertOk()
            ->assertJsonPath('meta', ['passed' => 1, 'failed' => 1, 'failed_by_decision' => 0])
            ->assertJsonPath('data.results_approval.approved_at', '2026-11-10T10:30:00+00:00')
            ->assertJsonPath('data.results_approval.approved_by.id', $this->exams->id)
            ->assertJsonPath('data.awaiting_approval', 0);
        $this->assertSame([S::AwaitingInterview, S::ExamFailed, S::ExamAbsent], [$this->statusOf($pass), $this->statusOf($fail), $this->statusOf($absent)]);
        $approval = $this->latestEvent($pass);
        $this->assertSame(['outcome', $this->bookingOf($pass), '2026-11-10 10:30:00', $this->exams->id], [$approval->decision_type->value, $approval->exam_booking_id, $approval->occurred_at->format('Y-m-d H:i:s'), $approval->actor_id]);
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $interviews->id)->count());   // the interviews are told now
        $this->assertSame(0, $this->asStaff($this->exams)->getJson('/api/v1/staff/badges')->json('data.exams.results_to_approve'));
        $this->asStaff($this->exams)->getJson(self::URL . '?results=approved')->assertOk()->assertJsonCount(1, 'data');
        $this->asStaff($this->exams)->getJson("/api/v1/staff/applications/{$pass->id}/exam-bookings")->assertOk()->assertJsonPath('data.0.session.results_approval.approved_at', '2026-11-10T10:30:00+00:00');
        $this->assertTrue(DB::table('audit_events')->where('event', 'exam_session.results_approved')->whereJsonContains('context->session_id', $session)->exists());

        // Once.
        $this->assertStringContainsString('اعتُمدت', $this->approve($session)->assertStatus(422)->json('errors.session.0'));
    }

    public function test_not_while_an_attempt_runs_or_a_result_is_undecided(): void
    {
        $session = $this->examSession();
        [$a, $b] = [$this->candidate('01111111111'), $this->candidate('02222222222')];
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$a->id, $b->id]])->assertCreated();
        $this->sit($session, $a, 10, tajweed: null);   // التجويد not marked yet

        // B started, and was given 60 minutes of their own: still running after the session's end.
        $this->travelTo('2026-11-10 08:05:00');
        $this->app['auth']->forgetGuards();
        $token = $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => '02222222222', 'password' => self::PASSWORD])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/candidate/exam/start')->assertOk();
        $attempt = DB::table('exam_attempts')->where('booking_id', $this->bookingOf($b))->value('id');
        $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-attempts/{$attempt}/extend", ['minutes' => 60, 'reason' => 'ظرف صحي موثق'])->assertOk();

        $this->travelTo('2026-11-10 10:30:00');
        $this->assertSame(__('exams.approval.running', ['count' => 1]), $this->approve($session)->assertStatus(422)->json('errors.session.0'));

        $this->travelTo('2026-11-10 11:10:00');   // B's own deadline passed: the approval's sweep closes it, decided once marked
        $this->assertSame(__('exams.approval.undecided', ['count' => 2]), $this->approve($session)->assertStatus(422)->json('errors.session.0'));
        $this->mark($a, '36')->assertOk();
        $this->mark($b, '10')->assertOk();
        $this->approve($session)->assertOk()->assertJsonPath('meta', ['passed' => 1, 'failed' => 1, 'failed_by_decision' => 0]);
    }

    // ---- failing a pass by decision ----

    public function test_a_pass_failed_by_decision_with_a_reason(): void
    {
        $session = $this->examSession();
        [$cheat, $honest, $weak] = [$this->candidate('01111111111'), $this->candidate('02222222222'), $this->candidate('03333333333')];
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$cheat->id, $honest->id, $weak->id]])->assertCreated();
        $this->sit($session, $cheat, 10);
        $this->sit($session, $honest, 10);
        $this->sit($session, $weak, 2);
        $this->travelTo('2026-11-10 10:30:00');
        $failure = ['booking_id' => $this->bookingOf($cheat), 'reason_type' => 'cheating', 'reason' => 'ضُبط مع ورقة إجابات', 'decision_number' => ' ق/2026/12 '];

        // Only a pass, only one of this session waiting for the approval, and the request is checked.
        $this->approve($session, [['booking_id' => $this->bookingOf($weak), 'reason_type' => 'other', 'reason' => 'سبب ما']])->assertStatus(422)->assertJsonPath('errors', ['failures.0.booking_id' => [__('exams.approval.not_a_pass')]]);
        $this->approve($session, [$failure, ['booking_id' => '01a1ed72-1018-7316-bd64-354e046306e5', 'reason_type' => 'other', 'reason' => 'سبب ما']])->assertStatus(422)->assertJsonValidationErrors('failures.1.booking_id');
        $this->approve($session, [['booking_id' => $failure['booking_id'], 'reason_type' => 'lazy', 'reason' => '']])->assertStatus(422)->assertJsonValidationErrors(['failures.0.reason_type', 'failures.0.reason']);
        $this->approve($session, [$failure, $failure])->assertStatus(422)->assertJsonValidationErrors('failures.0.booking_id');   // distinct
        $this->assertSame([S::ExamTaken, S::ExamTaken, S::ExamTaken], [$this->statusOf($cheat), $this->statusOf($honest), $this->statusOf($weak)]);

        // Failing needs exceptions.approve as well (a role with exams.manage only may approve, not fail).
        Role::where('slug', 'contracts')->firstOrFail()->permissions()->attach(Permission::where('slug', 'exams.manage')->value('id'));
        $this->approve($session, [$failure], $this->contractsStaff())->assertForbidden()->assertJsonPath('message', __('exams.approval.failures_permission'));

        $this->approve($session, [$failure])->assertOk()->assertJsonPath('meta', ['passed' => 1, 'failed' => 1, 'failed_by_decision' => 1]);
        $this->assertSame([S::ExamFailed, S::AwaitingInterview, S::ExamFailed], [$this->statusOf($cheat), $this->statusOf($honest), $this->statusOf($weak)]);

        // The decision carries its reason, its type and its number; the computed result stays a pass.
        $event = $this->latestEvent($cheat);
        $this->assertSame(['failed_by_decision', 'cheating', 'ضُبط مع ورقة إجابات', 'ق/2026/12', $this->exams->id], [$event->decision_type->value, $event->reason_type->value, $event->reason, $event->decision_number, $event->actor_id]);
        $this->assertTrue((bool) DB::table('exam_results')->where('attempt_id', DB::table('exam_attempts')->where('booking_id', $this->bookingOf($cheat))->value('id'))->value('passed'));

        // Recruitment reads it in the history; the round shows it; the stepper says so.
        $application = $this->asStaff($this->hiringStaff())->getJson("/api/v1/staff/applications/{$cheat->id}")->assertOk()->json('data');
        $this->assertSame(['failed_by_decision', 'ترسيب بقرار', 'cheating', 'غش', 'ق/2026/12'], array_values(array_intersect_key(last($application['history']), array_flip(['decision_type', 'decision_type_label', 'reason_type', 'reason_type_label', 'decision_number']))));
        $this->assertSame(['failed', 'راسب بقرار'], [$application['stages'][1]['state'], $application['stages'][1]['state_label']]);
        $round = $this->asStaff($this->exams)->getJson("/api/v1/staff/applications/{$cheat->id}/exam-bookings")->assertOk()->json('data.0.decision');
        $this->assertSame(['failed_by_decision', 'cheating', 'غش', 'ضُبط مع ورقة إجابات'], [$round['type'], $round['reason_type'], $round['reason_type_label'], $round['reason']]);

        // The candidates' statement says failed, never why (D-066).
        $results = CandidateResults::of(Application::query()->with(CandidateResults::RELATIONS)->whereKey($cheat->id)->get());
        $this->assertSame('failed', $results[0]['exam']['result']);
        $this->assertStringNotContainsString('ورقة', json_encode($results[0]['exam'], JSON_UNESCAPED_UNICODE));

        // The office does not take its own decision back by an exception; a computed failure still can be excepted.
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$cheat->id}/exam-exception", ['reason' => 'عفو'])->assertStatus(422)->assertJsonPath('errors.application.0', __('exams.decisions.failed_by_decision'));
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$weak->id}/exam-exception", ['reason' => 'حاجة ماسة إلى الاختصاص'])->assertOk();

        // The dashboard counts it among the failed, and apart.
        $exams = $this->asStaff($this->staffOf('general_manager'))->getJson('/api/v1/staff/reports/summary')->assertOk()->json('data.totals.exams');
        $this->assertSame([3, 1, 2, 1, 0], [$exams['takers'], $exams['passed'], $exams['failed'], $exams['failed_by_decision'], $exams['awaiting_approval']]);
    }

    // ---- corrections, closures and the database ----

    public function test_corrections_before_and_after_the_approval(): void
    {
        $session = $this->examSession();
        [$a, $b] = [$this->candidate('01111111111'), $this->candidate('02222222222')];
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$a->id, $b->id]])->assertCreated();
        // Nine of ten right: with التجويد 36 a pass, with 0 a failure (below the floor, or an average not above 70).
        $this->sit($session, $a, 9);
        $this->sit($session, $b, 9);

        // Before the approval a correction only revises the result: the approval reads the latest.
        $this->travelTo('2026-11-10 09:00:00');
        $this->mark($a, '0', 'أعيد تقييم التجويد')->assertOk()->assertJsonPath('data.attempt.result.revision', 2);
        $this->assertSame(S::ExamTaken, $this->statusOf($a));
        $this->travelTo('2026-11-10 10:30:00');
        $this->approve($session, [['booking_id' => $this->bookingOf($b), 'reason_type' => 'other', 'reason' => 'انتحال شخصية']])->assertOk()
            ->assertJsonPath('meta', ['passed' => 0, 'failed' => 1, 'failed_by_decision' => 1]);
        $this->assertSame([S::ExamFailed, S::ExamFailed], [$this->statusOf($a), $this->statusOf($b)]);

        // After it, a correction that changes passed / failed corrects the application (D-075)…
        $this->travelTo('2026-11-11 09:00:00');
        $this->mark($a, '36', 'خطأ في إدخال العلامة')->assertOk();
        $this->assertSame(S::AwaitingInterview, $this->statusOf($a));
        $this->assertSame('correction', $this->latestEvent($a)->decision_type->value);

        // …but a pass failed by decision stays failed, whatever the marks say.
        $this->mark($b, '0', 'أعيد تقييم التجويد')->assertOk();
        $this->assertSame(S::ExamFailed, $this->statusOf($b));
        $this->assertSame('failed_by_decision', $this->latestEvent($b)->decision_type->value);
    }

    public function test_an_application_closed_while_waiting_keeps_its_status(): void
    {
        $session = $this->examSession();
        [$a, $b] = [$this->candidate('01111111111'), $this->candidate('02222222222')];
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$a->id, $b->id]])->assertCreated();
        $this->sit($session, $a, 10);
        $this->sit($session, $b, 10);

        $this->asStaff($this->hiringStaff())->postJson("/api/v1/staff/applications/{$a->id}/close", ['reason' => 'انسحب المرشح'])->assertOk()->assertJsonPath('data.status', 'closed');
        $steps = $this->asStaff($this->hiringStaff())->getJson("/api/v1/staff/applications/{$b->id}")->assertOk()->json('data.stages');
        $this->assertSame(['current', 'أدى الاختبار، بانتظار اعتماد النتيجة'], [$steps[1]['state'], $steps[1]['state_label']]);

        $this->travelTo('2026-11-10 10:30:00');
        $this->approve($session, [['booking_id' => $this->bookingOf($a), 'reason_type' => 'other', 'reason' => 'سبب ما']])->assertStatus(422)->assertJsonValidationErrors('failures.0.booking_id');
        $this->approve($session)->assertOk()->assertJsonPath('meta', ['passed' => 1, 'failed' => 0, 'failed_by_decision' => 0]);
        $this->assertSame([S::Closed, S::AwaitingInterview], [$this->statusOf($a), $this->statusOf($b)]);
    }

    public function test_a_result_kept_for_an_application_closed_before_it_was_decided(): void
    {
        $session = $this->examSession();
        [$a, $b] = [$this->candidate('01111111111'), $this->candidate('02222222222')];
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$a->id, $b->id]])->assertCreated();
        $this->sit($session, $a, 10, tajweed: null);
        $this->sit($session, $b, 10);

        // A is closed before its التجويد is marked: its result is kept, the application stays closed (D-075).
        $this->asStaff($this->hiringStaff())->postJson("/api/v1/staff/applications/{$a->id}/close", ['reason' => 'انسحب المرشح'])->assertOk();
        $this->travelTo('2026-11-10 10:30:00');
        $this->assertSame(__('exams.approval.undecided', ['count' => 1]), $this->approve($session)->assertStatus(422)->json('errors.session.0'));
        $this->mark($a, '36')->assertOk()->assertJsonPath('data.attempt.result.passed', true);

        $this->approve($session)->assertOk()->assertJsonPath('meta', ['passed' => 1, 'failed' => 0, 'failed_by_decision' => 0]);
        $this->assertSame([S::Closed, S::AwaitingInterview], [$this->statusOf($a), $this->statusOf($b)]);
    }

    public function test_the_database_keeps_the_approval_and_the_failures_by_decision(): void
    {
        $session = $this->examSession();
        $a = $this->candidate('01111111111');
        $this->asStaff($this->exams)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$a->id]])->assertCreated();
        $this->sit($session, $a, 10);
        $this->travelTo('2026-11-10 10:30:00');
        $this->approve($session)->assertOk();

        $refused = function (\Closure $statement) {
            try {
                DB::transaction($statement);
                $this->fail('The database accepted it.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };

        // Approved once: never changed or taken back.
        $refused(fn() => DB::table('exam_sessions')->where('id', $session)->update(['results_approved_at' => null, 'results_approved_by' => null]));
        $refused(fn() => DB::table('exam_sessions')->where('id', $session)->update(['results_approved_by' => $this->hiringStaff()->id]));
        DB::table('exam_sessions')->where('id', $session)->update(['notes' => 'ملاحظة']);   // the rest of the session is not frozen by it
        $other = $this->examSession('2026-11-12T08:00:00Z');
        $refused(fn() => DB::table('exam_sessions')->where('id', $other)->update(['results_approved_at' => now()]));   // who, too

        // A reason type belongs to a failure by decision, which needs it, a reason and a person, and fails.
        $latest = $this->latestEvent($a);
        $base = ['application_id' => $a->id, 'previous_event_id' => $latest->id, 'actor_id' => $this->exams->id, 'occurred_at' => now(), 'created_at' => now()];
        $refused(fn() => DB::table('application_status_events')->insert($base + ['id' => (string) Str::uuid7(), 'to_status' => 'closed', 'decision_type' => 'closure', 'reason' => 'x', 'reason_type' => 'cheating']));
        $refused(fn() => DB::table('application_status_events')->insert($base + ['id' => (string) Str::uuid7(), 'to_status' => 'exam_failed', 'decision_type' => 'failed_by_decision', 'reason' => 'x']));
        $refused(fn() => DB::table('application_status_events')->insert($base + ['id' => (string) Str::uuid7(), 'to_status' => 'exam_failed', 'decision_type' => 'failed_by_decision', 'reason' => '', 'reason_type' => 'cheating']));
        $refused(fn() => DB::table('application_status_events')->insert($base + ['id' => (string) Str::uuid7(), 'to_status' => 'closed', 'decision_type' => 'failed_by_decision', 'reason' => 'x', 'reason_type' => 'other']));
        $refused(fn() => DB::table('application_status_events')->insert($base + ['id' => (string) Str::uuid7(), 'to_status' => 'exam_failed', 'decision_type' => 'failed_by_decision', 'reason' => 'x', 'reason_type' => 'lazy']));
    }
}
