<?php

namespace Tests\Feature\Interviews;

use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Specialization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** Interviews: sittings, appointments, results, corrections, excuses (D-059, D-061, D-067). */
class InterviewApiTest extends TestCase
{
    use RefreshDatabase, StaffTestHelpers, RecruitmentTestHelpers;

    private const GRADES = ['scientific_grade' => 'very_good', 'general_grade' => 'good', 'overall_grade' => 'very_good'];

    private StaffUser $staff;

    /** Sittings made by sitting(), so each gets its own room unless one is given (the same one twice is a duplicate). */
    private int $sittings = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo('2026-10-30 09:00:00');
        $this->staff = $this->hiringStaff();   // recruitment_staff holds interviews.manage
    }

    /** An application that passed its exams (opened yesterday). */
    private function awaitingInterview(string $number = '01234567890'): Application
    {
        $this->travel(-1)->days();
        $application = $this->openApplication(null, ['candidate' => ['national_number' => $number]]);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        $this->travelBack();
        $this->travelTo('2026-10-30 09:00:00');

        return $application;
    }

    private function members(): array
    {
        return [['name' => 'محمد السالم', 'capacity' => 'chair'], ['name' => 'خالد العمر', 'capacity' => 'member']];
    }

    private function sitting(array $overrides = []): string
    {
        return $this->asStaff($this->staff)->postJson('/api/v1/staff/interview-sittings', $overrides + [
            'starts_at' => '2026-10-30T08:00:00Z', 'location' => 'مبنى الإدارة - القاعة ' . (2 + $this->sittings++), 'committee_name' => 'لجنة دمشق الأولى', 'members' => $this->members(),
        ])->assertCreated()->json('data.id');
    }

    /** A one-off sitting: one interviewer. */
    private function oneOffSitting(array $overrides = []): string
    {
        return $this->asStaff($this->staff)->postJson('/api/v1/staff/interview-sittings', $overrides + [
            'kind' => 'one_off', 'starts_at' => '2026-11-02T10:00:00Z', 'location' => 'اتصال مرئي', 'members' => [['capacity' => 'chair', 'staff_user_id' => $this->staff->id]],
        ])->assertCreated()->json('data.id');
    }

    private function schedule(Application $application, array $appointment): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/staff/applications/{$application->id}/interviews", $appointment);
    }

    private function scheduled(Application $application, ?string $sitting = null): string
    {
        return $this->schedule($application, ['sitting_id' => $sitting ?? $this->sitting()])->assertCreated()->json('data.id');
    }

    private function record(string $interview, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/staff/interviews/{$interview}/result", $body);
    }

    private function reschedule(string $interview, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($this->staff)->postJson("/api/v1/staff/interviews/{$interview}/reschedule", $body + ['reason' => 'طلب المرشح']);
    }

    // ---- sittings and appointments ----

    public function test_an_interview_booked_into_a_sitting_takes_its_place_and_committee(): void
    {
        $sitting = $this->sitting();

        $this->schedule($this->awaitingInterview(), ['sitting_id' => $sitting, 'scheduled_at' => '2026-10-30T08:30:00Z'])
            ->assertCreated()
            ->assertJsonPath('data.round', 1)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.sitting.id', $sitting)
            ->assertJsonPath('data.sitting.kind', 'committee')
            ->assertJsonPath('data.scheduled_at', '2026-10-30T08:30:00+00:00')
            ->assertJsonPath('data.location', 'مبنى الإدارة - القاعة 2')
            ->assertJsonPath('data.committee_name', 'لجنة دمشق الأولى')
            ->assertJsonPath('data.members.0', ['name' => 'محمد السالم', 'capacity' => ['value' => 'chair', 'label' => 'رئيس اللجنة'], 'staff_user_id' => null]);

        $this->asStaff($this->staff)->getJson("/api/v1/staff/interview-sittings/{$sitting}")
            ->assertOk()->assertJsonCount(1, 'data.interviews')->assertJsonCount(2, 'data.members');
    }

    public function test_the_candidates_time_defaults_to_the_sitting_start_and_cannot_be_before_it(): void
    {
        $sitting = $this->sitting();

        $this->schedule($this->awaitingInterview('11111111111'), ['sitting_id' => $sitting])->assertCreated()->assertJsonPath('data.scheduled_at', '2026-10-30T08:00:00+00:00');
        $this->schedule($this->awaitingInterview('22222222222'), ['sitting_id' => $sitting, 'scheduled_at' => '2026-10-30T07:59:00Z'])
            ->assertStatus(422)->assertJsonPath('errors.scheduled_at.0', __('interviews.before_sitting_start', ['start' => '2026-10-30T08:00:00+00:00']));
    }

    public function test_a_one_off_sitting_is_one_interviewer_who_sees_many_candidates(): void
    {
        $url = '/api/v1/staff/interview-sittings';
        $members = [['name' => 'سامر', 'capacity' => 'chair'], ['name' => 'خالد', 'capacity' => 'member']];

        $this->asStaff($this->staff)->postJson($url, ['kind' => 'one_off', 'starts_at' => '2026-11-02T10:00:00Z', 'location' => 'اتصال مرئي', 'members' => $members])
            ->assertStatus(422)->assertJsonValidationErrors('members');
        $sitting = $this->oneOffSitting();

        $this->schedule($this->awaitingInterview('11111111111'), ['sitting_id' => $sitting])
            ->assertCreated()
            ->assertJsonPath('data.sitting.kind', 'one_off')
            ->assertJsonPath('data.location', 'اتصال مرئي')
            ->assertJsonPath('data.committee_name', null)
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.staff_user_id', $this->staff->id)
            ->assertJsonPath('data.members.0.name', $this->staff->full_name);
        $this->schedule($this->awaitingInterview('22222222222'), ['sitting_id' => $sitting, 'scheduled_at' => '2026-11-02T10:40:00Z'])->assertCreated();

        $this->asStaff($this->staff)->getJson("{$url}/{$sitting}")->assertOk()->assertJsonCount(2, 'data.interviews');
        $this->sitting();
        $this->asStaff($this->staff)->getJson($url)->assertJsonCount(2, 'data');
        $this->asStaff($this->staff)->getJson("{$url}?kind=one_off")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sitting);
        $this->asStaff($this->staff)->getJson("{$url}?kind=committee")->assertJsonCount(1, 'data');

        // Edited like any sitting, but it keeps one member and its kind.
        $this->asStaff($this->staff)->patchJson("{$url}/{$sitting}", ['location' => 'مكتب المدير'])->assertOk()->assertJsonPath('data.location', 'مكتب المدير');
        $this->asStaff($this->staff)->patchJson("{$url}/{$sitting}", ['members' => $members])
            ->assertStatus(422)->assertJsonPath('errors.members.0', __('interviews.one_off_one_member'));
        $this->asStaff($this->staff)->patchJson("{$url}/{$sitting}", ['members' => [['name' => 'محمد', 'capacity' => 'member']]])->assertOk()->assertJsonPath('data.members.0.name', 'محمد');
        $this->asStaff($this->staff)->patchJson("{$url}/{$sitting}", ['kind' => 'committee'])->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_the_same_sitting_cannot_be_entered_twice(): void
    {
        $url = '/api/v1/staff/interview-sittings';
        $first = $this->sitting(['location' => 'مبنى الإدارة - القاعة 2']);
        $same = [
            'starts_at' => '2026-10-30T08:00:00Z',
            'location' => '  مبنى  الادارة - القاعه 2 ',                                                            // spaces, ا for إ, ه for ة
            'committee_name' => 'اسم آخر',                                                                            // the name does not make it another sitting
            'members' => [['name' => 'خالد العمر', 'capacity' => 'chair'], ['name' => 'محمد  السالم', 'capacity' => 'secretary']],   // order and capacity neither
        ];

        $this->asStaff($this->staff)->postJson($url, $same)
            ->assertStatus(422)->assertJsonPath('errors.starts_at.0', __('interviews.duplicate_sitting', ['id' => $first]));
        $this->asStaff($this->staff)->postJson($url, ['members' => [['name' => 'خالد العمر', 'capacity' => 'chair']]] + $same)->assertCreated();   // other members
        $this->asStaff($this->staff)->postJson($url, ['location' => 'القاعة 5'] + $same)->assertCreated();                                     // another place
        $later = $this->asStaff($this->staff)->postJson($url, ['starts_at' => '2026-10-30T10:00:00Z'] + $same)->assertCreated()->json('data.id');   // another time

        // Nor made the same afterwards.
        $this->asStaff($this->staff)->patchJson("{$url}/{$later}", ['starts_at' => '2026-10-30T08:00:00Z'])
            ->assertStatus(422)->assertJsonPath('errors.starts_at.0', __('interviews.duplicate_sitting', ['id' => $first]));
        $this->assertSame('2026-10-30 10:00:00+00', DB::table('interview_sittings')->where('id', $later)->value('starts_at'));
    }

    public function test_a_member_with_an_account_carries_the_accounts_name(): void
    {
        $url = '/api/v1/staff/interview-sittings';
        $base = ['starts_at' => '2026-11-01T08:00:00Z', 'location' => 'القاعة 7', 'committee_name' => 'لجنة'];
        $colleague = StaffUserFactory::new()->create(['first_name' => 'rami', 'last_name' => 'haddad']);

        $errors = $this->asStaff($this->staff)->postJson($url, $base + ['members' => [['name' => 'اسم آخر', 'capacity' => 'chair', 'staff_user_id' => $colleague->id]]])->assertStatus(422)->json('errors');
        $this->assertSame(['عضو له حساب اسمه اسم حسابه: لا يُرسل معه اسم.'], $errors['members.0.staff_user_id']);
        $errors = $this->asStaff($this->staff)->postJson($url, $base + ['members' => [['capacity' => 'chair']]])->assertStatus(422)->json('errors');
        $this->assertSame(['اكتب اسم العضو، أو اختر حسابه.'], $errors['members.0.name']);

        $sitting = $this->asStaff($this->staff)->postJson($url, $base + ['members' => [['capacity' => 'chair', 'staff_user_id' => $colleague->id], ['name' => 'خالد العمر', 'capacity' => 'member']]])
            ->assertCreated()
            ->assertJsonPath('data.members.0', ['name' => 'Rami Haddad', 'capacity' => ['value' => 'chair', 'label' => 'رئيس اللجنة'], 'staff_user_id' => $colleague->id])
            ->assertJsonPath('data.members.1.name', 'خالد العمر')
            ->json('data.id');

        // The database sets it on every write, whatever is sent.
        DB::table('interview_members')->where('sitting_id', $sitting)->where('position', 1)->update(['name' => 'منتحل']);
        $this->assertSame('Rami Haddad', DB::table('interview_members')->where('sitting_id', $sitting)->where('position', 1)->value('name'));

        // A renamed account renames its seats on sittings without a result; a sitting with a result keeps its names.
        $held = $this->asStaff($this->staff)->postJson($url, ['location' => 'القاعة 8', 'starts_at' => '2026-10-30T08:00:00Z'] + $base + ['members' => [['capacity' => 'chair', 'staff_user_id' => $colleague->id]]])->assertCreated()->json('data.id');
        $interview = $this->scheduled($this->awaitingInterview(), $held);
        $this->record($interview, ['result' => 'passed', 'held_at' => '2026-10-30T08:30:00Z'] + self::GRADES)->assertOk();
        $colleague->update(['last_name' => 'haddadin']);

        $this->assertSame('Rami Haddadin', DB::table('interview_members')->where('sitting_id', $sitting)->where('position', 1)->value('name'));
        $this->assertSame('Rami Haddad', DB::table('interview_members')->where('sitting_id', $held)->value('name'));

        // An inactive account does not sit on a new committee.
        $colleague->update(['is_active' => false]);
        $this->asStaff($this->staff)->postJson($url, ['location' => 'القاعة 9'] + $base + ['members' => [['capacity' => 'chair', 'staff_user_id' => $colleague->id]]])
            ->assertStatus(422)->assertJsonValidationErrors('members.0.staff_user_id');
    }

    public function test_a_staff_user_and_the_sittings_they_sit_on(): void
    {
        $sitting = $this->oneOffSitting();
        $this->sitting();

        $this->asStaff($this->staff)->getJson("/api/v1/staff/interview-sittings?member={$this->staff->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sitting);
        $this->assertSame([$sitting], $this->staff->interviewSittings()->pluck('interview_sittings.id')->all());
        $this->assertSame('chair', $this->staff->interviewSittings()->first()->pivot->capacity);
        $this->assertCount(1, $this->staff->interviewMemberships);
        $this->assertCount(2, $this->staff->createdInterviewSittings);

        $interview = $this->scheduled($this->awaitingInterview(), $sitting);
        $this->assertSame([$interview], $this->staff->scheduledInterviews()->pluck('id')->all());
    }

    public function test_one_sitting_lists_its_interviews_without_repeating_itself(): void
    {
        $sitting = $this->sitting();
        $this->scheduled($this->awaitingInterview(), $sitting);

        $interview = $this->asStaff($this->staff)->getJson("/api/v1/staff/interview-sittings/{$sitting}")
            ->assertOk()
            ->assertJsonPath('data.location', 'مبنى الإدارة - القاعة 2')
            ->assertJsonCount(2, 'data.members')
            ->json('data.interviews.0');

        $this->assertSame($sitting, $interview['sitting_id']);
        foreach (['sitting', 'location', 'committee_name', 'members'] as $field) {
            $this->assertArrayNotHasKey($field, $interview);
        }
        $this->assertSame('01234567890', $interview['application']['candidate']['national_number']);
    }

    public function test_a_sitting_without_bookings_can_be_deleted(): void
    {
        $url = '/api/v1/staff/interview-sittings';
        $empty = $this->sitting();
        $this->asStaff($this->staff)->deleteJson("{$url}/{$empty}")->assertNoContent();
        $this->assertFalse(DB::table('interview_sittings')->where('id', $empty)->exists());
        $this->assertFalse(DB::table('interview_members')->where('sitting_id', $empty)->exists());

        // Not with an interview in it, even cancelled, nor once an interview was moved out of it.
        $booked = $this->sitting();
        $interview = $this->scheduled($this->awaitingInterview(), $booked);
        $this->asStaff($this->staff)->postJson("/api/v1/staff/interviews/{$interview}/cancel", ['reason' => 'خطأ'])->assertOk();
        $this->asStaff($this->staff)->deleteJson("{$url}/{$booked}")->assertStatus(422)->assertJsonPath('errors.sitting.0', __('interviews.sitting_in_use'));

        $left = $this->sitting();
        $moved = $this->scheduled($this->awaitingInterview('22222222222'), $left);
        $this->reschedule($moved, ['sitting_id' => $this->sitting()])->assertOk();
        $this->asStaff($this->staff)->deleteJson("{$url}/{$left}")->assertStatus(422);

        $this->asStaff($this->allow(StaffUserFactory::new()->create(), 'reports.view'))->deleteJson("{$url}/{$left}")->assertForbidden();
    }

    public function test_a_committee_sitting_keeps_its_name(): void
    {
        $url = '/api/v1/staff/interview-sittings';
        $this->asStaff($this->staff)->postJson($url, ['starts_at' => '2026-11-01T08:00:00Z', 'location' => 'القاعة 7', 'members' => $this->members()])
            ->assertStatus(422)->assertJsonValidationErrors('committee_name');
        $this->asStaff($this->staff)->patchJson("{$url}/" . $this->sitting(), ['committee_name' => null])
            ->assertStatus(422)->assertJsonPath('errors.committee_name.0', __('interviews.committee_name_required'));
        $this->asStaff($this->staff)->patchJson("{$url}/" . $this->oneOffSitting(), ['committee_name' => null])->assertOk();
    }

    public function test_a_committee_has_at_most_one_chair_and_known_capacities(): void
    {
        $two = [['name' => 'أ ب', 'capacity' => 'chair'], ['name' => 'ج د', 'capacity' => 'chair']];

        $this->asStaff($this->staff)->postJson('/api/v1/staff/interview-sittings', ['starts_at' => '2026-11-01T08:00:00Z', 'location' => 'x x', 'committee_name' => 'لجنة', 'members' => $two])
            ->assertStatus(422)->assertJsonPath('errors.members.0', __('interviews.one_chair'));
        $this->asStaff($this->staff)->postJson('/api/v1/staff/interview-sittings', ['starts_at' => '2026-11-01T08:00:00Z', 'location' => 'x x', 'committee_name' => 'لجنة', 'members' => [['name' => 'أ ب', 'capacity' => 'رئيس اللجنة']]])
            ->assertStatus(422)->assertJsonValidationErrors('members.0.capacity');
        $this->asStaff($this->staff)->patchJson('/api/v1/staff/interview-sittings/' . $this->sitting(), ['members' => $two])->assertStatus(422)->assertJsonPath('errors.members.0', __('interviews.one_chair'));
    }

    public function test_only_an_application_awaiting_its_interview_and_one_at_a_time(): void
    {
        $application = $this->awaitingInterview();
        $this->scheduled($application);

        $this->schedule($application, ['sitting_id' => $this->sitting()])->assertStatus(422)->assertJsonPath('errors.application.0', __('interviews.already_scheduled'));
        $this->schedule($this->openApplication(null, ['candidate' => ['national_number' => '22222222222']]), ['sitting_id' => $this->sitting()])
            ->assertStatus(422)->assertJsonPath('errors.application.0', __('interviews.wrong_application_status', ['status' => S::AwaitingExam->labelAr()]));
    }

    public function test_rescheduling_to_another_sitting_or_time_and_cancelling_keep_their_reason(): void
    {
        $application = $this->awaitingInterview();
        $first = $this->sitting();
        $interview = $this->scheduled($application, $first);
        $other = $this->sitting(['committee_name' => 'لجنة حلب', 'location' => 'حلب', 'starts_at' => '2026-11-05T08:00:00Z']);
        $url = "/api/v1/staff/interviews/{$interview}/reschedule";

        $this->asStaff($this->staff)->postJson($url, ['sitting_id' => $other])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->reschedule($interview, ['sitting_id' => $first])->assertStatus(422)->assertJsonPath('errors.scheduled_at.0', __('interviews.same_appointment'));
        $this->reschedule($interview, ['sitting_id' => $other])
            ->assertOk()
            ->assertJsonPath('data.committee_name', 'لجنة حلب')
            ->assertJsonPath('data.scheduled_at', '2026-11-05T08:00:00+00:00')
            ->assertJsonPath('data.reschedules.0.reason', 'طلب المرشح')
            ->assertJsonPath('data.reschedules.0.from.sitting.id', $first)
            ->assertJsonPath('data.reschedules.0.to.sitting.location', 'حلب');

        // A new time alone keeps it in its sitting.
        $this->reschedule($interview, ['scheduled_at' => '2026-11-05T09:15:00Z'])
            ->assertOk()->assertJsonPath('data.sitting.id', $other)->assertJsonPath('data.reschedules.1.to.scheduled_at', '2026-11-05T09:15:00+00:00');
        $this->reschedule($interview, ['scheduled_at' => '2026-11-05T07:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');

        $this->asStaff($this->staff)->postJson("/api/v1/staff/interviews/{$interview}/cancel", ['reason' => 'خطأ في الحجز'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancelled.reason', 'خطأ في الحجز');
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertStatus(422)->assertJsonValidationErrors('interview');

        $this->schedule($application, ['sitting_id' => $other])->assertCreated()->assertJsonPath('data.round', 2);   // a new booking after a cancellation
    }

    public function test_rescheduling_between_a_committee_and_a_one_off_sitting(): void
    {
        $committee = $this->sitting();
        $interview = $this->scheduled($this->awaitingInterview(), $committee);
        $single = $this->oneOffSitting();

        $this->reschedule($interview, [])->assertStatus(422)->assertJsonValidationErrors('sitting_id');
        $this->reschedule($interview, ['sitting_id' => $single])
            ->assertOk()
            ->assertJsonPath('data.sitting.kind', 'one_off')
            ->assertJsonPath('data.scheduled_at', '2026-11-02T10:00:00+00:00')
            ->assertJsonPath('data.reschedules.0.from.sitting.id', $committee)
            ->assertJsonPath('data.reschedules.0.to.sitting.kind', 'one_off');
        $this->reschedule($interview, ['sitting_id' => $committee, 'scheduled_at' => '2026-10-30T11:00:00Z'])
            ->assertOk()->assertJsonPath('data.sitting.kind', 'committee')->assertJsonPath('data.scheduled_at', '2026-10-30T11:00:00+00:00');
    }

    public function test_a_sittings_committee_is_frozen_once_a_result_is_recorded(): void
    {
        $sitting = $this->sitting();
        $interview = $this->scheduled($this->awaitingInterview(), $sitting);
        $url = "/api/v1/staff/interview-sittings/{$sitting}";

        $this->asStaff($this->staff)->patchJson($url, ['members' => [['name' => 'عضو جديد', 'capacity' => 'member']]])->assertOk()->assertJsonCount(1, 'data.members');
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();
        $this->asStaff($this->staff)->patchJson($url, ['members' => $this->members()])->assertStatus(422)->assertJsonPath('errors.members.0', __('interviews.committee_frozen'));
        $this->asStaff($this->staff)->patchJson($url, ['notes' => 'ملاحظة'])->assertOk();
    }

    public function test_a_sitting_cannot_start_after_its_bookings(): void
    {
        $sitting = $this->sitting();
        $this->schedule($this->awaitingInterview(), ['sitting_id' => $sitting, 'scheduled_at' => '2026-10-30T08:30:00Z'])->assertCreated();
        $url = "/api/v1/staff/interview-sittings/{$sitting}";

        $this->asStaff($this->staff)->patchJson($url, ['starts_at' => '2026-10-30T09:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
        $this->asStaff($this->staff)->patchJson($url, ['starts_at' => '2026-10-30T08:30:00Z'])->assertOk();
        $this->asStaff($this->staff)->patchJson($url, ['starts_at' => '2026-10-30T07:30:00Z'])->assertOk();
    }

    // ---- booking many, by priority ----

    public function test_several_applications_are_booked_into_a_sitting_at_successive_times(): void
    {
        $applications = [$this->awaitingInterview('11111111111'), $this->awaitingInterview('22222222222'), $this->awaitingInterview('33333333333')];
        $sitting = $this->sitting(['starts_at' => '2026-11-01T08:00:00Z']);
        $url = "/api/v1/staff/interview-sittings/{$sitting}/bookings";
        $ids = array_map(fn(Application $application) => $application->id, $applications);

        $this->asStaff($this->staff)->postJson($url, ['application_ids' => [$ids[2], $ids[0]], 'first_at' => '2026-11-01T09:00:00Z', 'minutes_between' => 20])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.application.id', $ids[2])
            ->assertJsonPath('data.0.scheduled_at', '2026-11-01T09:00:00+00:00')
            ->assertJsonPath('data.1.scheduled_at', '2026-11-01T09:20:00+00:00');

        // All or nothing: the first one is booked already, so the second is not booked either.
        $this->asStaff($this->staff)->postJson($url, ['application_ids' => [$ids[1], $ids[0]]])
            ->assertStatus(422)->assertJsonPath('errors', ['application_ids.1' => [__('interviews.already_scheduled')]]);
        $this->assertSame(0, Interview::query()->where('application_id', $ids[1])->count());
        $this->asStaff($this->staff)->postJson($url, ['application_ids' => [$ids[1]]])->assertCreated()->assertJsonPath('data.0.scheduled_at', '2026-11-01T08:00:00+00:00');
    }

    public function test_the_queue_follows_the_priorities_chosen_by_staff(): void
    {
        $sharia = Specialization::create(['name_ar' => 'فقه']);
        $arabic = Specialization::create(['name_ar' => 'لغة عربية']);
        $old = $this->awaitingInterview('11111111111');                                                     // waiting longest
        $this->travelTo('2026-10-30 10:00:00');
        $young = $this->awaitingInterview('22222222222');
        DB::table('applications')->where('id', $young->id)->update(['specialization_id' => $arabic->id, 'quran_parts_memorized' => 30]);
        DB::table('candidates')->where('id', $young->candidate_id)->update(['birth_date' => '2004-01-01']);
        $this->travelTo('2026-10-30 11:00:00');
        $third = $this->awaitingInterview('33333333333');
        DB::table('applications')->where('id', $third->id)->update(['specialization_id' => $sharia->id, 'quran_parts_memorized' => 10]);
        $booked = $this->awaitingInterview('44444444444');
        $this->scheduled($booked);                                                                            // booked: not in the queue

        $queue = fn(string $query = '') => array_column($this->asStaff($this->staff)->getJson('/api/v1/staff/interviews/queue?' . $query)->assertOk()->json('data'), 'nomination_number');
        $numbers = fn(Application ...$applications) => array_map(fn(Application $application) => $application->nomination_number, $applications);

        $this->assertSame($numbers($old, $young, $third), $queue());                                                    // longest waiting first
        $this->assertSame($numbers($third, $young, $old), $queue("priority[]=specialization_id:{$sharia->id},{$arabic->id}"));
        $this->assertSame($numbers($young, $third, $old), $queue('priority[]=quran_parts'));                             // most parts first; none last
        $this->assertSame($numbers($young, $old, $third), $queue('priority[]=age'));                                     // youngest first
        $this->assertSame($numbers($third), $queue("specialization_id={$sharia->id}"));                                  // the list filters too

        $response = $this->asStaff($this->staff)->getJson('/api/v1/staff/interviews/queue?per_page=5&page=1')->assertOk();
        $this->assertSame(1, $response->json('data.0.position'));
        $this->assertNotNull($response->json('data.0.waiting_since'));
        $this->asStaff($this->staff)->getJson('/api/v1/staff/interviews/queue?priority[]=specialization_id')->assertStatus(422)->assertJsonValidationErrors('priority.0');
        $this->asStaff($this->staff)->getJson('/api/v1/staff/interviews/queue?priority[]=salary:desc')->assertStatus(422)->assertJsonValidationErrors('priority.0');
    }

    // ---- results ----

    public function test_a_passed_result_moves_the_application_with_the_interview_as_cause(): void
    {
        $application = $this->awaitingInterview();
        $interview = $this->scheduled($application);
        Log::spy();

        $this->record($interview, ['result' => 'passed', 'committee_summary' => 'إجابات متمكنة'] + self::GRADES)
            ->assertOk()
            ->assertJsonPath('data.status', 'recorded')
            ->assertJsonPath('data.result', ['value' => 'passed', 'label' => 'ناجح'])
            ->assertJsonPath('data.held_at', '2026-10-30T08:00:00+00:00')
            ->assertJsonPath('data.grades.overall', ['value' => 'very_good', 'label' => 'جيد جداً'])
            ->assertJsonPath('data.application.status', 'awaiting_contract')
            ->assertJsonPath('data.revisions.0.revision', 1)
            ->assertJsonPath('data.recorded_by.id', $this->staff->id);

        $event = DB::table('application_status_events')->where('application_id', $application->id)->where('to_status', 'awaiting_contract')->sole();
        $this->assertSame($interview, $event->interview_id);
        $this->assertSame('outcome', $event->decision_type);
        $this->assertStringStartsWith('2026-10-30 08:00:00', $event->occurred_at);
        Log::shouldHaveReceived('notice')->withArgs(fn(string $event) => $event === 'interview.recorded')->once();
    }

    public function test_failed_and_absent_results(): void
    {
        $failed = $this->awaitingInterview('11111111111');
        $absent = $this->awaitingInterview('22222222222');

        $this->record($this->scheduled($failed), ['result' => 'failed', 'scientific_grade' => 'weak', 'general_grade' => 'average', 'overall_grade' => 'weak'])
            ->assertOk()->assertJsonPath('data.application.status', 'interview_failed');
        $this->record($this->scheduled($absent), ['result' => 'absent', 'notes' => 'لم يحضر ولم يعتذر'])
            ->assertOk()->assertJsonPath('data.application.status', 'interview_absent')->assertJsonPath('data.grades.overall', null);
    }

    public function test_grades_are_needed_when_attended_and_refused_when_absent(): void
    {
        $interview = $this->scheduled($this->awaitingInterview());

        $this->record($interview, ['result' => 'passed'])->assertStatus(422)->assertJsonValidationErrors(['scientific_grade', 'general_grade', 'overall_grade']);
        $this->record($interview, ['result' => 'absent', 'overall_grade' => 'good'])->assertStatus(422)->assertJsonValidationErrors('overall_grade');
        $this->record($interview, ['result' => 'passed', 'overall_grade' => 'outstanding'] + self::GRADES)->assertStatus(422)->assertJsonValidationErrors('overall_grade');   // not on the scale
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();
    }

    public function test_dates_of_a_result(): void
    {
        $interview = $this->scheduled($this->awaitingInterview());

        $this->record($interview, ['result' => 'passed', 'held_at' => '2026-10-31T10:00:00Z'] + self::GRADES)->assertStatus(422)->assertJsonValidationErrors('held_at');
        $this->record($interview, ['result' => 'passed', 'held_at' => '2026-10-30T07:00:00Z'] + self::GRADES)       // before the sitting
            ->assertStatus(422)->assertJsonPath('errors.held_at.0', __('interviews.held_before_sitting', ['start' => '2026-10-30T08:00:00+00:00']));

        $early = $this->scheduled($this->awaitingInterview('22222222222'), $this->sitting(['starts_at' => '2026-10-20T08:00:00Z']));
        $this->record($early, ['result' => 'passed', 'held_at' => '2026-10-20T10:00:00Z'] + self::GRADES)       // before the exam decision
            ->assertStatus(422)->assertJsonPath('errors.held_at.0', __('interviews.held_before_previous_decision'));
        $this->assertSame('scheduled', Interview::query()->findOrFail($interview)->status->value);              // nothing was kept
    }

    public function test_no_result_once_the_application_has_moved_on(): void
    {
        $application = $this->awaitingInterview();
        $interview = $this->scheduled($application);
        $this->closeApplication($application);

        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertStatus(422)->assertJsonValidationErrors('application');
    }

    // ---- corrections ----

    private function corrector(): StaffUser
    {
        return $this->allow($this->hiringStaff(), 'interviews.correct');
    }

    public function test_correcting_grades_adds_a_revision_and_leaves_the_status(): void
    {
        $application = $this->awaitingInterview();
        $interview = $this->scheduled($application);
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();

        $this->asStaff($this->corrector())->postJson("/api/v1/staff/interviews/{$interview}/correct", ['overall_grade' => 'excellent', 'reason' => 'خطأ نقل من الاستمارة'])
            ->assertOk()
            ->assertJsonPath('data.grades.overall.value', 'excellent')
            ->assertJsonPath('data.revisions.0.grades.overall', 'very_good')
            ->assertJsonPath('data.revisions.1.reason', 'خطأ نقل من الاستمارة')
            ->assertJsonPath('data.application.status', 'awaiting_contract');

        $this->assertSame(0, DB::table('application_status_events')->where('application_id', $application->id)->where('decision_type', 'correction')->count());
    }

    public function test_correcting_the_result_moves_the_application_with_a_correction_event(): void
    {
        $application = $this->awaitingInterview();
        $interview = $this->scheduled($application);
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();
        $corrector = $this->corrector();
        $url = "/api/v1/staff/interviews/{$interview}/correct";

        $this->asStaff($corrector)->postJson($url, ['result' => 'failed', 'reason' => 'النتيجة في المحضر راسب'])
            ->assertOk()->assertJsonPath('data.application.status', 'interview_failed')->assertJsonPath('data.grades.overall.value', 'very_good');

        // A second correction starts from the original status again: awaiting_interview could also give "absent".
        $this->asStaff($corrector)->postJson($url, ['result' => 'absent', 'reason' => 'المرشح لم يحضر أصلاً'])
            ->assertOk()->assertJsonPath('data.application.status', 'interview_absent')->assertJsonPath('data.grades.overall', null)->assertJsonCount(3, 'data.revisions');

        $this->asStaff($corrector)->postJson($url, ['result' => 'passed', 'reason' => 'سهو'])->assertStatus(422)->assertJsonValidationErrors('overall_grade');   // attended again: grades needed

        $events = DB::table('application_status_events')->where('application_id', $application->id)->where('decision_type', 'correction')->orderBy('created_at')->get();
        $this->assertSame(['interview_failed', 'interview_absent'], $events->pluck('to_status')->all());
        $this->assertSame([$interview, $interview], $events->pluck('interview_id')->all());
        $this->assertSame([$corrector->id, $corrector->id], $events->pluck('actor_id')->all());
    }

    public function test_the_result_is_corrected_only_while_it_is_the_latest_decision(): void
    {
        $application = $this->awaitingInterview();
        $interview = $this->scheduled($application);
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();
        app(ApplicationLifecycle::class)->move($application, S::ContractWritten, D::Outcome);

        $this->asStaff($this->corrector())->postJson("/api/v1/staff/interviews/{$interview}/correct", ['result' => 'failed', 'reason' => 'متأخر'])
            ->assertStatus(422)->assertJsonPath('errors.result.0', __('interviews.result_not_latest'));
        $this->assertSame(1, Interview::query()->findOrFail($interview)->revisions()->count());   // nothing was kept

        // Grades and notes can still be corrected: they do not move the application.
        $this->asStaff($this->corrector())->postJson("/api/v1/staff/interviews/{$interview}/correct", ['notes' => 'تصحيح ملاحظة', 'reason' => 'سهو'])->assertOk();
    }

    public function test_correcting_needs_the_individual_permission_and_a_reason(): void
    {
        $interview = $this->scheduled($this->awaitingInterview());
        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();
        $url = "/api/v1/staff/interviews/{$interview}/correct";

        $this->asStaff($this->staff)->postJson($url, ['overall_grade' => 'good', 'reason' => 'سبب'])->assertForbidden();
        $this->asStaff($this->corrector())->postJson($url, ['overall_grade' => 'good'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->asStaff($this->corrector())->postJson($url, ['reason' => 'سبب'])->assertStatus(422)->assertJsonValidationErrors('result');
    }

    // ---- excuses ----

    private function approver(): StaffUser
    {
        return $this->allow($this->hiringStaff(), 'exceptions.approve');
    }

    public function test_an_accepted_excuse_brings_the_application_back_and_books_the_next_round(): void
    {
        $application = $this->awaitingInterview();
        $first = $this->scheduled($application);
        $this->record($first, ['result' => 'absent', 'notes' => 'مرض'])->assertOk();
        $sitting = $this->sitting(['starts_at' => '2026-11-06T08:00:00Z']);

        $this->asStaff($this->approver())->postJson("/api/v1/staff/applications/{$application->id}/interview-excuse", [
            'reason' => 'تقرير طبي', 'appointment' => ['sitting_id' => $sitting],
        ])->assertCreated()->assertJsonPath('data.round', 2)->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('awaiting_interview', $application->fresh()->current_status->value);
        $this->assertSame('absent', Interview::query()->findOrFail($first)->result->value);   // round 1 stays absent
        $event = DB::table('application_status_events')->where('application_id', $application->id)->where('decision_type', 'excuse_accepted')->sole();
        $this->assertSame($first, $event->interview_id);
        $this->assertSame('تقرير طبي', $event->reason);

        $this->asStaff($this->staff)->getJson('/api/v1/staff/interviews?result=absent&excused=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first);
        $this->asStaff($this->staff)->getJson('/api/v1/staff/interviews?result=absent&excused=0')->assertJsonCount(0, 'data');
    }

    public function test_an_excuse_without_appointment_and_its_rules(): void
    {
        $application = $this->awaitingInterview();
        $url = "/api/v1/staff/applications/{$application->id}/interview-excuse";

        $this->asStaff($this->approver())->postJson($url, ['reason' => 'عذر'])->assertStatus(422)->assertJsonValidationErrors('application');   // not absent
        $this->record($this->scheduled($application), ['result' => 'absent'])->assertOk();

        $this->asStaff($this->staff)->postJson($url, ['reason' => 'عذر'])->assertForbidden();
        $this->asStaff($this->approver())->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->asStaff($this->approver())->postJson($url, ['reason' => 'عذر مقبول'])->assertNoContent();
        $this->assertSame('awaiting_interview', $application->fresh()->current_status->value);
    }

    // ---- reading ----

    public function test_interviews_are_listed_and_filtered(): void
    {
        $sitting = $this->sitting();
        $this->record($this->scheduled($this->awaitingInterview('11111111111'), $sitting), ['result' => 'passed'] + self::GRADES)->assertOk();
        $this->record($this->scheduled($this->awaitingInterview('22222222222'), $sitting), ['result' => 'failed', 'scientific_grade' => 'weak', 'general_grade' => 'weak', 'overall_grade' => 'weak'])->assertOk();
        $this->scheduled($this->awaitingInterview('33333333333'), $this->sitting(['committee_name' => 'لجنة حمص', 'starts_at' => '2026-11-10T08:00:00Z']));
        $reader = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($reader)->getJson('/api/v1/staff/interviews')->assertOk()->assertJsonCount(3, 'data');
        $this->asStaff($reader)->getJson('/api/v1/staff/interviews?status=scheduled')->assertJsonCount(1, 'data');
        $this->asStaff($reader)->getJson('/api/v1/staff/interviews?result=passed')->assertJsonCount(1, 'data');
        $this->asStaff($reader)->getJson("/api/v1/staff/interviews?sitting_id={$sitting}")->assertJsonCount(2, 'data');
        $this->asStaff($reader)->getJson('/api/v1/staff/interviews?committee_name=' . urlencode('حمص'))->assertJsonCount(1, 'data');
        $this->asStaff($reader)->getJson('/api/v1/staff/interviews?from=2026-11-01')->assertJsonCount(1, 'data');
        $this->asStaff($reader)->getJson('/api/v1/staff/interview-sittings?from=2026-11-01')->assertJsonCount(1, 'data')->assertJsonPath('data.0.interviews_count', 1);
        $this->asStaff($reader)->getJson('/api/v1/staff/interviews/queue')->assertOk();
    }

    public function test_an_applications_rounds(): void
    {
        $application = $this->awaitingInterview();
        $this->record($this->scheduled($application), ['result' => 'absent'])->assertOk();
        $sitting = $this->sitting();   // before switching to the approver: sitting() acts as the staff user
        $this->asStaff($this->approver())->postJson("/api/v1/staff/applications/{$application->id}/interview-excuse", ['reason' => 'عذر', 'appointment' => ['sitting_id' => $sitting]])->assertCreated();

        $this->asStaff($this->staff)->getJson("/api/v1/staff/applications/{$application->id}/interviews")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.result.value', 'absent')->assertJsonPath('data.1.status', 'scheduled');
    }

    public function test_permissions(): void
    {
        $application = $this->awaitingInterview();
        $manager = StaffUserFactory::new()->withRole('general_manager')->create();

        $this->asStaff($manager)->postJson('/api/v1/staff/interview-sittings', ['starts_at' => '2026-11-01T08:00:00Z', 'location' => 'x', 'committee_name' => 'x', 'members' => $this->members()])->assertForbidden();
        $sitting = $this->sitting();   // before switching to the manager: sitting() acts as the staff user
        $this->asStaff($manager)->postJson("/api/v1/staff/applications/{$application->id}/interviews", ['sitting_id' => $sitting])->assertForbidden();
        $this->asStaff(StaffUserFactory::new()->create())->getJson('/api/v1/staff/interviews')->assertForbidden();
    }

    // ---- database ----

    public function test_the_database_keeps_results_consistent_and_history_intact(): void
    {
        $application = $this->awaitingInterview();
        $sitting = $this->sitting();
        $interview = $this->scheduled($application, $sitting);
        $refused = function (callable $write) {
            try {
                DB::transaction($write);
                $this->fail('The database accepted the write.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $recorded = ['status' => 'recorded', 'held_at' => '2026-10-30 08:00:00', 'recorded_by' => $this->staff->id, 'recorded_at' => now()];

        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['status' => 'recorded']));                              // no result
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update($recorded + ['result' => 'passed']));                     // attended without grades
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update($recorded + ['result' => 'absent', 'overall_grade' => 'good']));
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['result' => 'absent', 'held_at' => '2026-10-30 10:00:00', 'recorded_at' => '2026-10-30 09:00:00'] + $recorded));   // held after it was entered
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['result' => 'absent', 'held_at' => '2026-10-30 07:00:00'] + $recorded));   // held before the sitting
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $this->staff->id]));   // no reason
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['scientific_grade' => 'outstanding']));
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['status' => 'done']));
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['scheduled_at' => '2026-10-30 07:00:00']));               // before the sitting
        $refused(fn() => DB::table('interviews')->where('id', $interview)->update(['sitting_id' => null]));                                 // always in a sitting
        $refused(fn() => DB::table('interviews')->where('id', $interview)->delete());
        $refused(fn() => DB::table('interviews')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'application_id' => $application->id, 'round' => 2, 'sitting_id' => $sitting, 'scheduled_at' => '2026-10-30 09:00:00', 'status' => 'scheduled', 'created_by' => $this->staff->id]));   // a second waiting interview
        $refused(fn() => DB::table('interview_sittings')->where('id', $sitting)->update(['starts_at' => '2026-10-30 09:00:00']));          // after a booking
        $refused(fn() => DB::table('interview_sittings')->where('id', $sitting)->update(['kind' => 'one_off']));
        $refused(fn() => DB::table('interview_members')->insert(['position' => 1, 'name' => 'x', 'capacity' => 'member']));                 // no sitting
        $refused(fn() => DB::table('interview_members')->insert(['sitting_id' => $sitting, 'sitting_kind' => 'committee', 'position' => 9, 'name' => 'x', 'capacity' => 'رئيس']));
        $refused(fn() => DB::table('interview_members')->insert(['sitting_id' => $sitting, 'sitting_kind' => 'committee', 'position' => 9, 'name' => 'x', 'capacity' => 'chair']));   // a second chair

        $this->record($interview, ['result' => 'passed'] + self::GRADES)->assertOk();
        $refused(fn() => DB::table('interview_members')->where('sitting_id', $sitting)->update(['name' => 'غيره']));                          // frozen
        $refused(fn() => DB::table('interview_members')->insert(['sitting_id' => $sitting, 'sitting_kind' => 'committee', 'position' => 9, 'name' => 'x', 'capacity' => 'member']));
        $refused(fn() => DB::table('interview_result_revisions')->update(['result' => 'failed']));
        $refused(fn() => DB::table('interview_result_revisions')->delete());
        $refused(fn() => DB::table('interview_reschedules')->insert(['interview_id' => $interview, 'from_sitting_id' => $sitting, 'to_sitting_id' => $sitting, 'from_scheduled_at' => now(), 'to_scheduled_at' => now(), 'reason' => 'x', 'rescheduled_by' => $this->staff->id, 'created_at' => now()]));   // nothing changed
    }

    /**
     * The deferred committee check runs at commit, which tests never reach: SET CONSTRAINTS ... IMMEDIATE runs it now.
     * The message is checked, so the refusal comes from this rule and not from any other error.
     */
    public function test_every_sitting_keeps_a_committee_at_commit(): void
    {
        $sitting = $this->sitting();
        $check = function (callable $write): void {
            try {
                DB::transaction(function () use ($write) {
                    $write();
                    DB::statement('SET CONSTRAINTS interview_sittings_have_members, interview_members_keep_one IMMEDIATE');
                });
                $this->fail('The database accepted a sitting without a committee.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('A sitting needs at least one committee member', $e->getMessage());
            }
        };

        $check(fn() => DB::table('interview_members')->where('sitting_id', $sitting)->delete());
        $check(fn() => DB::table('interview_sittings')->insert(['id' => (string) \Illuminate\Support\Str::uuid7(), 'kind' => 'committee', 'starts_at' => now(), 'location' => 'x', 'created_by' => $this->staff->id]));

        // A committee replaced inside one transaction is fine.
        DB::transaction(function () use ($sitting) {
            DB::table('interview_members')->where('sitting_id', $sitting)->delete();
            DB::table('interview_members')->insert(['sitting_id' => $sitting, 'sitting_kind' => 'committee', 'position' => 1, 'name' => 'عضو', 'capacity' => 'member']);
            DB::statement('SET CONSTRAINTS interview_sittings_have_members, interview_members_keep_one IMMEDIATE');
        });
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame(1, DB::table('interview_members')->where('sitting_id', $sitting)->count());
    }

    public function test_a_one_off_sitting_has_one_member_in_the_database(): void
    {
        $sitting = $this->oneOffSitting();
        $committee = $this->sitting();
        $insert = fn(array $row) => DB::table('interview_members')->insert($row + ['position' => 9, 'name' => 'عضو ثان', 'capacity' => 'member']);

        foreach ([
            'a second member' => ['sitting_id' => $sitting, 'sitting_kind' => 'one_off', 'message' => 'interview_members_one_off_single'],
            'its kind given wrong' => ['sitting_id' => $sitting, 'sitting_kind' => 'committee', 'message' => 'foreign key'],
            'a committee member said one-off' => ['sitting_id' => $committee, 'sitting_kind' => 'one_off', 'message' => 'foreign key'],
        ] as $case => $row) {
            try {
                DB::transaction(fn() => $insert(array_diff_key($row, ['message' => true])));
                $this->fail("The database accepted {$case}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($row['message'], $e->getMessage(), $case);
            }
        }
    }

    /** The committee key is computed at commit (deferred), which tests never reach: SET CONSTRAINTS runs it now. */
    public function test_the_database_refuses_the_same_sitting_twice_at_commit(): void
    {
        $first = $this->sitting(['location' => 'القاعة 2']);
        DB::statement('SET CONSTRAINTS interview_members_key_sitting IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame(32, strlen((string) DB::table('interview_sittings')->where('id', $first)->value('committee_key')));
        $copy = (string) \Illuminate\Support\Str::uuid7();

        try {
            DB::transaction(function () use ($copy) {
                DB::table('interview_sittings')->insert(['id' => $copy, 'kind' => 'committee', 'starts_at' => '2026-10-30 08:00:00', 'location' => 'القاعه  2', 'created_by' => $this->staff->id]);
                foreach (array_reverse($this->members()) as $index => $member) {
                    DB::table('interview_members')->insert(['sitting_id' => $copy, 'sitting_kind' => 'committee', 'position' => $index + 1] + $member);
                }
                DB::statement('SET CONSTRAINTS interview_members_key_sitting IMMEDIATE');
            });
            $this->fail('The database accepted the same sitting twice.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('interview_sittings_no_duplicate', $e->getMessage());
        }

        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertFalse(DB::table('interview_sittings')->where('id', $copy)->exists());
    }
}
