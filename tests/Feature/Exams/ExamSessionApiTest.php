<?php

namespace Tests\Feature\Exams;

use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamSubjectPart;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExamTestHelpers;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/**
 * Exam sessions, candidate login, attempts, hand marks and results (analysis doc §06, §07; D-072 to D-075). The
 * plan: الحديث النبوي (5 questions) and القرآن الكريم from parts (التلاوة: 5 questions, weight 60; التجويد: marked
 * by hand out of 40, weight 40); 120 minutes. Every bank question's correct option is 0.
 */
class ExamSessionApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers, ExamTestHelpers;

    private const URL = '/api/v1/staff/exam-sessions';

    private const PASSWORD = 'qalam-2026';

    private StaffUser $staff;

    private StaffUser $planner;

    private ExamPlanVersion $plan;

    private int $tajweed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-11-10 07:00:00');
        $this->staff = $this->hiringStaff();          // recruitment_staff holds exams.manage
        $this->planner = $this->examManager();        // system_admin holds exam_bank.manage
        $position = JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']);
        $this->plan = $this->publishedPlan($position, $this->planContent([$this->subject()], $this->quran()), $this->planner);
        $this->tajweed = (int) DB::table('exam_plan_subject_parts')->where('plan_version_id', $this->plan->id)->where('assessment', 'manual')->value('id');
    }

    // ---- helpers ----

    private function examSession(array $overrides = []): string
    {
        return $this->asStaff($this->staff)->postJson(self::URL, $overrides + [
            'plan_version_id' => $this->plan->id, 'starts_at' => '2026-11-10T08:00:00Z', 'location' => 'قاعة الحاسوب 1', 'password' => self::PASSWORD,
        ])->assertCreated()->json('data.id');
    }

    private function applicant(string $number = '01234567890'): Application
    {
        return $this->openApplication($this->staff, ['candidate' => ['national_number' => $number]]);
    }

    private function book(string $session, Application ...$applications): TestResponse
    {
        return $this->asStaff($this->staff)->postJson(self::URL . "/{$session}/bookings", ['application_ids' => array_map(fn(Application $a) => $a->id, $applications)]);
    }

    private function login(string $number = '01234567890', string $password = self::PASSWORD): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => $number, 'password' => $password]);
    }

    private function asCandidate(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** Booked, logged in at 08:00 and started; returns the token. */
    private function started(string $session, string $number = '01234567890'): string
    {
        $this->travelTo('2026-11-10 07:55:00');
        $token = $this->login($number)->assertOk()->json('token');
        $this->travelTo('2026-11-10 08:00:00');
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/start')->assertOk();

        return $token;
    }

    /** Answers every question: right ones by the subject they belong to ($correct: how many right per subject). */
    private function answer(string $token, array $correct = []): void
    {
        $questions = $this->asCandidate($token)->getJson('/api/v1/candidate/exam/questions')->assertOk()->json('data');
        $subjects = DB::table('exam_attempt_items as i')->join('exam_session_questions as q', 'q.id', '=', 'i.session_question_id')
            ->join('exam_plan_subjects as s', 's.id', '=', 'q.plan_subject_id')->join('exam_subjects as e', 'e.id', '=', 's.subject_id')
            ->where('i.attempt_id', DB::table('exam_attempts')->latest('started_at')->value('id'))->pluck('e.name_ar', 'i.position');
        $given = [];

        foreach ($questions as $question) {
            $subject = $subjects[$question['position']];
            $given[$subject] = ($given[$subject] ?? 0) + 1;
            $right = $given[$subject] <= ($correct[$subject] ?? PHP_INT_MAX);
            $this->asCandidate($token)->putJson("/api/v1/candidate/exam/answers/{$question['position']}", ['option' => $right ? 0 : 1])->assertOk();
        }
    }

    private function booking(Application $application): array
    {
        return $this->asStaff($this->staff)->getJson("/api/v1/staff/applications/{$application->id}/exam-bookings")->assertOk()->json('data.0');
    }

    private function marks(string $booking, string $mark, ?string $reason = null, ?StaffUser $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->staff)->postJson("/api/v1/staff/exam-bookings/{$booking}/marks", ['marks' => [['part_id' => $this->tajweed, 'mark' => $mark]]] + ($reason === null ? [] : ['reason' => $reason]));
    }

    /** The first message of a dotted error key ("application_ids.1"), which a JSON path cannot address. */
    private function error(TestResponse $response, string $key): ?string
    {
        return $response->assertStatus(422)->json('errors')[$key][0] ?? null;
    }

    private function latestEvent(Application $application): object
    {
        return DB::table('application_status_events')->where('application_id', $application->id)->orderByDesc('occurred_at')->orderByDesc('id')->first();
    }

    // ---- sessions ----

    public function test_a_session_draws_its_questions_once_for_everyone(): void
    {
        $response = $this->asStaff($this->staff)->postJson(self::URL, ['plan_version_id' => $this->plan->id, 'starts_at' => '2026-11-10T08:00:00Z', 'location' => 'قاعة 1', 'password' => self::PASSWORD])
            ->assertCreated()
            ->assertJsonPath('data.questions_count', 10)
            ->assertJsonPath('data.duration_minutes', 120)
            ->assertJsonPath('data.late_minutes', 15)
            ->assertJsonPath('data.late_until', '2026-11-10T08:15:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-10T10:00:00+00:00')
            ->assertJsonPath('data.stage', 'scheduled');
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $session = $response->json('data.id');

        // The answers are seen only by who manages the bank.
        $this->asStaff($this->staff)->getJson(self::URL . "/{$session}/questions")->assertForbidden();
        $questions = $this->asStaff($this->planner)->getJson(self::URL . "/{$session}/questions")->assertOk()->json('data');
        $this->assertCount(10, $questions);
        $this->assertSame(0, $questions[0]['correct_option']);
        $this->assertSame(['الحديث النبوي' => 5, 'القرآن الكريم' => 5], collect($questions)->countBy('subject')->all());

        // Drawn again, or moved, until it starts.
        $this->asStaff($this->staff)->postJson(self::URL . "/{$session}/draw")->assertOk()->assertJsonPath('data.questions_count', 10);
        $this->asStaff($this->staff)->patchJson(self::URL . "/{$session}", ['location' => 'قاعة 2', 'late_minutes' => 10])->assertOk()->assertJsonPath('data.late_until', '2026-11-10T08:10:00+00:00');
        $this->travelTo('2026-11-10 08:01:00');
        $this->asStaff($this->staff)->patchJson(self::URL . "/{$session}", ['location' => 'قاعة 3'])->assertStatus(422)->assertJsonPath('errors.session.0', __('exams.sessions.started'));
        $this->asStaff($this->staff)->postJson(self::URL . "/{$session}/draw")->assertStatus(422);
        $this->asStaff($this->staff)->patchJson(self::URL . "/{$session}", ['password' => 'new-pass-1'])->assertOk();          // the password at any time
        $this->asStaff($this->staff)->patchJson(self::URL . "/{$session}", ['plan_version_id' => $this->plan->id])->assertStatus(422)->assertJsonValidationErrors('plan_version_id');
    }

    public function test_a_session_needs_a_published_plan_and_a_late_window_shorter_than_the_exam(): void
    {
        $body = ['starts_at' => '2026-11-10T08:00:00Z', 'location' => 'قاعة 1', 'password' => self::PASSWORD];
        $draft = app(\App\Modules\Exams\Actions\ManageExamPlans::class)->createDraft(JobPosition::query()->findOrFail($this->plan->job_position_id), $this->planner, $this->plan);

        $this->asStaff($this->staff)->postJson(self::URL, ['plan_version_id' => $draft->id] + $body)
            ->assertStatus(422)->assertJsonPath('errors.plan_version_id.0', __('exams.sessions.plan_not_published', ['status' => __('exams.statuses.draft')]));
        $this->asStaff($this->staff)->postJson(self::URL, ['plan_version_id' => $this->plan->id, 'late_minutes' => 120] + $body)
            ->assertStatus(422)->assertJsonPath('errors.late_minutes.0', __('exams.sessions.late_too_long', ['duration' => 120]));
        $this->asStaff($this->staff)->postJson(self::URL, ['plan_version_id' => $this->plan->id, 'starts_at' => '2026-11-09T08:00:00Z'] + $body)->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->asStaff($this->staff)->postJson(self::URL, ['plan_version_id' => $this->plan->id, 'password' => '123'] + $body)->assertStatus(422)->assertJsonValidationErrors('password');

        // The bank short of questions: no session.
        DB::table('exam_questions')->where('difficulty', 'hard')->update(['deleted_at' => now()]);
        $this->asStaff($this->staff)->postJson(self::URL, ['plan_version_id' => $this->plan->id] + $body)->assertStatus(422)->assertJsonValidationErrors('plan_version_id');
        $this->assertSame(0, DB::table('exam_sessions')->count());
    }

    public function test_a_session_nobody_was_booked_into_can_be_deleted(): void
    {
        $empty = $this->examSession();
        $this->asStaff($this->staff)->deleteJson(self::URL . "/{$empty}")->assertNoContent();
        $this->assertSame(0, DB::table('exam_session_questions')->count());

        $session = $this->examSession();
        $booking = $this->book($session, $this->applicant())->assertCreated()->json('data.0.id');
        $this->asStaff($this->staff)->postJson("/api/v1/staff/exam-bookings/{$booking}/cancel", ['reason' => 'خطأ في الحجز'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->asStaff($this->staff)->deleteJson(self::URL . "/{$session}")->assertStatus(422)->assertJsonPath('errors.session.0', __('exams.sessions.in_use'));
    }

    // ---- bookings ----

    public function test_booking_rules_and_the_queue(): void
    {
        $session = $this->examSession();
        $first = $this->applicant();
        $second = $this->applicant('22222222222');

        $this->asStaff($this->staff)->getJson('/api/v1/staff/exams/queue')->assertOk()->assertJsonCount(2, 'data');
        $this->book($session, $first)->assertCreated()->assertJsonPath('data.0.round', 1)->assertJsonPath('data.0.status', 'booked');
        $this->asStaff($this->staff)->getJson('/api/v1/staff/exams/queue')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id);

        $this->assertSame(__('exams.bookings.already_booked'), $this->error($this->book($session, $second, $first), 'application_ids.1'));
        $this->assertSame(1, DB::table('exam_bookings')->count());   // all or nothing

        $other = $this->openApplication($this->staff, ['job_position_id' => $this->position('آخر')->id, 'candidate' => ['national_number' => '33333333333']]);
        $this->assertSame(__('exams.bookings.other_job_position'), $this->error($this->book($session, $other), 'application_ids.0'));

        $this->closeApplication($second);
        $this->assertSame(__('exams.bookings.wrong_status', ['status' => S::Closed->labelAr()]), $this->error($this->book($session, $second), 'application_ids.0'));

        $this->travelTo('2026-11-10 08:15:00');
        $this->book($session, $this->applicant('44444444444'))->assertStatus(422)->assertJsonPath('errors.session.0', __('exams.bookings.session_closed'));
    }

    // ---- candidate login ----

    public function test_a_candidate_logs_in_with_the_national_number_and_the_sessions_password(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();

        // Not more than an hour before the session.
        $this->travelTo('2026-11-10 06:59:00');
        $this->login()->assertStatus(422)->assertJsonPath('errors.national_number.0', __('exams.candidate.login_failed'));
        $this->travelTo('2026-11-10 07:30:00');
        $this->login('01234567890', 'wrong-password')->assertStatus(422)->assertJsonPath('errors.national_number.0', __('exams.candidate.login_failed'));
        $this->login('99999999999')->assertStatus(422)->assertJsonPath('errors.national_number.0', __('exams.candidate.login_failed'));

        $first = $this->login('٠١٢٣٤٥٦٧٨٩٠')->assertOk()   // Arabic digits are the same number
            ->assertJsonPath('exam.location', 'قاعة الحاسوب 1')
            ->assertJsonPath('exam.status', 'booked')
            ->assertJsonPath('exam.can_start', false)
            ->assertJsonPath('exam.candidate.full_name', 'أحمد محمد علي')
            ->json('token');
        $this->asCandidate($first)->getJson('/api/v1/candidate/exam')->assertOk()->assertJsonPath('exam.server_time', '2026-11-10T07:30:00+00:00');

        // One login at a time: a new one ends the previous.
        $second = $this->login()->assertOk()->json('token');
        $this->asCandidate($first)->getJson('/api/v1/candidate/exam')->assertUnauthorized();
        $this->asCandidate($second)->getJson('/api/v1/candidate/exam')->assertOk();

        // A candidate's token opens no staff page, and a staff token no candidate page.
        $this->asCandidate($second)->getJson(self::URL)->assertUnauthorized();
        $this->asStaff($this->staff)->getJson('/api/v1/candidate/exam')->assertUnauthorized();

        // A deactivated person cannot log in.
        DB::table('candidates')->where('id', $application->candidate_id)->update(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $this->staff->id, 'deactivation_reason' => 'وفاة']);
        $this->login()->assertStatus(422);
        $this->asCandidate($second)->getJson('/api/v1/candidate/exam')->assertUnauthorized();
    }

    // ---- taking the exam ----

    public function test_a_candidate_takes_the_exam_in_their_own_order_and_the_server_keeps_the_time(): void
    {
        $session = $this->examSession();
        $this->book($session, $this->applicant(), $this->applicant('22222222222'))->assertCreated();
        $this->travelTo('2026-11-10 07:55:00');
        $token = $this->login()->json('token');
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/start')->assertStatus(422)->assertJsonPath('errors.booking.0', __('exams.candidate.not_yet', ['time' => '2026-11-10T08:00:00+00:00']));
        $this->asCandidate($token)->getJson('/api/v1/candidate/exam/questions')->assertStatus(422);

        // A latecomer gets the time left: the attempt ends with the session.
        $this->travelTo('2026-11-10 08:05:00');
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/start')->assertOk()
            ->assertJsonPath('exam.status', 'attended')
            ->assertJsonPath('exam.attempt.deadline_at', '2026-11-10T10:00:00+00:00')
            ->assertJsonPath('exam.attempt.seconds_left', 115 * 60)
            ->assertJsonPath('exam.attempt.questions', 10);
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/start')->assertOk();   // the same attempt
        $this->assertSame(1, DB::table('exam_attempts')->count());

        $questions = $this->asCandidate($token)->getJson('/api/v1/candidate/exam/questions')->assertOk()->json('data');
        $this->assertCount(10, $questions);
        $this->assertSame(range(1, 10), array_column($questions, 'position'));
        $this->assertSame(['position', 'prompt', 'options', 'selected_option', 'answered_at'], array_keys($questions[0]));   // never the answer
        $this->assertSame(['أ', 'ب', 'ج'], $questions[0]['options']);   // the options keep their order

        // The same questions as the other candidate, in another order (10! orders: the same one is near impossible).
        $other = $this->started($session, '22222222222');
        $mine = DB::table('exam_attempt_items')->where('attempt_id', DB::table('exam_attempts')->orderBy('started_at')->value('id'))->orderBy('position')->pluck('session_question_id')->all();
        $theirs = DB::table('exam_attempt_items')->where('attempt_id', DB::table('exam_attempts')->orderByDesc('started_at')->value('id'))->orderBy('position')->pluck('session_question_id')->all();
        $this->assertEqualsCanonicalizing($mine, $theirs);
        $this->assertNotSame($mine, $theirs);
        $this->assertNotNull($other);

        // Every save is kept; the last one counts; clearing is a save too.
        $this->travelTo('2026-11-10 08:10:00');
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/1', ['option' => 2])->assertOk()->assertJsonPath('selected_option', 2)->assertJsonPath('deadline_at', '2026-11-10T10:00:00+00:00');
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/1', ['option' => 0])->assertOk();
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/2', ['option' => 1])->assertOk();
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/2', ['option' => null])->assertOk()->assertJsonPath('selected_option', null);
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/3', ['option' => 3])->assertStatus(422)->assertJsonPath('errors.option.0', __('exams.candidate.no_such_option'));
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/11', ['option' => 0])->assertStatus(422)->assertJsonPath('errors.position.0', __('exams.candidate.no_such_question'));
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/3', [])->assertStatus(422)->assertJsonValidationErrors('option');
        $this->assertSame(4, DB::table('exam_answer_revisions')->count());
        $this->asCandidate($token)->getJson('/api/v1/candidate/exam')->assertJsonPath('exam.attempt.answered', 1);

        // Submitting twice gives one closed attempt and the same receipt.
        $receipt = $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk()->assertJsonPath('exam.attempt.closed_by', 'candidate')->json('exam.attempt');
        $this->assertSame($receipt, $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk()->json('exam.attempt'));
        $this->assertArrayNotHasKey('result', $receipt);
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/2', ['option' => 0])->assertStatus(422)->assertJsonPath('errors.attempt.0', __('exams.candidate.closed'));
        $this->asCandidate($token)->getJson('/api/v1/candidate/exam/questions')->assertOk()->assertJsonPath('data.0.selected_option', 0);
    }

    public function test_the_server_closes_the_attempt_at_its_deadline(): void
    {
        $session = $this->examSession();
        $this->book($session, $this->applicant())->assertCreated();
        $token = $this->started($session);
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/1', ['option' => 0])->assertOk();

        $this->travelTo('2026-11-10 10:00:00');
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/2', ['option' => 0])->assertStatus(422)->assertJsonPath('errors.attempt.0', __('exams.candidate.time_over'));

        $attempt = DB::table('exam_attempts')->first();
        $this->assertSame('submitted', $attempt->status);
        $this->assertSame('timeout', $attempt->closed_by);
        $this->assertSame('2026-11-10 10:00:00+00', $attempt->submitted_at);
        $this->assertSame(1, DB::table('exam_attempt_items')->where('is_correct', true)->count());
        $this->assertSame(9, DB::table('exam_attempt_items')->where('is_correct', false)->count());   // unanswered is wrong
    }

    public function test_not_started_when_the_late_window_closes_is_absent(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();

        $this->travelTo('2026-11-10 08:14:59');
        $this->asStaff($this->staff)->getJson(self::URL . "/{$session}/bookings")->assertJsonPath('data.0.status', 'booked');
        $this->travelTo('2026-11-10 08:15:00');
        $this->login()->assertStatus(422)->assertJsonPath('errors.national_number.0', __('exams.candidate.too_late', ['time' => '2026-11-10T08:15:00+00:00']));

        $this->asStaff($this->staff)->getJson(self::URL . "/{$session}/bookings")->assertOk()->assertJsonPath('data.0.status', 'absent');
        $this->assertSame(S::ExamAbsent, $application->fresh()->current_status);
        $event = $this->latestEvent($application);
        $this->assertSame($this->booking($application)['id'], $event->exam_booking_id);
        $this->assertSame('2026-11-10 08:15:00+00', $event->occurred_at);

        $this->artisan('exams:sweep')->assertSuccessful();   // again: nothing more
        $this->assertSame(2, DB::table('application_status_events')->where('application_id', $application->id)->count());
    }

    // ---- extensions ----

    public function test_more_time_for_one_attempt_or_for_everyone_needs_the_individual_permission(): void
    {
        $session = $this->examSession();
        $this->book($session, $this->applicant(), $this->applicant('22222222222'), $this->applicant('33333333333'))->assertCreated();
        $late = $this->started($session);
        $onTime = $this->started($session, '22222222222');
        $done = $this->started($session, '33333333333');
        $this->asCandidate($done)->postJson('/api/v1/candidate/exam/submit')->assertOk();
        $attempt = fn(string $number) => DB::table('exam_attempts')->join('exam_bookings as b', 'b.id', '=', 'exam_attempts.booking_id')->join('applications as a', 'a.id', '=', 'b.application_id')
            ->join('candidates as c', 'c.id', '=', 'a.candidate_id')->where('c.national_number', $number)->select('exam_attempts.*')->first();

        $this->asStaff($this->staff)->postJson('/api/v1/staff/exam-attempts/' . $attempt('01234567890')->id . '/extend', ['minutes' => 10, 'reason' => 'تأخر بعذر'])->assertForbidden();
        $manager = $this->allow($this->hiringStaff(), 'attempts.extend');

        $this->asStaff($manager)->postJson('/api/v1/staff/exam-attempts/' . $attempt('01234567890')->id . '/extend', ['minutes' => 10, 'reason' => 'تأخر بعذر'])
            ->assertOk()
            ->assertJsonPath('data.attempt.deadline_at', '2026-11-10T10:10:00+00:00')
            ->assertJsonPath('data.attempt.extensions.0.previous_deadline', '2026-11-10T10:00:00+00:00')
            ->assertJsonPath('data.attempt.extensions.0.for_everyone', false);
        $this->asStaff($manager)->postJson('/api/v1/staff/exam-attempts/' . $attempt('33333333333')->id . '/extend', ['minutes' => 10, 'reason' => 'x x x'])
            ->assertStatus(422)->assertJsonPath('errors.attempt.0', __('exams.attempts.not_running'));
        $this->asStaff($manager)->postJson('/api/v1/staff/exam-attempts/' . $attempt('01234567890')->id . '/extend', ['minutes' => 0, 'reason' => 'x x x'])->assertStatus(422);

        $this->asStaff($manager)->postJson(self::URL . "/{$session}/extend", ['minutes' => 15, 'reason' => 'انقطاع الكهرباء'])
            ->assertOk()->assertJsonPath('data.ends_at', '2026-11-10T10:15:00+00:00')->assertJsonPath('data.extensions.0.minutes', 15);
        $this->assertSame('2026-11-10 10:25:00+00', $attempt('01234567890')->deadline_at);
        $this->assertSame('2026-11-10 10:15:00+00', $attempt('22222222222')->deadline_at);
        $this->assertSame('2026-11-10 10:00:00+00', $attempt('33333333333')->deadline_at);   // closed: stays closed
        $this->assertSame(3, DB::table('exam_attempt_extensions')->count());
        $this->asCandidate($onTime)->getJson('/api/v1/candidate/exam')->assertJsonPath('exam.attempt.deadline_at', '2026-11-10T10:15:00+00:00');

        // Saving within the extended time works; after the session ends, no more extensions.
        $this->travelTo('2026-11-10 10:20:00');
        $this->asCandidate($late)->putJson('/api/v1/candidate/exam/answers/1', ['option' => 0])->assertOk();
        $this->asStaff($manager)->postJson(self::URL . "/{$session}/extend", ['minutes' => 5, 'reason' => 'x x x'])->assertStatus(422)->assertJsonPath('errors.session.0', __('exams.sessions.ended'));
    }

    // ---- results ----

    public function test_the_result_is_decided_when_the_hand_marks_are_in_and_moves_the_application(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();
        $token = $this->started($session);
        $this->answer($token);   // all right
        $this->travelTo('2026-11-10 09:30:00');
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk();

        $booking = $this->booking($application);
        $this->assertNull($booking['attempt']['result']);   // التجويد is missing
        $this->assertSame(10, $booking['attempt']['correct']);
        $this->assertSame(S::AwaitingExam, $application->fresh()->current_status);

        $this->assertSame(__('exams.marks.above_max', ['max' => '40.00']), $this->error($this->marks($booking['id'], '41'), "marks.{$this->tajweed}"));
        $this->asStaff($this->staff)->postJson("/api/v1/staff/exam-bookings/{$booking['id']}/marks", ['marks' => [['part_id' => 999999, 'mark' => '30']]])->assertStatus(422);

        $this->travelTo('2026-11-11 09:00:00');   // marked the next day: the decision is dated the exam day
        $result = $this->marks($booking['id'], '36')->assertOk()->json('data.attempt.result');
        $this->assertTrue($result['passed']);
        $this->assertSame('98.00', $result['average']);   // الحديث 100, القرآن 60 + 36 = 96
        $this->assertSame(1, $result['outcome']['policy_version']);

        $this->assertSame(S::AwaitingInterview, $application->fresh()->current_status);
        $event = $this->latestEvent($application);
        $this->assertSame($booking['id'], $event->exam_booking_id);
        $this->assertSame('outcome', $event->decision_type);
        $this->assertSame([$booking['id']], $this->staff->examBookings()->pluck('id')->all());
        $this->assertCount(1, $this->staff->examMarks);
        $this->assertCount(1, $this->staff->createdExamSessions);
        $this->assertSame('2026-11-10 09:30:00+00', $event->occurred_at);
        $this->asStaff($this->staff)->getJson("/api/v1/staff/exam-bookings/{$booking['id']}")->assertOk()->assertJsonPath('data.decided_at', '2026-11-11T09:00:00+00:00');
    }

    public function test_a_failed_exam_its_reasons_and_an_exception(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();
        $token = $this->started($session);
        $this->marks($this->booking($application)['id'], '40')->assertOk();    // the oral before the online part
        $this->answer($token, ['الحديث النبوي' => 2]);                         // 40% in الحديث: below the floor
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk();

        $result = $this->booking($application)['attempt']['result'];
        $this->assertFalse($result['passed']);
        $this->assertSame('subject_below_floor', $result['outcome']['reasons'][0]['code']);
        $this->assertSame(S::ExamFailed, $application->fresh()->current_status);

        $url = "/api/v1/staff/applications/{$application->id}/exam-exception";
        $this->asStaff($this->staff)->postJson($url, ['reason' => 'حاجة ماسة'])->assertForbidden();
        $approver = $this->allow($this->hiringStaff(), 'exceptions.approve');
        $this->asStaff($approver)->postJson($url, ['reason' => 'حاجة ماسة إلى الاختصاص'])->assertOk()->assertJsonPath('application_status', 'awaiting_interview');
        $this->assertSame('exception', $this->latestEvent($application)->decision_type);
        $this->assertFalse($this->booking($application)['attempt']['result']['passed']);   // the computed result stays failed
        $this->asStaff($approver)->postJson($url, ['reason' => 'مرة ثانية'])->assertStatus(422);
    }

    public function test_correcting_a_hand_mark(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();
        $token = $this->started($session);
        $booking = $this->booking($application)['id'];

        // Before the decision: changing a mark needs a reason.
        $this->marks($booking, '15')->assertOk();
        $this->assertSame(__('exams.marks.reason_required'), $this->error($this->marks($booking, '20'), "marks.{$this->tajweed}"));
        $this->marks($booking, '20', 'خطأ في الإدخال')->assertOk();

        // الحديث 3/5 = 60, القرآن 60 + 20 = 80: average 70, not above 70: failed.
        $this->answer($token, ['الحديث النبوي' => 3]);
        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk();
        $this->assertSame(S::ExamFailed, $application->fresh()->current_status);
        $this->assertSame('70.00', $this->booking($application)['attempt']['result']['average']);

        // After it: the individual permission too; the result is revised and the application corrected.
        $this->marks($booking, '30', 'أعيد التقييم')->assertForbidden();
        $corrector = $this->allow($this->hiringStaff(), 'exam_results.correct');
        $this->marks($booking, '30', null, $corrector)->assertStatus(422);
        $response = $this->marks($booking, '30', 'أعيد تقييم التجويد بحضور اللجنة', $corrector)->assertOk();

        $this->assertSame(['revision' => 2, 'passed' => true, 'average' => '75.00'], array_intersect_key($response->json('data.attempt.result'), array_flip(['revision', 'passed', 'average'])));
        $this->assertSame([1, 2, 3], array_column($response->json('data.attempt.marks'), 'revision'));
        $this->assertSame(S::AwaitingInterview, $application->fresh()->current_status);
        $event = $this->latestEvent($application);
        $this->assertSame(['correction', $booking], [$event->decision_type, $event->exam_booking_id]);
        $this->assertSame(2, DB::table('exam_results')->count());
    }

    public function test_a_plan_without_hand_marked_parts_is_decided_at_submission(): void
    {
        $position = $this->position('خطيب');
        $plan = $this->publishedPlan($position, $this->planContent([$this->subject('الفقه')]), $this->planner);
        $session = $this->examSession(['plan_version_id' => $plan->id]);
        $application = $this->openApplication($this->staff, ['job_position_id' => $position->id]);
        $this->book($session, $application)->assertCreated();
        $token = $this->started($session);
        $this->answer($token);

        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk();
        $this->assertSame(S::AwaitingInterview, $application->fresh()->current_status);
        $this->assertNull(DB::table('exam_results')->value('decided_by'));   // the system decided
    }

    public function test_an_accepted_excuse_books_the_next_round(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();
        $this->travelTo('2026-11-10 08:20:00');
        $this->artisan('exams:sweep')->assertSuccessful();
        $this->assertSame(S::ExamAbsent, $application->fresh()->current_status);

        $next = $this->examSession(['starts_at' => '2026-11-17T08:00:00Z', 'location' => 'قاعة 2']);
        $approver = $this->allow($this->hiringStaff(), 'exceptions.approve');
        $this->asStaff($approver)->postJson("/api/v1/staff/applications/{$application->id}/exam-excuse", ['reason' => 'تقرير طبي', 'session_id' => $next])
            ->assertOk()
            ->assertJsonPath('application_status', 'awaiting_exam')
            ->assertJsonPath('booking.round', 2)
            ->assertJsonPath('booking.session_id', $next);
        $this->assertSame('excuse_accepted', DB::table('application_status_events')->where('application_id', $application->id)->where('to_status', 'awaiting_exam')->orderByDesc('occurred_at')->value('decision_type'));
        $this->assertSame(['absent', 'booked'], DB::table('exam_bookings')->orderBy('round')->pluck('status')->all());
    }

    public function test_reading_and_managing_sessions(): void
    {
        $session = $this->examSession();
        $reader = $this->allow(StaffUserFactory::new()->create(), 'reports.view');

        $this->asStaff($reader)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'data');
        $this->asStaff($reader)->getJson(self::URL . "/{$session}")->assertOk();
        $this->asStaff($reader)->postJson(self::URL, [])->assertForbidden();
        $this->asStaff(StaffUserFactory::new()->create())->getJson(self::URL)->assertForbidden();
        $this->asStaff($reader)->getJson(self::URL . '?from=2026-11-11')->assertJsonCount(0, 'data');
    }

    // ---- the database ----

    public function test_the_database_keeps_the_exam_record_intact(): void
    {
        $session = $this->examSession();
        $application = $this->applicant();
        $this->book($session, $application)->assertCreated();
        $token = $this->started($session);
        $this->asCandidate($token)->putJson('/api/v1/candidate/exam/answers/1', ['option' => 0])->assertOk();
        $refused = function (string $case, callable $write, string $message): void {
            try {
                DB::transaction($write);
                $this->fail("The database accepted {$case}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
            }
        };

        $refused('changing the questions after a start', fn() => DB::table('exam_session_questions')->where('session_id', $session)->delete(), 'questions of a session that has started are frozen');
        $refused('moving a started session', fn() => DB::table('exam_sessions')->where('id', $session)->update(['starts_at' => '2026-11-10 09:00:00']), 'keeps its plan, start');
        $refused('deleting a booking', fn() => DB::table('exam_bookings')->delete(), 'never deleted');
        $refused('shortening an attempt', fn() => DB::table('exam_attempts')->update(['deadline_at' => '2026-11-10 09:00:00']), 'deadline only moves later');
        $refused('a hand mark above its scale', fn() => DB::table('exam_manual_marks')->insert(['attempt_id' => DB::table('exam_attempts')->value('id'), 'plan_part_id' => $this->tajweed, 'revision' => 1, 'mark' => 41, 'entered_by' => $this->staff->id, 'created_at' => now()]), 'cannot exceed');
        $refused('a second undecided booking', fn() => DB::table('exam_bookings')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'session_id' => $session, 'application_id' => $application->id, 'round' => 2, 'created_by' => $this->staff->id]), 'exam_bookings_one_open');

        $this->asCandidate($token)->postJson('/api/v1/candidate/exam/submit')->assertOk();
        $refused('reopening an attempt', fn() => DB::table('exam_attempts')->update(['status' => 'in_progress', 'submitted_at' => null, 'closed_by' => null]), 'never reopened');
        $refused('changing an answer after closing', fn() => DB::table('exam_attempt_items')->where('position', 1)->update(['selected_option' => 2]), 'closed attempt do not change');
        $refused('rewriting an answer history', fn() => DB::table('exam_answer_revisions')->update(['selected_option' => 2]), 'append-only');
        $refused('remarking an answer', fn() => DB::table('exam_attempt_items')->where('position', 1)->update(['is_correct' => false]), 'marked once');
    }
}
