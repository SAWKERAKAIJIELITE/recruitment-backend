<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\ApplicationLifecycle;
use App\Modules\Recruitment\Actions\ChangeCandidateActivation;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Database\Seeders\Demo\DemoPeople;
use App\Modules\Recruitment\Database\Seeders\Demo\DemoReferences;
use App\Modules\Recruitment\Enums\ApplicationStatus as S;
use App\Modules\Recruitment\Enums\DecisionType as D;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Candidate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * LOCAL DEVELOPMENT ONLY. People and applications in every status, each with a dated history, so screens and
 * reports have something to show.
 *
 * Everything goes through the real actions (CreateApplication, ApplicationLifecycle, ChangeCandidateActivation):
 * the database triggers accept history only that way, and the demo then follows the same rules as real data.
 * The clock is moved back while seeding, so applications are spread over the last months and every decision
 * is recorded on the day it happened (occurred_at = created_at). Exam and interview records do not exist yet,
 * so the outcomes carry no cause record.
 *
 * Needs ReferenceDataSeeder and DevUsersSeeder (users "staff" and "manager"). Runs once: it stops if the
 * first demo person already exists.
 */
class RecruitmentDemoSeeder extends Seeder
{
    /**
     * Each scenario: the moves after the application is opened, and how many people follow it.
     * A move: [to status, decision type, reason for decisions made by a person].
     */
    private const SCENARIOS = [
        'awaiting_exam' => [8, []],
        'exam_failed' => [3, [[S::ExamFailed, D::Outcome]]],
        'exam_absent' => [2, [[S::ExamAbsent, D::Outcome]]],
        'exam_absent_excused' => [1, [[S::ExamAbsent, D::Outcome], [S::AwaitingExam, D::ExcuseAccepted, 'عذر طبي موثق بتقرير من مشفى حكومي']]],
        'awaiting_interview' => [5, [[S::AwaitingInterview, D::Outcome]]],
        'exam_exception' => [1, [[S::ExamFailed, D::Outcome], [S::AwaitingInterview, D::Exception, 'معدل قريب من حد النجاح وحاجة ماسة إلى الاختصاص']]],
        'interview_failed' => [2, [[S::AwaitingInterview, D::Outcome], [S::InterviewFailed, D::Outcome]]],
        'interview_absent' => [1, [[S::AwaitingInterview, D::Outcome], [S::InterviewAbsent, D::Outcome]]],
        'interview_absent_excused' => [1, [[S::AwaitingInterview, D::Outcome], [S::InterviewAbsent, D::Outcome], [S::AwaitingInterview, D::ExcuseAccepted, 'تعارض مع موعد امتحان جامعي']]],
        'awaiting_contract' => [3, [[S::AwaitingInterview, D::Outcome], [S::AwaitingContract, D::Outcome]]],
        'contract_written' => [4, [[S::AwaitingInterview, D::Outcome], [S::AwaitingContract, D::Outcome], [S::ContractWritten, D::Outcome]]],
        'contract_ended' => [2, [[S::AwaitingInterview, D::Outcome], [S::AwaitingContract, D::Outcome], [S::ContractWritten, D::Outcome], [S::ContractEnded, D::Outcome]]],
        'closed' => [2, [[S::Closed, D::Closure, 'انسحب المرشح بطلب خطي']]],
        'closed_after_interview' => [1, [[S::AwaitingInterview, D::Outcome], [S::InterviewFailed, D::Outcome], [S::Closed, D::Closure, 'لا مجال لإعادة المقابلة']]],
    ];

    /** The demo starts this many days ago; every date stays in the past. */
    private const DAYS_BACK = 160;

    public function __construct(
        private readonly CreateApplication $create,
        private readonly ApplicationLifecycle $lifecycle,
        private readonly ChangeCandidateActivation $activation,
    ) {
    }

    public function run(): void
    {
        if (Candidate::query()->where('national_number', DemoPeople::nationalNumber(1))->exists()) {
            $this->command?->info('Recruitment demo data is already there: skipped.');

            return;
        }

        $references = new DemoReferences();
        $staff = StaffUser::query()->where('username', 'staff')->firstOrFail();
        $manager = StaffUser::query()->where('username', 'manager')->firstOrFail();   // holds exceptions.approve and applications.close

        $start = CarbonImmutable::now()->subDays(self::DAYS_BACK)->setTime(9, 0);
        $index = 0;

        try {
            foreach (self::SCENARIOS as [$count, $moves]) {
                for ($i = 0; $i < $count; $i++) {
                    $index++;
                    $this->follow($references, $index, $start->addDays($index * 2), $moves, $staff, $manager);
                }
            }

            // Nominated again: the first application was closed, a new one is live.
            $index++;
            $first = $this->follow($references, $index, $start->addDays($index * 2), [[S::Closed, D::Closure, 'تقدم لمسمى آخر']], $staff, $manager);
            $this->follow($references, $index, $first->addDays(5), [], $staff, $manager, ['job_position_id' => $references->entry('job_position_id', $index + 1)->id]);

            // Deactivated: the application ended, then the person was deactivated.
            $index++;
            $ended = $this->follow($references, $index, $start->addDays($index * 2), [[S::Closed, D::Closure, 'تبين أنه يعمل لدى جهة أخرى']], $staff, $manager);
            Date::setTestNow($ended->addDay());
            $this->activation->deactivate(
                Candidate::query()->where('national_number', DemoPeople::nationalNumber($index))->firstOrFail(),
                $manager,
                'طلب الجهة المرشحة عدم ترشيحه مجددًا',
            );
        } finally {
            Date::setTestNow();
        }

        $this->command?->info("Recruitment demo: {$index} people, " . Application::query()->count() . ' applications.');
    }

    /**
     * Opens an application for demo person $index at $openedAt and applies the moves a few days apart.
     *
     * @return CarbonImmutable the date of the last decision
     */
    private function follow(DemoReferences $references, int $index, CarbonImmutable $openedAt, array $moves, StaffUser $staff, StaffUser $manager, array $overrides = []): CarbonImmutable
    {
        $at = $openedAt->addMinutes(($index * 37) % 420);
        Date::setTestNow($at);

        $application = $this->create->execute(array_replace($references->applicationInput($index), $overrides), $staff)['application'];

        foreach ($moves as $step => $move) {
            [$to, $type] = $move;
            $at = $at->addDays(3 + ($index + $step * 5) % 8)->addMinutes(($index * 13) % 300);
            Date::setTestNow($at);

            $this->lifecycle->move($application, $to, $type, $type->needsReasonAndActor() ? $manager : $staff, $move[2] ?? null);
        }

        return $at;
    }
}
