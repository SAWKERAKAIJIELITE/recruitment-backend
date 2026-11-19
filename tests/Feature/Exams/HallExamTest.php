<?php

namespace Tests\Feature\Exams;

use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Support\HallBundle;
use App\Modules\Exams\Support\HallReceipt;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExamTestHelpers;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/**
 * Exams in a hall with no internet (D-102): a session taken out to the moderator's approved laptop, frozen on the
 * server while it is out, sat in the hall, and brought back sealed; the server checks it whole, then marks and decides
 * as if online. The plan: one subject of 5 questions, 120 minutes, late window 15; every bank question's correct
 * option is 0. The session: 2026-11-11 08:00 UTC (11:00 in Damascus).
 */
class HallExamTest extends TestCase
{
    use ExamTestHelpers, RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const URL = '/api/v1/staff/exam-sessions';

    private StaffUser $moderator;

    private string $token;

    private $key;

    private string $session;

    /** @var array<string, Application> A, B, C */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-11-10 09:00:00');

        // The owner chose: the Exams and Interviews office may take sessions out (granted by the administrator).
        Role::query()->where('slug', 'exams_interviews')->firstOrFail()->permissions()->attach(Permission::query()->where('slug', 'exams.offline')->value('id'));
        $this->moderator = $this->examsStaff();
        ['device' => $device, 'key' => $this->key] = $this->approvedDevice($this->moderator);
        $token = $this->moderator->createToken('laptop');
        $token->accessToken->forceFill(['staff_device_id' => $device->id])->save();
        $this->token = $token->plainTextToken;

        $plan = $this->plan();
        $this->session = $this->asModerator()->postJson(self::URL, [
            'plan_version_id' => $plan->id, 'starts_at' => '2026-11-11T08:00:00Z', 'location' => 'قاعة ناحية بعيدة', 'password' => 'qalam-2026',
        ])->assertCreated()->json('data.id');

        foreach (['A' => '01111111111', 'B' => '02222222222', 'C' => '03333333333'] as $name => $number) {
            $this->people[$name] = $this->openApplication($this->moderator, ['candidate' => ['national_number' => $number]]);
        }

