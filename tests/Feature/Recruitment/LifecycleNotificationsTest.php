<?php

namespace Tests\Feature\Recruitment;

use App\Modules\Documents\Database\Seeders\DocumentTypesSeeder;
use App\Modules\Identity\Database\Factories\StaffUserFactory;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Notifications\ApplicationsMoved;
use App\Modules\Recruitment\Support\LifecycleNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecruitmentTestHelpers;
use Tests\Support\StaffTestHelpers;
use Tests\TestCase;

/** The offices are told when applications move (D-089). */
class LifecycleNotificationsTest extends TestCase
{
    use RecruitmentTestHelpers, RefreshDatabase, StaffTestHelpers;

    /** @var array<string, StaffUser> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DocumentTypesSeeder::class]);

        foreach (['recruitment', 'recruitment_two' => 'recruitment', 'exams_interviews', 'exams_two' => 'exams_interviews', 'exams_only' => 'exams', 'interviews_only' => 'interviews', 'contracts', 'documenting', 'general_manager', 'system_admin'] as $key => $role) {
            $this->staff[is_int($key) ? $role : $key] = $this->staffOf($role);
        }

        $this->staff['inactive'] = StaffUserFactory::new()->withRole('recruitment')->create(['is_active' => false]);
    }

    /** @return array<string, list<array>> who got which moves (their details), by staff key */
    private function told(): array
    {
        $told = [];

        foreach ($this->staff as $key => $user) {
            $told[$key] = DB::table('notifications')->where('notifiable_id', $user->id)->where('type', ApplicationsMoved::class)->orderBy('created_at')->pluck('data')->map(fn (string $data) => json_decode($data, true))->all();
        }

        return array_filter($told);
    }

    private function forget(): void
    {
        DB::table('notifications')->delete();
    }

    public function test_a_recorded_interview_tells_recruitment_and_the_contracts_office_not_the_recorder(): void
    {
        $application = $this->openApplication($this->staff['recruitment']);
        app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome);
        $this->forget();

        app(ApplicationLifecycle::class)->move($application, S::AwaitingContract, D::Outcome, $this->staff['exams_interviews']);

        $told = $this->told();
        $this->assertSame(['recruitment', 'recruitment_two', 'contracts'], array_keys($told));   // not the recorder, the managers or the inactive
        $this->assertCount(1, $told['contracts']);
        $details = $told['contracts'][0];
        $this->assertSame(['applications_moved', 'awaiting_interview', 'awaiting_contract', 'outcome', 1], [$details['kind'], $details['from_status'], $details['to_status'], $details['decision_type'], $details['count']]);
        $this->assertEquals([['id' => $application->id, 'nomination_number' => $application->nomination_number, 'candidate_name' => 'أحمد محمد علي']], $details['applications']);
        $this->assertSame($this->staff['exams_interviews']->id, $details['actor']['id']);
        $this->assertStringContainsString('ناجح بالمقابلة بانتظار كتابة العقد', $details['message']);
        $this->assertStringContainsString($application->nomination_number, $details['message']);

        // Through the API.
        $this->asStaff($this->staff['recruitment'])->getJson('/api/v1/staff/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'applications_moved')
            ->assertJsonPath('data.0.details.to_status', 'awaiting_contract')
            ->assertJsonPath('data.0.title', __('recruitment.moved_title_one', [], 'ar'));
    }

