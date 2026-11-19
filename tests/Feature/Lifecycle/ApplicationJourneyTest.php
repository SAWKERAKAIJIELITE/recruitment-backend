<?php

namespace Tests\Feature\Lifecycle;

use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ExamTestHelpers;
use Tests\Support\LegacyWorkbook;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/**
 * The whole lifecycle of an application through the API, office by office, and every branch of it (analysis doc
 * §04 to §08). Each step is taken by the office whose section it is, as a real user of that role, and checked on the
 * application, its history, its stages and the counts the offices see.
 *
 *   received ─start→ awaiting_exam ─take→ exam_taken ─approve: pass→ awaiting_interview ─pass→ awaiting_contract ─write→ contract_written ─end→ contract_ended
 *                      │  ↑ excuse                │                  ↑ exception  │  ↑ excuse
 *                      ├→ exam_absent             └─approve: fail→ exam_failed    ├→ interview_absent
 *                      │                                                          └→ interview_failed
 *                      (an exam result waits in exam_taken until the exams office approves the session's results, D-104)
 *   and closed from every status that waits (received to awaiting_contract).
 */
class ApplicationJourneyTest extends TestCase
{
    use ExamTestHelpers, RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private const PASSWORD = 'qalam-2026';

    private const GRADES = ['scientific_grade' => 'very_good', 'general_grade' => 'good', 'overall_grade' => 'very_good'];

    private StaffUser $recruitment;

    private StaffUser $exams;

    private StaffUser $interviews;

    private StaffUser $contracts;

    private StaffUser $manager;

    private ExamPlanVersion $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->travelTo('2026-11-01 07:00:00');
        $this->referenceIds();
        $this->recruitment = $this->hiringStaff();
        $this->exams = $this->staffOf('exams');
        $this->interviews = $this->staffOf('interviews');
        $this->contracts = $this->contractsStaff();
        $this->manager = $this->staffOf('general_manager');
        // The exam of the job position: one subject marked by the system, five questions (correct option 0).
        $this->plan = $this->publishedPlan(JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']), $this->planContent([$this->subject()]), $this->exams);
        $this->openVacancies(3);
    }

    // ---- the offices' steps ----

    /** Recruitment: the import of the legacy template, reviewed and approved; returns the applications by national number. */
    private function imported(array $people): array
    {
        $rows = array_map(fn(string $name, string $number) => LegacyWorkbook::row(['F' => $name, 'J' => $number]), $people, array_keys($people));
        $batch = $this->asStaff($this->recruitment)->post('/api/v1/staff/imports', ['file' => LegacyWorkbook::file($rows, [], 'candidates.xlsx')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.counts.valid', count($people))->json('data');
        $this->asStaff($this->recruitment)->postJson("/api/v1/staff/imports/{$batch['id']}/approve", ['revision' => $batch['revision']])->assertOk();

        return Application::query()->with('candidate')->get()->keyBy(fn(Application $application) => $application->candidate->national_number)->all();
    }

    private function statusOf(Application $application): string
    {
        return $this->asStaff($this->recruitment)->getJson("/api/v1/staff/applications/{$application->id}")->assertOk()->json('data.status');
    }

    /** @return array<string, string> step => state */
    private function stages(Application $application): array
    {
        $stages = $this->asStaff($this->recruitment)->getJson("/api/v1/staff/applications/{$application->id}")->assertOk()->json('data.stages');

        return array_column($stages, 'state', 'step');
    }

    /** @return list<string> the history's moves, "from → to (decision)" */
    private function history(Application $application): array
    {
        return array_map(
            fn(array $event) => ($event['from_status'] ?? '·') . " → {$event['to_status']} ({$event['decision_type']})",
            $this->asStaff($this->recruitment)->getJson("/api/v1/staff/applications/{$application->id}")->assertOk()->json('data.history'),
        );
    }

    /** The exams office approves a session's results (D-104), once it has ended. */
    private function approveResults(string $session): TestResponse
    {
        return $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-sessions/{$session}/results-approval");
    }

    private function examSession(string $startsAt): string
    {
        return $this->asStaff($this->exams)->postJson('/api/v1/staff/exam-sessions', [
            'plan_version_id' => $this->plan->id, 'starts_at' => $startsAt, 'location' => 'قاعة الحاسوب', 'password' => self::PASSWORD,
        ])->assertCreated()->json('data.id');
    }

    /** A candidate logs in at the venue, starts, answers ($right of the five right) and hands in. */
    private function sitExam(string $nationalNumber, int $right): void
    {
        $this->app['auth']->forgetGuards();
        $token = $this->withoutToken()->postJson('/api/v1/candidate/auth/login', ['national_number' => $nationalNumber, 'password' => self::PASSWORD])->assertOk()->json('token');
        $candidate = function () use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token);
        };
        $candidate()->postJson('/api/v1/candidate/exam/start')->assertOk();

        foreach ($candidate()->getJson('/api/v1/candidate/exam/questions')->assertOk()->json('data') as $index => $question) {
            $candidate()->putJson("/api/v1/candidate/exam/answers/{$question['position']}", ['option' => $index < $right ? 0 : 1])->assertOk();
        }

        $candidate()->postJson('/api/v1/candidate/exam/submit')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withoutToken();
    }

