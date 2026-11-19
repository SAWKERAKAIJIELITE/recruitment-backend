<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
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
 * Recruitment tells the candidates of their appointments (D-105): every exam booked and every interview scheduled is
 * someone to call. A call is recorded against the time and place as they stand; when either changes the candidate is to
 * be told again. For information only: nothing moves and nothing is blocked. Only Recruitment (and the general
 * manager, reading) sees it.
 */
class AppointmentCallsTest extends TestCase
{
    use ExamTestHelpers, RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    private StaffUser $recruitment;

    private StaffUser $exams;

    private ExamPlanVersion $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-11-10 07:00:00');
        $this->recruitment = $this->hiringStaff();
        $this->exams = $this->staffOf('exams');
        $this->plan = $this->publishedPlan(JobPosition::query()->findOrFail($this->referenceIds()['job_position_id']), $this->planContent([$this->subject()]), $this->examManager());
    }

    private function candidate(string $number): Application
    {
        return $this->openApplication($this->recruitment, ['candidate' => ['national_number' => $number]]);
    }

    private function examSession(string $startsAt = '2026-11-12T08:00:00Z', Application ...$booked): string
    {
        $session = $this->asStaff($this->exams)->postJson('/api/v1/staff/exam-sessions', ['plan_version_id' => $this->plan->id, 'starts_at' => $startsAt, 'location' => 'قاعة الحاسوب', 'password' => 'qalam-2026'])
            ->assertCreated()->json('data.id');
        $this->asStaff($this->exams)->postJson("/api/v1/staff/exam-sessions/{$session}/bookings", ['application_ids' => array_map(fn(Application $a) => $a->id, $booked)])->assertCreated();

        return $session;
    }

    private function recordCall(array $applications, bool $reached, ?string $note = null, ?StaffUser $as = null): TestResponse
    {
        return $this->asStaff($as ?? $this->recruitment)->postJson('/api/v1/staff/applications/calls', ['application_ids' => array_map(fn(Application $a) => $a->id, $applications), 'reached' => $reached] + ($note === null ? [] : ['note' => $note]));
    }

    private function appointment(Application $application): ?array
    {
        return $this->asStaff($this->recruitment)->getJson("/api/v1/staff/applications/{$application->id}")->assertOk()->json('data.appointment');
    }

    /** @return list<string> the ids the list keeps with that call state */
    private function listed(string $state): array
    {
        return array_column($this->asStaff($this->recruitment)->getJson("/api/v1/staff/applications?call_state={$state}")->assertOk()->json('data'), 'id');
    }

    private function badge(): array
    {
        return $this->asStaff($this->recruitment)->getJson('/api/v1/staff/badges')->assertOk()->json('data.calls');
    }

    public function test_a_booked_exam_is_someone_to_call_and_each_call_is_kept(): void
    {
        [$a, $b] = [$this->candidate('01111111111'), $this->candidate('02222222222')];
        $waiting = $this->candidate('03333333333');   // awaiting the exam, booked nowhere
        $session = $this->examSession('2026-11-12T08:00:00Z', $a, $b);

        // Booked: to inform, on the list's row and in the sidebar.
        $row = collect($this->asStaff($this->recruitment)->getJson('/api/v1/staff/applications?call_state=any')->assertOk()->json('data'))->firstWhere('id', $a->id);
        $this->assertSame(['exam', 'اختبار', $session, '2026-11-12T08:00:00+00:00', 'قاعة الحاسوب', 'to_inform', 'بانتظار الإعلام', null], [
            $row['appointment']['kind'], $row['appointment']['kind_label'], $row['appointment']['session_id'], $row['appointment']['at'],
            $row['appointment']['location'], $row['appointment']['call_state'], $row['appointment']['call_state_label'], $row['appointment']['last_call'],
        ]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->listed('to_inform'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->listed('any'));
        $this->assertNull(collect($this->asStaff($this->recruitment)->getJson('/api/v1/staff/applications')->json('data'))->firstWhere('id', $waiting->id)['appointment']);
        $this->assertSame(['to_inform' => 2, 'not_reached' => 0], $this->badge());

        // A first call not answered, then a second that reached them; B is told at once.
        $this->travelTo('2026-11-10 09:00:00');
        $this->recordCall([$a], false, 'الهاتف مغلق')->assertCreated()->assertJsonPath('data.0.appointment.call_state', 'not_reached');
        $this->assertSame(['to_inform' => 1, 'not_reached' => 1], $this->badge());
        $this->assertSame([$a->id], $this->listed('not_reached'));
        $this->travelTo('2026-11-10 11:00:00');
        $this->recordCall([$a, $b], true)->assertCreated()->assertJsonPath('data.1.application_id', $b->id)->assertJsonPath('data.1.appointment.call_state', 'informed');
        $this->assertSame(['to_inform' => 0, 'not_reached' => 0], $this->badge());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->listed('informed'));

        $appointment = $this->appointment($a);
        $this->assertSame(['informed', 'أُعلم بالموعد'], [$appointment['call_state'], $appointment['call_state_label']]);
        $this->assertSame(['2026-11-10T11:00:00+00:00', $this->recruitment->id, true, null], [$appointment['last_call']['at'], $appointment['last_call']['by']['id'], $appointment['last_call']['reached'], $appointment['last_call']['note']]);
        $this->assertSame([false, true], array_column($appointment['calls'], 'reached'));
        $this->assertSame('الهاتف مغلق', $appointment['calls'][0]['note']);

        // The session moved: the candidates were told of another time, so they are to inform again.
        $this->asStaff($this->exams)->patchJson("/api/v1/staff/exam-sessions/{$session}", ['starts_at' => '2026-11-13T08:00:00Z'])->assertOk();
        $appointment = $this->appointment($a);
        $this->assertSame(['to_inform', '2026-11-13T08:00:00+00:00', '2026-11-12T08:00:00+00:00'], [$appointment['call_state'], $appointment['at'], $appointment['last_call']['about']['at']]);
        $this->assertSame(['to_inform' => 2, 'not_reached' => 0], $this->badge());

        // All or nothing: one application without an appointment ahead refuses the request.
        $this->recordCall([$a, $waiting], true)->assertStatus(422)->assertJsonPath('errors', ['application_ids.1' => [__('recruitment.calls.no_appointment')]]);
        $this->assertSame(3, DB::table('appointment_calls')->count());
        $this->recordCall([$a, $a], true)->assertStatus(422)->assertJsonValidationErrors('application_ids.0');
        $this->recordCall([], true)->assertStatus(422)->assertJsonValidationErrors('application_ids');
        $this->assertTrue(DB::table('audit_events')->where('event', 'application.calls_recorded')->where('actor_id', $this->recruitment->id)->exists());

        // Information only: nothing moved, and the clock still decides an absence.
        $this->assertSame([S::AwaitingExam, S::AwaitingExam], [$a->fresh()->current_status, $b->fresh()->current_status]);
        $this->travelTo('2026-11-13 08:20:00');
        $this->artisan('exams:sweep')->assertSuccessful();
        $this->assertSame([S::ExamAbsent, S::ExamAbsent], [$a->fresh()->current_status, $b->fresh()->current_status]);
        $this->assertNull($this->appointment($a));   // nothing ahead any more
        $this->recordCall([$a], true)->assertStatus(422);
    }

    public function test_a_scheduled_interview_is_someone_to_call_and_a_new_time_needs_a_new_call(): void
    {
        $application = $this->candidate('01111111111');
        $lifecycle = app(ApplicationLifecycle::class);
        $lifecycle->move($application, S::ExamTaken, D::Outcome);
        $lifecycle->move($application, S::AwaitingInterview, D::Outcome);
        $interviews = $this->staffOf('interviews');
        $sitting = $this->asStaff($interviews)->postJson('/api/v1/staff/interview-sittings', [
            'starts_at' => '2026-11-12T08:00:00Z', 'ends_at' => '2026-11-12T14:00:00Z', 'location' => 'قاعة المقابلات', 'committee_name' => 'لجنة',
            'members' => [['name' => 'محمد السالم', 'capacity' => 'chair']],
        ])->assertCreated()->json('data.id');
        $interview = $this->asStaff($interviews)->postJson("/api/v1/staff/applications/{$application->id}/interviews", ['sitting_id' => $sitting, 'scheduled_at' => '2026-11-12T09:00:00Z'])->assertCreated()->json('data.id');

        $appointment = $this->appointment($application);
        $this->assertSame(['interview', $interview, $sitting, '2026-11-12T09:00:00+00:00', 'قاعة المقابلات', 'to_inform'], [$appointment['kind'], $appointment['interview_id'], $appointment['sitting_id'], $appointment['at'], $appointment['location'], $appointment['call_state']]);
        $this->recordCall([$application], true, 'أكّد الحضور')->assertCreated()->assertJsonPath('data.0.appointment.call_state', 'informed');

        $this->asStaff($interviews)->postJson("/api/v1/staff/interviews/{$interview}/reschedule", ['scheduled_at' => '2026-11-12T10:00:00Z', 'reason' => 'طلب اللجنة'])->assertOk();
        $this->assertSame(['to_inform', '2026-11-12T10:00:00+00:00'], [$this->appointment($application)['call_state'], $this->appointment($application)['at']]);
        $this->assertSame([$application->id], $this->listed('to_inform'));
    }

    public function test_only_recruitment_records_and_reads_the_calls(): void
    {
        $a = $this->candidate('01111111111');
        $this->examSession('2026-11-12T08:00:00Z', $a);
        $this->recordCall([$a], true)->assertCreated();
        $manager = $this->staffOf('general_manager');

        // The general manager reads the full file, and with it the calls; records none.
        $this->assertSame('informed', $this->asStaff($manager)->getJson("/api/v1/staff/applications/{$a->id}")->assertOk()->json('data.appointment.call_state'));
        $this->recordCall([$a], true, null, $manager)->assertForbidden();
        $this->assertNull($this->asStaff($manager)->getJson('/api/v1/staff/badges')->json('data.calls'));

        // The other offices see who the candidate is only: no appointment, no call filter, no recording.
        foreach (['exams', 'interviews', 'contracts', 'documenting'] as $office) {
            $staff = $this->staffOf($office);
            $this->recordCall([$a], true, null, $staff)->assertForbidden();
            $this->asStaff($staff)->getJson('/api/v1/staff/applications?call_state=informed')->assertStatus(422)->assertJsonValidationErrors('call_state');
            $this->assertNull($this->asStaff($staff)->getJson('/api/v1/staff/badges')->json('data.calls'));
        }

        $identity = $this->asStaff($this->exams)->getJson("/api/v1/staff/applications/{$a->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('appointment', $identity);
    }

    public function test_the_database_keeps_the_calls(): void
    {
        [$a, $b] = [$this->candidate('01111111111'), $this->candidate('02222222222')];
        $this->examSession('2026-11-12T08:00:00Z', $a, $b);
        $this->recordCall([$a], true)->assertCreated();
        $call = DB::table('appointment_calls')->first();
        $refused = function (\Closure $statement) {
            try {
                DB::transaction($statement);
                $this->fail('The database accepted it.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        // A copy of the call, without its id.
        $row = fn(array $changes) => array_replace(array_diff_key((array) $call, ['id' => true]), $changes);

        $refused(fn() => DB::table('appointment_calls')->where('id', $call->id)->update(['reached' => false]));   // a call is a fact
        $refused(fn() => DB::table('appointment_calls')->where('id', $call->id)->delete());
        $refused(fn() => DB::table('appointment_calls')->insert($row(['exam_booking_id' => null])));                 // about no appointment
        $refused(fn() => DB::table('appointment_calls')->insert($row(['application_id' => $b->id])));                // about another's booking
        DB::table('appointment_calls')->insert($row(['reached' => false]));                                         // a new call is fine
        $this->assertSame(2, DB::table('appointment_calls')->count());
    }
}