    public function test_a_bulk_start_is_one_notification_for_all_and_reaches_the_documenting_office(): void
    {
        $applications = collect(['01111111111', '02222222222', '03333333333'])->map(fn (string $number) => $this->openApplication($this->staff['recruitment'], ['candidate' => ['national_number' => $number]], start: false));
        $this->assertSame(['recruitment_two'], array_keys($this->told()));   // received: Recruitment's own section
        $this->forget();

        $this->asStaff($this->staff['recruitment'])->postJson('/api/v1/staff/applications/start', ['application_ids' => $applications->pluck('id')->all()])->assertOk();

        $told = $this->told();
        // The ID card is required from awaiting_exam: the Documenting office is told too.
        $this->assertSame(['recruitment_two', 'exams_interviews', 'exams_two', 'exams_only', 'documenting'], array_keys($told));

        foreach ($told as $notifications) {
            $this->assertCount(1, $notifications);
            $this->assertSame(3, $notifications[0]['count']);
            $this->assertCount(3, $notifications[0]['applications']);
            $this->assertSame('started', $notifications[0]['decision_type']);
        }

        $this->assertSame(__('recruitment.moved_title_many', ['count' => 3], 'ar'), $told['exams_two'][0]['title']);
    }

    public function test_moves_without_a_staff_user_tell_the_whole_section(): void
    {
        $application = $this->openApplication($this->staff['recruitment']);
        $this->forget();

        app(ApplicationLifecycle::class)->move($application, S::ExamAbsent, D::Outcome);   // the exam sweep: nobody acted

        $this->assertSame(['recruitment', 'recruitment_two', 'exams_interviews', 'exams_two', 'exams_only'], array_keys($this->told()));
        $this->assertNull($this->told()['exams_two'][0]['actor']);
    }

    public function test_the_exams_and_the_interviews_roles_are_told_their_own_sections_moves(): void
    {
        $application = $this->openApplication($this->staff['recruitment']);
        $this->forget();

        // Passed the exam: the interviews' section now (D-097).
        app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome, $this->staff['exams_only']);
        app(ApplicationLifecycle::class)->move($application, S::AwaitingInterview, D::Outcome, $this->staff['exams_only']);
        $this->assertSame(['recruitment', 'recruitment_two', 'exams_interviews', 'exams_two', 'interviews_only'], array_keys($this->told()));
        $this->forget();

        app(ApplicationLifecycle::class)->move($application, S::InterviewFailed, D::Outcome, $this->staff['interviews_only']);
        $this->assertSame(['recruitment', 'recruitment_two', 'exams_interviews', 'exams_two'], array_keys($this->told()));
    }

    public function test_a_rolled_back_move_tells_nobody(): void
    {
        $application = $this->openApplication($this->staff['recruitment']);
        $this->forget();

        try {
            DB::transaction(function () use ($application) {
                app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome);
                app(ApplicationLifecycle::class)->move($application, S::ExamFailed, D::Outcome);
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->told());
        $this->assertSame(S::AwaitingExam, $application->fresh()->current_status);

        // A savepoint rolled back inside a kept transaction drops its own moves only.
        DB::transaction(function () use ($application) {
            try {
                DB::transaction(function () use ($application) {
                    app(ApplicationLifecycle::class)->move($application, S::ExamTaken, D::Outcome);
                    app(ApplicationLifecycle::class)->move($application, S::ExamFailed, D::Outcome);
                    throw new \RuntimeException('inner');
                });
            } catch (\RuntimeException) {
            }

            app(ApplicationLifecycle::class)->move($application, S::ExamAbsent, D::Outcome);
        });

        $this->assertSame(['exam_absent'], array_column($this->told()['exams_two'], 'to_status'));
    }

    public function test_a_batch_of_commits_is_told_together(): void
    {
        $first = $this->openApplication($this->staff['recruitment']);
        $second = $this->openApplication($this->staff['recruitment'], ['candidate' => ['national_number' => '02222222222']]);
        $this->forget();

        app(LifecycleNotices::class)->batch(function () use ($first, $second) {
            DB::transaction(fn () => app(ApplicationLifecycle::class)->move($first, S::ExamAbsent, D::Outcome));
            DB::transaction(fn () => app(ApplicationLifecycle::class)->move($second, S::ExamAbsent, D::Outcome));
            $this->assertSame([], $this->told());   // nothing yet
        });

        $this->assertSame([2], array_column($this->told()['exams_two'], 'count'));
    }
}