    private function sitting(string $startsAt, string $endsAt): string
    {
        return $this->asStaff($this->interviews)->postJson('/api/v1/staff/interview-sittings', [
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'location' => 'قاعة المقابلات', 'committee_name' => 'لجنة دمشق الأولى',
            'members' => [['name' => 'الشيخ محمد السالم', 'capacity' => 'chair'], ['staff_user_id' => $this->interviews->id, 'capacity' => 'secretary']],
        ])->assertCreated()->json('data.id');
    }

    /** The application's interview waiting for its result. */
    private function scheduledInterview(Application $application): string
    {
        return collect($this->asStaff($this->interviews)->getJson("/api/v1/staff/applications/{$application->id}/interviews")->assertOk()->json('data'))
            ->firstWhere('status', 'scheduled')['id'];
    }

    private function record(Application $application, string $result): TestResponse
    {
        return $this->asStaff($this->interviews)->postJson('/api/v1/staff/interviews/' . $this->scheduledInterview($application) . '/result', ['result' => $result] + ($result === 'absent' ? [] : self::GRADES));
    }

    private function writeContract(Application $application, string $reference, array $fields = []): TestResponse
    {
        return $this->asStaff($this->contracts)->postJson("/api/v1/staff/applications/{$application->id}/contracts", $fields + [
            'contract_type_id' => $this->contractType(), 'reference_number' => $reference, 'starts_on' => '2026-11-15', 'ends_on' => '2027-11-14',
        ]);
    }

    private function contractType(): int
    {
        return (int) (DB::table('contract_types')->value('id')
            ?? $this->asStaff($this->contracts)->postJson('/api/v1/staff/reference/contract-types', ['name_ar' => 'عقد سنوي'])->assertCreated()->json('item.id'));
    }

    /** @return array<string, int> status => count, as the list's tiles show them */
    private function counts(): array
    {
        $statuses = $this->asStaff($this->recruitment)->getJson('/api/v1/staff/applications/counts')->assertOk()->json('data.statuses');

        return array_filter(array_column($statuses, 'count', 'status'));
    }

    // ---- the journeys ----