        $this->asModerator()->postJson(self::URL . "/{$this->session}/bookings", ['application_ids' => array_map(fn(Application $a) => $a->id, array_values($this->people))])->assertCreated();
    }

    public function test_a_session_goes_to_the_hall_and_comes_back(): void
    {
        $download = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated();
        $package = $download->json();

        // What the laptop takes: the bookings and the questions, never the answer key.
        $this->assertCount(3, $package['bookings']);
        $this->assertCount(5, $package['questions']);
        $this->assertSame(['booking_id', 'national_number', 'full_name', 'nomination_number'], array_keys($package['bookings'][0]));
        $this->assertSame(['session_question_id', 'prompt', 'options'], array_keys($package['questions'][0]));
        $this->assertStringNotContainsString('correct', $download->getContent());
        $this->assertSame(['id', 'starts_at', 'late_minutes', 'duration_minutes', 'extra_minutes', 'location', 'job_position', 'plan'], array_keys($package['session']));

        $this->asModerator()->getJson(self::URL . "/{$this->session}")->assertOk()
            ->assertJsonPath('data.stage', 'out')
            ->assertJsonPath('data.hall.download_id', $package['download_id'])
            ->assertJsonPath('data.hall.by.id', $this->moderator->id)
            ->assertJsonPath('data.hall.overdue', false);
        $this->asModerator()->getJson('/api/v1/staff/badges')->assertOk()->assertJsonPath('data.exams', ['results_to_approve' => 0, 'hall_out' => 1, 'hall_overdue' => 0]);
        $this->asModerator()->getJson(self::URL . '?hall=out')->assertOk()->assertJsonCount(1, 'data');

        // While it is out, nothing about it changes, by any route; only its notes.
        $this->assertSame(__('exams.hall.out'), $this->asModerator()->patchJson(self::URL . "/{$this->session}", ['starts_at' => '2026-11-11T09:00:00Z'])->assertStatus(422)->json('errors.session.0'));
        $this->asModerator()->patchJson(self::URL . "/{$this->session}", ['notes' => 'الحافلة تنطلق 06:00'])->assertOk();
        $this->asModerator()->postJson(self::URL . "/{$this->session}/draw")->assertStatus(422)->assertJsonValidationErrors('session');
        $late = $this->openApplication($this->moderator, ['candidate' => ['national_number' => '04444444444']]);
        $this->asModerator()->postJson(self::URL . "/{$this->session}/bookings", ['application_ids' => [$late->id]])->assertStatus(422)->assertJsonValidationErrors('session');
        $bookingA = $this->bookingOf('A');
        $this->asModerator()->postJson("/api/v1/staff/exam-bookings/{$bookingA}/cancel", ['reason' => 'خطأ'])->assertStatus(422)->assertJsonValidationErrors('booking');

        try {
            DB::transaction(fn() => DB::table('exam_bookings')->where('id', $bookingA)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'من غير طريق']));   // a savepoint
            $this->fail('the database let a booking of a session that is out change');
        } catch (QueryException $e) {
            $this->assertStringContainsString('taken out to a hall', $e->getMessage());
        }

        // On the day: nobody sits it online, and the sweep leaves it alone.
        $this->travelTo('2026-11-11 07:30:00');
        $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => '01111111111', 'password' => 'qalam-2026'])->assertStatus(422)->assertJsonValidationErrors('national_number');
        $this->travelTo('2026-11-11 09:00:00');
        $this->artisan('exams:sweep')->assertSuccessful();
        $this->asModerator()->getJson(self::URL . "/{$this->session}/bookings")->assertOk();   // the sweep for this session too
        $this->assertSame(['booked'], DB::table('exam_bookings')->where('session_id', $this->session)->distinct()->pluck('status')->all());
        $this->assertSame(S::AwaitingExam, $this->people['C']->fresh()->current_status);

        // Back where there is internet. The hall began 10 minutes late; everyone got 10 more minutes at 08:30; B got 5
        // more of their own at 09:50 and ran out of time; C did not come.
        $this->travelTo('2026-11-11 12:00:00');
        $bundle = $this->bundle($package, [
            'started_at' => '2026-11-11T11:10:00+03:00', 'start_changed_reason' => 'تأخر وصول اللجنة',
            'extensions' => [['minutes' => 10, 'reason' => 'انقطاع الكهرباء', 'at' => '2026-11-11T08:30:00Z']],
            'attendance_count' => 2,
        ], [
            'A' => $this->attempt($package, 'A', '2026-11-11T08:12:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5, changedMind: true),
            'B' => $this->attempt($package, 'B', '2026-11-11T08:20:00Z', '2026-11-11T10:25:00Z', 'timeout', right: 1,
                own: [['minutes' => 5, 'reason' => 'ظرف صحي', 'at' => '2026-11-11T09:50:00Z']]),
            'C' => null,
        ]);
        $summary = $this->upload($bundle)->assertOk()->json();

        $this->assertSame([2, 1, 0], [$summary['sat'], $summary['absent'], $summary['cancelled']]);
        $this->assertSame(['sat', 'sat', 'absent'], array_column($summary['bookings'], 'outcome'));
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $summary['bookings'][0]['receipt']);

        // Marked and decided by the server, dated with the hall's times; the results wait for the approval (D-104).
        $this->assertSame(S::ExamTaken, $this->people['A']->fresh()->current_status);
        $this->assertSame(S::ExamTaken, $this->people['B']->fresh()->current_status);
        $this->assertSame(S::ExamAbsent, $this->people['C']->fresh()->current_status);
        $this->assertSame('2026-11-11 09:00:00', $this->movedAt('A'));
        $this->assertSame('2026-11-11 10:25:00', $this->movedAt('B'));
        $this->assertSame('2026-11-11 08:25:00', $this->movedAt('C'));   // the hall's late window closed (08:10 + 15)
        $this->asModerator()->postJson(self::URL . "/{$this->session}/results-approval")->assertOk()->assertJsonPath('meta', ['passed' => 1, 'failed' => 1, 'failed_by_decision' => 0]);
        $this->assertSame([S::AwaitingInterview, S::ExamFailed], [$this->people['A']->fresh()->current_status, $this->people['B']->fresh()->current_status]);

        $session = $this->asModerator()->getJson(self::URL . "/{$this->session}")->assertOk();
        $this->assertSame(['ended', null], [$session->json('data.stage'), $session->json('data.hall')]);
        $this->assertSame(['2026-11-11T08:10:00+00:00', '2026-11-11T08:00:00+00:00', 10], [$session->json('data.starts_at'), $session->json('data.planned_starts_at'), $session->json('data.extra_minutes')]);

        $b = $this->asModerator()->getJson("/api/v1/staff/exam-bookings/{$this->bookingOf('B')}")->assertOk();
        $this->assertSame(['2026-11-11T10:25:00+00:00', 'timeout', true, 5], [$b->json('data.attempt.deadline_at'), $b->json('data.attempt.closed_by'), $b->json('data.attempt.from_hall'), $b->json('data.attempt.extra_minutes')]);
        $this->assertSame([[10, true], [5, false]], array_map(fn(array $e) => [$e['minutes'], $e['for_everyone']], $b->json('data.attempt.extensions')));
        $this->assertSame($summary['bookings'][1]['receipt'], $b->json('data.attempt.receipt_code'));
        $this->assertSame(1, $b->json('data.attempt.correct'));
        $a = $this->attemptRow('A');
        $this->assertSame(6, DB::table('exam_answer_revisions as r')->join('exam_attempt_items as i', 'i.id', '=', 'r.attempt_item_id')->where('i.attempt_id', $a->id)->count());   // 5 answers, one changed

        $events = DB::table('audit_events')->pluck('event')->all();
        $this->assertContains('exam_hall.downloaded', $events);
        $this->assertContains('exam_hall.uploaded', $events);
        $this->assertSame(0, DB::table('exam_hall_downloads')->where('status', 'out')->count());

        // Sent again (the connection dropped): nothing changes, the same answer.
        $this->assertSame($summary, $this->upload($bundle)->assertOk()->json());
        $this->assertSame(2, DB::table('exam_attempts')->where('session_id', $this->session)->count());

        // Another bundle for the same download: refused.
        $other = $this->bundle($package, ['attendance_count' => 0], ['A' => null, 'B' => null, 'C' => null]);
        $this->assertSame(__('exams.hall.already_returned'), $this->upload($other)->assertStatus(422)->json('errors')['bundle.download_id'][0]);
    }

    public function test_the_upload_is_checked_whole_and_nothing_is_written_when_it_is_wrong(): void
    {
        $package = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json();
        $this->travelTo('2026-11-11 12:00:00');
        $good = fn(array $top = [], ?array $a = null) => $this->bundle($package, $top + ['attendance_count' => 1], [
            'A' => $a ?? $this->attempt($package, 'A', '2026-11-11T08:05:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5),
            'B' => null, 'C' => null,
        ]);

        // The seal: another key, or the text changed after sealing.
        $this->upload($good(), sign: $this->newDeviceKey()['key'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $sealed = $good();
        $this->upload(str_replace('"attendance_count":1', '"attendance_count": 1', $sealed), signature: $this->sign($this->key, HallBundle::payload($sealed)))
            ->assertStatus(422)->assertJsonValidationErrors('signature');

        $cases = [
            'bundle.bookings' => $this->bundle($package, ['attendance_count' => 0], ['A' => null, 'B' => null]),   // C missing
            'bundle.attendance_count' => $good(['attendance_count' => 2]),                                           // 1 sat, 2 signatures
            'bundle.started_at' => $good(['started_at' => '2026-11-11T07:59:00Z']),                                  // before the planned start
            'bundle.start_changed_reason' => $good(['started_at' => '2026-11-11T08:05:00Z']),                        // later, no reason
            'bundle.bookings.0.attempt.started_at' => $good([], $this->attempt($package, 'A', '2026-11-11T08:15:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5)),   // after the late window
            'bundle.bookings.0.attempt.submitted_at' => $good([], $this->attempt($package, 'A', '2026-11-11T08:05:00Z', '2026-11-11T09:59:00Z', 'timeout', right: 5)), // the deadline is 10:00
            'bundle.bookings.0.attempt.receipt' => $good([], ['receipt' => 'AAAA-BBBB-CCCC'] + $this->attempt($package, 'A', '2026-11-11T08:05:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5)),
            'bundle.bookings.0.attempt.items.0.revisions.0.selected_option' => $good([], $this->attempt($package, 'A', '2026-11-11T08:05:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5, option: 3)),   // 3 options: 0 to 2
            'bundle.sealed_at' => $good(['sealed_at' => '2026-11-11T08:50:00Z']),                                    // before the hand-in
        ];

        foreach ($cases as $key => $bundle) {
            $errors = $this->upload($bundle)->assertStatus(422)->json('errors');
            $this->assertArrayHasKey($key, $errors, json_encode($errors, JSON_UNESCAPED_UNICODE));
        }

        // Extensions given in the hall: uploaded only by who may extend.
        $permission = Permission::query()->where('slug', 'attempts.extend')->value('id');
        Role::query()->where('slug', 'exams_interviews')->firstOrFail()->permissions()->detach($permission);
        $extended = $good(['extensions' => [['minutes' => 5, 'reason' => 'انقطاع', 'at' => '2026-11-11T08:30:00Z']]]);
        $this->assertArrayHasKey('bundle.extensions', $this->upload($extended)->assertStatus(422)->json('errors'));

        // Nothing was written, and the session is still out.
        $this->assertSame(0, DB::table('exam_attempts')->count());
        $this->assertSame(['booked'], DB::table('exam_bookings')->distinct()->pluck('status')->all());
        $this->assertSame('out', DB::table('exam_hall_downloads')->value('status'));
    }

    public function test_the_download_rules(): void
    {
        // Too early: more than 48 hours before the start.
        $this->travelTo('2026-11-09 07:59:00');
        $this->assertStringContainsString('48', $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertStatus(422)->json('errors.session.0'));

        // Without the permission, or from a token with no approved device.
        $this->travelTo('2026-11-11 07:30:00');
        $this->asStaff($this->staffOf('exams'))->postJson(self::URL . "/{$this->session}/hall/download")->assertForbidden();
        $this->asStaff($this->moderator)->postJson(self::URL . "/{$this->session}/hall/download")->assertStatus(422)->assertJsonValidationErrors('device');

        // A candidate who logged in before the download loses that login.
        $candidate = $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => '01111111111', 'password' => 'qalam-2026'])->assertOk()->json('token');
        $first = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json('download_id');
        $this->app['auth']->forgetGuards();
        $this->withToken($candidate)->getJson('/api/v1/candidate/exam')->assertUnauthorized();

        // Taken out again before the start: the first copy stops counting.
        $second = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json();
        $this->assertNotSame($first, $second['download_id']);
        $this->assertSame(['replaced', 'out'], DB::table('exam_hall_downloads')->orderBy('downloaded_at')->orderBy('created_at')->pluck('status')->all());
        $stale = $this->bundle(['download_id' => $first] + $second, ['attendance_count' => 0], ['A' => null, 'B' => null, 'C' => null]);
        $this->travelTo('2026-11-11 12:00:00');
        $this->assertSame(__('exams.hall.replaced'), $this->upload($stale)->assertStatus(422)->json('errors')['bundle.download_id'][0]);

        // After the start: no download.
        $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/cancel", ['download_id' => $second['download_id'], 'reason' => 'تأجيل', 'questions_exposed' => false])->assertOk();
        $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertStatus(422)->assertJsonValidationErrors('session');
    }

    public function test_cancelling_before_and_after_the_start(): void
    {
        // Before the start: the session is back to normal and can be sat online.
        $download = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json('download_id');
        $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/cancel", ['download_id' => $download, 'reason' => 'وصل الإنترنت إلى القاعة', 'questions_exposed' => false])
            ->assertOk()->assertJsonPath('data.hall', null)->assertJsonPath('meta.redrawn', null);
        $this->asModerator()->patchJson(self::URL . "/{$this->session}", ['location' => 'قاعة الحاسوب 1'])->assertOk();
        $this->travelTo('2026-11-11 07:30:00');
        $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => '01111111111', 'password' => 'qalam-2026'])->assertOk();
        $this->assertSame(__('exams.hall.not_out'), $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/cancel", ['download_id' => $download, 'reason' => 'مرة ثانية', 'questions_exposed' => false])->assertStatus(422)->json('errors.download_id.0'));

        // Taken out again; the laptop is lost after the start. The candidates go back to the queue, the questions leave
        // the bank, and what comes back later is refused.
        $package = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json();
        $drawn = DB::table('exam_session_questions as q')->join('exam_question_versions as v', 'v.id', '=', 'q.question_version_id')->where('q.session_id', $this->session)->pluck('v.question_id')->all();
        $this->travelTo('2026-11-11 09:00:00');
        $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/cancel", ['download_id' => $package['download_id'], 'reason' => 'سُرق الحاسوب', 'questions_exposed' => true])->assertOk();

        $this->assertSame(['cancelled'], DB::table('exam_bookings')->where('session_id', $this->session)->distinct()->pluck('status')->all());
        $this->assertSame([S::AwaitingExam, S::AwaitingExam, S::AwaitingExam], array_map(fn(Application $a) => $a->fresh()->current_status, array_values($this->people)));
        $queue = array_column($this->asModerator()->getJson('/api/v1/staff/exams/queue')->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing(array_map(fn(Application $a) => $a->id, array_values($this->people)), array_values(array_intersect($queue, array_map(fn(Application $a) => $a->id, array_values($this->people)))));
        $this->assertSame(count($drawn), ExamQuestion::onlyTrashed()->whereKey($drawn)->count());
        $this->assertContains('exam_hall.cancelled', DB::table('audit_events')->pluck('event')->all());

        $late = $this->bundle($package, ['attendance_count' => 0], ['A' => null, 'B' => null, 'C' => null]);
        $this->travelTo('2026-11-11 12:00:00');
        $this->assertSame(__('exams.hall.cancelled'), $this->upload($late)->assertStatus(422)->json('errors')['bundle.download_id'][0]);
    }

    public function test_questions_exposed_before_the_start_are_drawn_again_when_the_bank_allows(): void
    {
        $download = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json('download_id');

        // The bank has 3 of each difficulty and the plan asks 2 easy, 2 medium, 1 hard: without the drawn ones it is short.
        $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/cancel", ['download_id' => $download, 'reason' => 'فُقد الحاسوب في الطريق', 'questions_exposed' => true])
            ->assertOk()->assertJsonPath('meta.redrawn', false);
        $this->assertSame(5, DB::table('exam_session_questions')->where('session_id', $this->session)->count());   // kept: draw before the session
    }

    public function test_applications_that_moved_while_out_keep_their_place(): void
    {
        $package = $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/download")->assertCreated()->json();

        // Recruitment closes A and C while the session is out: the hall does not know.
        $recruitment = $this->hiringStaff();
        foreach (['A', 'C'] as $person) {
            $this->asStaff($recruitment)->postJson("/api/v1/staff/applications/{$this->people[$person]->id}/close", ['reason' => 'انسحب'])->assertOk();
        }

        $this->travelTo('2026-11-11 12:00:00');
        $summary = $this->upload($this->bundle($package, ['attendance_count' => 2], [
            'A' => $this->attempt($package, 'A', '2026-11-11T08:05:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5),
            'B' => $this->attempt($package, 'B', '2026-11-11T08:05:00Z', '2026-11-11T09:00:00Z', 'candidate', right: 5),
            'C' => null,
        ]))->assertOk()->json();

        // A's result is kept, not applied; C's booking is cancelled, not an absence; B moves on (taken, waiting for the approval).
        $this->assertSame(['sat', 'sat', 'cancelled'], array_column($summary['bookings'], 'outcome'));
        $this->assertSame([S::Closed, S::ExamTaken, S::Closed], array_map(fn(Application $a) => $a->fresh()->current_status, array_values($this->people)));
        $this->assertSame(1, DB::table('exam_results')->where('attempt_id', $this->attemptRow('A')->id)->count());
    }

    public function test_a_hand_marked_part_waits_after_the_upload(): void
    {
        // A second session on a plan with the Quran from its parts: التلاوة in the exam, التجويد marked by hand.
        $position = JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']);
        $plan = $this->publishedPlan($position, $this->planContent([$this->subject('الفقه')], $this->quran()), $this->examManager());
        $session = $this->asModerator()->postJson(self::URL, ['plan_version_id' => $plan->id, 'starts_at' => '2026-11-11T12:00:00Z', 'location' => 'قاعة ثانية', 'password' => 'qalam-2026'])->assertCreated()->json('data.id');
        $this->people['D'] = $this->openApplication($this->moderator, ['candidate' => ['national_number' => '04444444444']]);
        $this->asModerator()->postJson(self::URL . "/{$session}/bookings", ['application_ids' => [$this->people['D']->id]])->assertCreated();
        $main = $this->session;
        $this->session = $session;

        $package = $this->asModerator()->postJson(self::URL . "/{$session}/hall/download")->assertCreated()->json();
        $this->assertCount(10, $package['questions']);   // the hand-marked part has no questions
        $this->travelTo('2026-11-11 16:00:00');
        $this->upload($this->bundle($package, ['started_at' => '2026-11-11T12:00:00Z', 'sealed_at' => '2026-11-11T14:00:00Z', 'attendance_count' => 1], [
            'D' => $this->attempt($package, 'D', '2026-11-11T12:03:00Z', '2026-11-11T13:00:00Z', 'candidate', right: 10),
        ]))->assertOk()->assertJsonPath('sat', 1);

        // Closed and marked, waiting for التجويد, entered through the usual route; then decided.
        $this->assertSame(S::AwaitingExam, $this->people['D']->fresh()->current_status);
        $tajweed = (int) DB::table('exam_plan_subject_parts')->where('plan_version_id', $plan->id)->where('assessment', 'manual')->value('id');
        $this->asModerator()->postJson("/api/v1/staff/exam-bookings/{$this->bookingOf('D')}/marks", ['marks' => [['part_id' => $tajweed, 'mark' => '36']]])->assertOk();
        $this->assertSame(S::ExamTaken, $this->people['D']->fresh()->current_status);
        $this->assertSame('2026-11-11 13:00:00', $this->movedAt('D'));   // dated the hand-in in the hall
        $this->session = $main;
    }

    public function test_the_receipt_is_the_documented_one(): void
    {
        // The example in docs/hall-exam-reply.md: the laptop must compute exactly this.
        $this->assertSame('1C94-230D-861B', HallReceipt::code('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', 1795252200, [
            ['position' => 1, 'session_question_id' => 9007, 'option' => 2],
            ['position' => 2, 'session_question_id' => 9001, 'option' => null],
            ['position' => 3, 'session_question_id' => 9004, 'option' => 0],
        ]));
        $this->assertSame('1C94-230D-861B', HallReceipt::normalize('1c94 230d 861b'));
    }

    // ---- helpers ----

    private function plan(): ExamPlanVersion
    {
        $position = JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']);

        return $this->publishedPlan($position, $this->planContent([$this->subject()]), $this->examManager());
    }

    private function asModerator(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function bookingOf(string $person): string
    {
        return DB::table('exam_bookings')->where('application_id', $this->people[$person]->id)->value('id');
    }

    private function attemptRow(string $person): object
    {
        return DB::table('exam_attempts')->where('booking_id', $this->bookingOf($person))->first();
    }

    private function movedAt(string $person): string
    {
        return CarbonImmutable::parse(DB::table('application_status_events')->where('application_id', $this->people[$person]->id)->orderByDesc('occurred_at')->value('occurred_at'))->utc()->format('Y-m-d H:i:s');
    }

    /**
     * One candidate's hall attempt, as the laptop writes it: the questions in this candidate's own order (the session's
     * reversed), the first $right answered right (option 0), one save each a minute after the start (two for the first
     * when they changed their mind), and the receipt.
     */
    private function attempt(array $package, string $person, string $startedAt, string $submittedAt, string $closedBy, int $right, array $own = [], bool $changedMind = false, ?int $option = null): array
    {
        $booking = $this->bookingOf($person);
        $first = CarbonImmutable::parse($startedAt)->addMinute();
        $items = [];

        foreach (array_reverse(array_column($package['questions'], 'session_question_id')) as $index => $question) {
            $chosen = $option ?? ($index < $right ? 0 : 1);
            $revisions = [['selected_option' => $chosen, 'saved_at' => $first->addSeconds($index)->toIso8601ZuluString()]];

            if ($changedMind && $index === 0) {
                $revisions = [['selected_option' => 1, 'saved_at' => $first->toIso8601ZuluString()], ['selected_option' => $chosen, 'saved_at' => $first->addSeconds(30)->toIso8601ZuluString()]];
            }

            $items[] = ['position' => $index + 1, 'session_question_id' => $question, 'revisions' => $revisions];
        }

        $receipt = HallReceipt::code($booking, CarbonImmutable::parse($submittedAt)->getTimestamp(), array_map(fn(array $item) => [
            'position' => $item['position'], 'session_question_id' => $item['session_question_id'], 'option' => end($item['revisions'])['selected_option'],
        ], $items));

        return ['started_at' => $startedAt, 'submitted_at' => $submittedAt, 'closed_by' => $closedBy, 'extensions' => $own, 'receipt' => $receipt, 'items' => $items];
    }

    /** @param array<string, ?array> $attempts by person, in booking order */
    private function bundle(array $package, array $top, array $attempts): string
    {
        $bookings = [];

        foreach ($attempts as $person => $attempt) {
            $bookings[] = ['booking_id' => $this->bookingOf($person), 'attempt' => $attempt];
        }

        return json_encode($top + [
            'version' => 1,
            'download_id' => $package['download_id'],
            'session_id' => $this->session,
            'started_at' => '2026-11-11T08:00:00Z',
            'start_changed_reason' => null,
            'sealed_at' => '2026-11-11T10:30:00Z',
            'committee' => ['أحمد السالم', 'محمد العلي'],
            'attendance_count' => 0,
            'extensions' => [],
            'bookings' => $bookings,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function upload(string $bundle, $sign = null, ?string $signature = null): TestResponse
    {
        return $this->asModerator()->postJson(self::URL . "/{$this->session}/hall/upload", [
            'bundle' => $bundle,
            'signature' => $signature ?? $this->sign($sign ?? $this->key, HallBundle::payload($bundle)),
        ]);
    }
}