    public function test_four_candidates_through_every_office_and_every_way_back(): void
    {
        // 1. Recruitment imports four people; they wait to be started, then are started together.
        $people = ['01111111111' => 'أحمد محمد علي', '02222222222' => 'سارة خالد عمر', '03333333333' => 'محمود سعيد حسن', '04444444444' => 'ليلى أحمد ناصر'];
        ['01111111111' => $a, '02222222222' => $b, '03333333333' => $c, '04444444444' => $d] = $this->imported($people);
        $this->assertSame(['received' => 4], $this->counts());
        $this->assertSame('current', $this->stages($a)['nomination']);
        $batch = DB::table('import_batches')->value('id');
        $this->asStaff($this->recruitment)->postJson('/api/v1/staff/applications/start', ['filters' => ['import_batch_id' => $batch]])->assertOk();
        $this->assertSame(['awaiting_exam' => 4], $this->counts());
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $this->exams->id)->count());   // the exams are told
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->interviews->id)->count());      // not the interviews

        // 2. The exams: one session for all four, from the queue.
        $first = $this->examSession('2026-11-02T08:00:00Z');
        $queue = array_column($this->asStaff($this->exams)->getJson('/api/v1/staff/exams/queue')->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id], $queue);
        $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-sessions/{$first}/bookings", ['application_ids' => $queue])->assertCreated();
        $this->asStaff($this->interviews)->getJson('/api/v1/staff/exams/queue')->assertForbidden();                 // not the interviews' section

        $this->travelTo('2026-11-02 08:01:00');
        $this->sitExam('01111111111', 5);   // A passes
        $this->sitExam('02222222222', 1);   // B fails
        $this->sitExam('04444444444', 5);   // D passes; C does not come
        $this->travelTo('2026-11-02 08:20:00');
        $this->artisan('exams:sweep')->assertSuccessful();
        $this->assertSame(['exam_taken', 'exam_taken', 'exam_absent', 'exam_taken'], [$this->statusOf($a), $this->statusOf($b), $this->statusOf($c), $this->statusOf($d)]);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->interviews->id)->count());     // nothing passed yet
        $this->assertSame(1, $this->asStaff($this->exams)->getJson('/api/v1/staff/badges')->json('data.exams.results_to_approve'));

        // The exams office approves the session once it has ended (D-104): now they pass or fail.
        $this->approveResults($first)->assertStatus(422)->assertJsonValidationErrors('session');                        // not ended yet
        $this->travelTo('2026-11-02 10:00:00');
        $this->approveResults($first)->assertOk()->assertJsonPath('meta', ['passed' => 2, 'failed' => 1, 'failed_by_decision' => 0]);
        $this->assertSame(['awaiting_interview', 'exam_failed', 'exam_absent', 'awaiting_interview'], [$this->statusOf($a), $this->statusOf($b), $this->statusOf($c), $this->statusOf($d)]);
        $this->assertSame(['nomination' => 'done', 'exam' => 'failed', 'interview' => 'pending', 'contract' => 'pending'], $this->stages($b));
        $this->assertGreaterThan(0, DB::table('notifications')->where('notifiable_id', $this->interviews->id)->count());   // the interviews are told now

        // 3. The exams office's decisions: B admitted by exception, C's absence excused with a second round.
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$b->id}/exam-exception", ['reason' => 'قرار اللجنة'])->assertForbidden();
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$b->id}/exam-exception", ['reason' => 'قرار لجنة الاختبارات', 'decision_number' => 'ق/2026/7'])->assertOk();
        $second = $this->examSession('2026-11-05T08:00:00Z');
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$c->id}/exam-excuse", ['reason' => 'تقرير طبي', 'session_id' => $second, 'decision_number' => 'ق/2026/8'])
            ->assertOk()->assertJsonPath('booking.round', 2);
        $this->travelTo('2026-11-05 08:01:00');
        $this->sitExam('03333333333', 5);
        $this->travelTo('2026-11-05 10:00:00');
        $this->approveResults($second)->assertOk()->assertJsonPath('meta.passed', 1);
        $this->assertSame(['awaiting_interview', 'awaiting_interview'], [$this->statusOf($b), $this->statusOf($c)]);
        $rounds = $this->asStaff($this->exams)->getJson("/api/v1/staff/applications/{$c->id}/exam-bookings")->assertOk()->json('data');
        $this->assertSame(['absent', 'ق/2026/8', '2026-11-05T08:00:00+00:00'], [$rounds[0]['status'], $rounds[0]['decision']['decision_number'], $rounds[1]['session']['starts_at']]);
        $this->assertSame(['to_book' => 4, 'to_record' => 0, 'total' => 4], $this->asStaff($this->interviews)->getJson('/api/v1/staff/badges')->assertOk()->json('data.interviews'));

        // 4. The interviews: one sitting, the queue booked 30 minutes apart; A and C pass, B is absent, D fails.
        $this->travelTo('2026-11-06 09:00:00');
        $sitting = $this->sitting('2026-11-08T08:00:00Z', '2026-11-08T14:00:00Z');
        $interviewQueue = array_column($this->asStaff($this->interviews)->getJson('/api/v1/staff/interviews/queue')->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$a->id, $b->id, $c->id, $d->id], $interviewQueue);
        $booked = $this->asStaff($this->interviews)->postJson("/api/v1/staff/interview-sittings/{$sitting}/bookings", ['application_ids' => [$a->id, $b->id, $c->id, $d->id], 'minutes_between' => 30])
            ->assertCreated()->json('data');
        $this->assertSame(['2026-11-08T08:00:00+00:00', '2026-11-08T08:30:00+00:00', '2026-11-08T09:00:00+00:00', '2026-11-08T09:30:00+00:00'], array_column($booked, 'scheduled_at'));
        $this->travelTo('2026-11-08 10:00:00');
        $this->assertSame(4, $this->asStaff($this->interviews)->getJson('/api/v1/staff/badges')->json('data.interviews.to_record'));
        $this->record($a, 'passed')->assertOk();
        $this->record($b, 'absent')->assertOk();
        $this->record($c, 'passed')->assertOk();
        $this->record($d, 'failed')->assertOk();
        $this->assertSame(['interview_failed' => 1, 'interview_absent' => 1, 'awaiting_contract' => 2], $this->counts());

        // B's absence excused by the interviews officer, rebooked the same day, and passed.
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$b->id}/interview-excuse", ['reason' => 'ظرف طارئ'])->assertForbidden();
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$b->id}/interview-excuse", [
            'reason' => 'ظرف طارئ', 'decision_number' => 'ق/2026/9', 'appointment' => ['sitting_id' => $sitting, 'scheduled_at' => '2026-11-08T11:00:00Z'],
        ])->assertCreated()->assertJsonPath('data.round', 2);
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$d->id}/interview-excuse", ['reason' => 'لا عذر للراسب'])->assertStatus(422);   // a failure has no excuse
        $this->travelTo('2026-11-08 11:30:00');
        $this->record($b, 'passed')->assertOk();
        $this->assertSame(['contracts' => ['awaiting_contract' => 3, 'ending_soon' => 0, 'expired' => 0]], array_intersect_key($this->asStaff($this->contracts)->getJson('/api/v1/staff/badges')->json('data'), ['contracts' => 1]));

        // 5. Recruitment closes D, who failed the interview.
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/applications/{$d->id}/close", ['reason' => 'راسب بالمقابلة'])->assertForbidden();
        $this->asStaff($this->recruitment)->postJson("/api/v1/staff/applications/{$d->id}/close", ['reason' => 'راسب بالمقابلة'])->assertOk()->assertJsonPath('data.status', 'closed');

        // 6. The Contracts office: a unit and a job code, then the first contracts within the vacancies.
        $this->travelTo('2026-11-10 09:00:00');
        $unit = $this->asStaff($this->contracts)->postJson('/api/v1/staff/reference/units', ['name_ar' => 'هيئة التوجيه المعنوي'])->assertCreated()->json('item.id');
        $this->asStaff($this->contracts)->putJson("/api/v1/staff/job-positions/{$a->job_position_id}/job-code", ['job_code' => 'JC-101'])->assertOk();
        $aContract = $this->writeContract($a, 'ع/2026/1', ['unit_id' => $unit, 'salary_usd' => 350, 'certificate_type' => 'bachelor_4', 'wives_count' => 0, 'children_count' => 0])
            ->assertCreated()->assertJsonPath('data.application.job_code', 'JC-101')->json('data.id');
        $this->writeContract($b, 'ع/2026/2')->assertCreated();
        $cContract = $this->writeContract($c, 'ع/2026/3', ['starts_on' => '2026-11-10', 'ends_on' => '2026-11-20'])->assertCreated()->json('data.id');
        $this->writeContract($a, 'ع/2026/9')->assertStatus(422);                                                   // A has one already
        $this->assertSame(['contract_written' => 3, 'closed' => 1], $this->counts());

        // The contract form and ملف التثبيت.
        $this->asStaff($this->contracts)->get("/api/v1/staff/contracts/{$aContract}/form")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->asStaff($this->contracts)->get('/api/v1/staff/contracts/confirmation-file?written_from=2026-11-10')->assertOk();

        // 7. A's contract renewed; C's ends without renewal, so C's file ends too.
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$aContract}/renew", ['contract_type_id' => $this->contractType(), 'reference_number' => 'ت/2027/1', 'ends_on' => '2028-11-14'])
            ->assertCreated()->assertJsonPath('data.sequence', 2)->assertJsonPath('data.unit.id', $unit);
        $this->assertSame(['awaiting_contract' => 0, 'ending_soon' => 1, 'expired' => 0], $this->asStaff($this->contracts)->getJson('/api/v1/staff/badges')->json('data.contracts'));   // C's started, ends on the 20th
        $this->travelTo('2026-12-01 09:00:00');
        $this->assertSame(1, $this->asStaff($this->contracts)->getJson('/api/v1/staff/badges')->json('data.contracts.expired'));   // past its end, not renewed
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$cContract}/end", ['ended_on' => '2026-11-20', 'reason' => 'انتهت المدة دون تجديد'])->assertOk();
        $this->assertSame('contract_ended', $this->statusOf($c));
        $this->assertSame(['nomination' => 'done', 'exam' => 'done', 'interview' => 'done', 'contract' => 'ended'], $this->stages($c));

        // 8. An ended file frees the person: C and D can be nominated again, A (under contract) cannot.
        foreach (['03333333333' => 201, '04444444444' => 201, '01111111111' => 422] as $number => $expected) {
            $this->asStaff($this->recruitment)->postJson('/api/v1/staff/applications', $this->applicationPayload(['candidate' => ['national_number' => $number, 'full_name' => $people[$number]]]))
                ->assertStatus($expected);
        }

        // 9. What each file says about its way: the history, the stages, the decisions' numbers.
        $this->assertSame([
            '· → received (created)', 'received → awaiting_exam (started)', 'awaiting_exam → exam_taken (outcome)',
            'exam_taken → exam_failed (outcome)', 'exam_failed → awaiting_interview (exception)', 'awaiting_interview → interview_absent (outcome)',
            'interview_absent → awaiting_interview (excuse_accepted)', 'awaiting_interview → awaiting_contract (outcome)',
            'awaiting_contract → contract_written (outcome)',
        ], $this->history($b));
        $this->assertSame(['ق/2026/7', 'ق/2026/9'], array_values(array_filter(array_column($this->asStaff($this->recruitment)->getJson("/api/v1/staff/applications/{$b->id}")->json('data.history'), 'decision_number'))));
        $this->assertSame(['nomination' => 'done', 'exam' => 'done', 'interview' => 'done', 'contract' => 'done'], $this->stages($a));
        $this->assertSame(['nomination' => 'done', 'exam' => 'done', 'interview' => 'failed', 'contract' => 'pending'], $this->stages($d));

        // 10. The general manager's dashboard counts the same story; the contracts report has its four contracts.
        $totals = $this->asStaff($this->manager)->getJson('/api/v1/staff/reports/summary')->assertOk()->json('data.totals');
        $this->assertSame(6, $totals['nominations']);                                                               // four imported, two again
        $this->assertSame([4, 3, 1, 1, 1, 1], [$totals['exams']['takers'], $totals['exams']['passed'], $totals['exams']['failed'], $totals['exams']['passed_by_exception'], $totals['exams']['absent'], $totals['exams']['absent_excused']]);
        $this->assertSame([4, 3, 1, 1, 1], [$totals['interviews']['takers'], $totals['interviews']['passed'], $totals['interviews']['failed'], $totals['interviews']['absent'], $totals['interviews']['absent_excused']]);
        $this->assertSame(['written' => 3, 'renewals' => 1, 'ended' => 1], $totals['contracts']);
        $this->asStaff($this->manager)->get('/api/v1/staff/reports/contracts')->assertOk();
        $this->asStaff($this->manager)->get("/api/v1/staff/applications/{$a->id}/results-statement")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // And the audit log kept every office's decision.
        foreach (['import.approved', 'exam.exception_approved', 'exam.excuse_accepted', 'interview_sitting.created', 'interview.recorded', 'interview.excuse_accepted', 'contract.written', 'contract.renewed', 'contract.ended', 'contract.form_printed', 'contract.confirmation_file'] as $event) {
            $this->assertTrue(DB::table('audit_events')->where('event', $event)->exists(), $event);
        }
    }

    public function test_an_application_is_closed_wherever_it_waits_and_only_there(): void
    {
        $names = ['أحمد محمد علي', 'سارة خالد عمر', 'محمود سعيد حسن', 'ليلى أحمد ناصر', 'يوسف عمر شاكر', 'هبة سامر نجم', 'كريم ماهر سليم', 'رنا وليد حداد'];
        $people = [];

        foreach ($names as $index => $name) {
            $people[sprintf('%011d', 50000000001 + $index)] = $name;
        }

        $applications = array_values($this->imported($people));
        [$received, $awaitingExam, $examFailed, $examAbsent, $awaitingInterview, $interviewAbsent, $awaitingContract, $written] = $applications;
        $this->asStaff($this->recruitment)->postJson('/api/v1/staff/applications/start', ['application_ids' => array_map(fn(Application $application) => $application->id, array_slice($applications, 1))])->assertOk();

        // The exam: one fails, one is absent, the others pass; one stays waiting for its exam.
        $session = $this->examSession('2026-11-02T08:00:00Z');
        $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-sessions/{$session}/bookings", ['application_ids' => [$examFailed->id, $examAbsent->id, $awaitingInterview->id, $interviewAbsent->id, $awaitingContract->id, $written->id]])->assertCreated();
        $this->travelTo('2026-11-02 08:01:00');
        $this->sitExam($examFailed->candidate->national_number, 0);

        foreach ([$awaitingInterview, $interviewAbsent, $awaitingContract, $written] as $application) {
            $this->sitExam($application->candidate->national_number, 5);
        }

        $this->travelTo('2026-11-02 10:00:00');
        $this->approveResults($session)->assertOk();

        // The interview: one absent, two pass; one stays waiting for its interview.
        $this->travelTo('2026-11-06 09:00:00');
        $sitting = $this->sitting('2026-11-08T08:00:00Z', '2026-11-08T14:00:00Z');
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/interview-sittings/{$sitting}/bookings", ['application_ids' => [$interviewAbsent->id, $awaitingContract->id, $written->id]])->assertCreated();
        $this->travelTo('2026-11-08 12:00:00');
        $this->record($interviewAbsent, 'absent')->assertOk();
        $this->record($awaitingContract, 'passed')->assertOk();
        $this->record($written, 'passed')->assertOk();
        $this->writeContract($written, 'ع/2026/50')->assertCreated();

        $expected = ['received', 'awaiting_exam', 'exam_failed', 'exam_absent', 'awaiting_interview', 'interview_absent', 'awaiting_contract', 'contract_written'];
        $this->assertSame($expected, array_map(fn(Application $application) => $this->statusOf($application), $applications));

        // Every status that waits is closed by Recruitment, with a reason; a written contract is not (it ends instead).
        $close = fn(Application $application) => $this->asStaff($this->recruitment)->postJson("/api/v1/staff/applications/{$application->id}/close", ['reason' => 'انسحب المرشح']);
        $this->asStaff($this->recruitment)->postJson("/api/v1/staff/applications/{$received->id}/close", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        foreach (array_slice($applications, 0, 7) as $index => $application) {
            $close($application)->assertOk()->assertJsonPath('data.status', 'closed');
            $closedStage = array_search('closed', $this->stages($application), true);
            $this->assertSame(['nomination', 'exam', 'exam', 'exam', 'interview', 'interview', 'contract'][$index], $closedStage === false ? $this->failedStage($application) : $closedStage, $expected[$index]);
        }

        $close($written)->assertStatus(422);
        $this->assertSame('contract_written', $this->statusOf($written));

        // Once closed nothing moves it: not again, not started, not excused, not contracted.
        $close($received)->assertStatus(422);
        $this->asStaff($this->recruitment)->postJson("/api/v1/staff/applications/{$received->id}/start")->assertStatus(422);
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$examAbsent->id}/exam-excuse", ['reason' => 'تقرير طبي'])->assertStatus(422);
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$examFailed->id}/exam-exception", ['reason' => 'قرار اللجنة'])->assertStatus(422);
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$interviewAbsent->id}/interview-excuse", ['reason' => 'ظرف طارئ'])->assertStatus(422);
        $this->writeContract($awaitingContract, 'ع/2026/51')->assertStatus(422);
        $this->assertSame(['contract_written' => 1, 'closed' => 7], $this->counts());
    }

    /** The stage a closed application stopped at when its own state there is failed or absent. */
    private function failedStage(Application $application): string
    {
        return (string) array_search(true, array_map(fn(string $state) => in_array($state, ['failed', 'absent'], true), $this->stages($application)), true);
    }

    public function test_a_step_out_of_turn_is_refused(): void
    {
        ['01111111111' => $a] = $this->imported(['01111111111' => 'أحمد محمد علي']);
        $session = $this->examSession('2026-11-02T08:00:00Z');

        // Not started yet: no exam booking, no decisions, no contract; started once only.
        $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-sessions/{$session}/bookings", ['application_ids' => [$a->id]])->assertStatus(422);
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$a->id}/exam-exception", ['reason' => 'قرار اللجنة'])->assertStatus(422);
        $this->writeContract($a, 'ع/1')->assertStatus(422);
        $this->asStaff($this->recruitment)->postJson("/api/v1/staff/applications/{$a->id}/start")->assertOk()->assertJsonPath('data.status', 'awaiting_exam');
        $this->asStaff($this->recruitment)->postJson("/api/v1/staff/applications/{$a->id}/start")->assertStatus(422);

        // Waiting for the exam: no excuse (not absent), no exception (not failed), no interview, no contract.
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$a->id}/exam-excuse", ['reason' => 'تقرير طبي'])->assertStatus(422);
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$a->id}/exam-exception", ['reason' => 'قرار اللجنة'])->assertStatus(422);
        $this->travelTo('2026-11-01 09:00:00');
        $sitting = $this->sitting('2026-11-08T08:00:00Z', '2026-11-08T14:00:00Z');
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$a->id}/interviews", ['sitting_id' => $sitting])->assertStatus(422);
        $this->writeContract($a, 'ع/1')->assertStatus(422);

        // A second live application of the same person is refused, whatever the job position.
        $this->asStaff($this->recruitment)->postJson('/api/v1/staff/applications', $this->applicationPayload(['candidate' => ['national_number' => '01111111111', 'full_name' => 'أحمد محمد علي']]))
            ->assertStatus(422);

        // Passed: the interview is booked; a recorded interview is not recorded twice nor excused (not absent).
        $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-sessions/{$session}/bookings", ['application_ids' => [$a->id]])->assertCreated();
        $this->travelTo('2026-11-02 08:01:00');
        $this->sitExam('01111111111', 5);
        // Taken, not approved yet (D-104): no interview, no exception.
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$a->id}/interviews", ['sitting_id' => $sitting])->assertStatus(422);
        $this->asStaff($this->exams)->postJson("/api/v1/staff/applications/{$a->id}/exam-exception", ['reason' => 'قرار اللجنة'])->assertStatus(422);
        $this->travelTo('2026-11-02 10:00:00');
        $this->approveResults($session)->assertOk();
        $this->approveResults($session)->assertStatus(422)->assertJsonValidationErrors('session');                       // once
        $this->travelTo('2026-11-06 09:00:00');
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$a->id}/interviews", ['sitting_id' => $sitting])->assertCreated();
        $interview = $this->scheduledInterview($a);
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/interviews/{$interview}/result", ['result' => 'passed'] + self::GRADES)->assertStatus(422);   // the sitting has not started
        $this->travelTo('2026-11-08 09:00:00');
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/interviews/{$interview}/result", ['result' => 'passed'] + self::GRADES)->assertOk();
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/interviews/{$interview}/result", ['result' => 'failed'] + self::GRADES)->assertStatus(422);
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/applications/{$a->id}/interview-excuse", ['reason' => 'ظرف طارئ'])->assertStatus(422);

        // A correction moves it back: passed → failed takes it out of awaiting_contract; the history says so.
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/interviews/{$interview}/correct", ['result' => 'failed', 'reason' => 'خطأ في إدخال النتيجة'])->assertOk();
        $this->assertSame('interview_failed', $this->statusOf($a));
        $this->assertSame('awaiting_contract → interview_failed (correction)', last($this->history($a)));
        $this->writeContract($a, 'ع/1')->assertStatus(422);

        // A contract ends only once, and an ended or renewed contract does not renew.
        $this->asStaff($this->interviews)->postJson("/api/v1/staff/interviews/{$interview}/correct", ['result' => 'passed', 'reason' => 'تصحيح التصحيح'])->assertOk();
        $this->travelTo('2026-11-10 09:00:00');
        $contract = $this->writeContract($a, 'ع/1')->assertCreated()->json('data.id');
        $renewal = $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$contract}/renew", ['contract_type_id' => $this->contractType(), 'reference_number' => 'ت/1', 'ends_on' => '2028-11-14'])->assertCreated()->json('data.id');
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$contract}/renew", ['contract_type_id' => $this->contractType(), 'reference_number' => 'ت/2', 'ends_on' => '2029-11-14'])->assertStatus(422);
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$contract}/end", ['ended_on' => '2026-11-10', 'reason' => 'إنهاء'])->assertStatus(422);   // renewed: the renewal is the latest
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$renewal}/end", ['ended_on' => '2026-11-10', 'reason' => 'استقالة'])->assertOk();
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$renewal}/end", ['ended_on' => '2026-11-10', 'reason' => 'استقالة'])->assertStatus(422);
        $this->asStaff($this->contracts)->postJson("/api/v1/staff/contracts/{$renewal}/renew", ['contract_type_id' => $this->contractType(), 'reference_number' => 'ت/3', 'ends_on' => '2029-11-14'])->assertStatus(422);
        $this->assertSame('contract_ended', $this->statusOf($a));
    }
}
