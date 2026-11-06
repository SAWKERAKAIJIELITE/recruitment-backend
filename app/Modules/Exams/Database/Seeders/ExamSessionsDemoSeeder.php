<?php

namespace App\Modules\Exams\Database\Seeders;

use App\Modules\Exams\Actions\ExamResults;
use App\Modules\Exams\Actions\ManageExamBookings;
use App\Modules\Exams\Actions\ManageExamSessions;
use App\Modules\Exams\Actions\SweepExamSessions;
use App\Modules\Exams\Actions\TakeExam;
use App\Modules\Exams\Enums\ExamPlanStatus;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Exams\Models\ExamAttemptItem;
use App\Modules\Exams\Models\ExamPlanSubjectPart;
use App\Modules\Exams\Models\ExamPlanVersion;
use App\Modules\Exams\Models\ExamSession;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Actions\CreateApplication;
use App\Modules\Recruitment\Database\Seeders\Demo\DemoReferences;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * Demo exam sessions (local only), on the first published plan, for six people nominated for its job position three
 * days ago (national numbers 09000000301 to 09000000306):
 *
 *  - one held yesterday: the first candidate sat it (answers saved, submitted, التجويد marked: decided), the
 *    second did not come (absent);
 *  - one tomorrow at 09:00 with the next candidates booked; they log in with their national number and the
 *    password DEMO_PASSWORD, from 08:00.
 */
class ExamSessionsDemoSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'exam-2026';

    private const FIRST_PERSON = 301;

    public function __construct(
        private readonly ManageExamSessions $sessions,
        private readonly ManageExamBookings $bookings,
        private readonly TakeExam $exam,
        private readonly ExamResults $results,
        private readonly SweepExamSessions $sweep,
        private readonly CreateApplication $create,
    ) {}

    public function run(): void
    {
        if (ExamSession::query()->exists()) {
            $this->command?->info('Exam sessions already exist: demo skipped.');

            return;
        }

        $admin = StaffUser::query()->where('username', 'admin')->firstOrFail();
        $today = CarbonImmutable::now()->startOfDay();
        $held = $today->subDay()->setTime(9, 0);
        $plan = ExamPlanVersion::query()->where('status', ExamPlanStatus::Published)->orderBy('created_at')->orderBy('id')->first();

        if ($plan === null) {
            $this->command?->info('Exam sessions demo: no published plan.');

            return;
        }

        try {
            $waiting = $this->nominate($plan, $today->subDays(3)->setTime(10, 0), StaffUser::query()->where('username', 'staff')->firstOrFail());

            Date::setTestNow($held->subHours(2));
            $past = $this->sessions->create(['plan_version_id' => $plan->id, 'starts_at' => $held, 'location' => 'قاعة الحاسوب - المبنى الرئيسي', 'password' => 'held-2026'], $admin);
            [$sat] = $this->bookings->bookMany($past, $waiting->take(2)->pluck('id')->all(), $admin);

            Date::setTestNow($held->addMinutes(3));
            $attempt = $this->exam->start($sat, '10.0.0.21');
            $this->answer($attempt);

            Date::setTestNow($held->addMinutes(95));
            $this->exam->submit($attempt);
            $this->sweep->sweep($past->id);   // the second candidate: absent

            Date::setTestNow($held->addHours(3));
            $tajweed = ExamPlanSubjectPart::query()->where('plan_version_id', $plan->id)->where('assessment', 'manual')->get();
            if ($tajweed->isNotEmpty()) {
                $this->results->enterMarks($attempt, $tajweed->mapWithKeys(fn(ExamPlanSubjectPart $part) => [$part->id => (string) round((float) $part->max_score * 0.85, 2)])->all(), null, $admin);
            }
        } finally {
            Date::setTestNow();
        }

        $next = $this->sessions->create(['plan_version_id' => $plan->id, 'starts_at' => $today->addDay()->setTime(9, 0), 'location' => 'قاعة الحاسوب - المبنى الرئيسي', 'password' => self::DEMO_PASSWORD, 'notes' => 'تُكتب كلمة المرور على السبورة عند بدء الجلسة.'], $admin);
        $booked = $this->bookings->bookMany($next, $waiting->slice(2, 4)->pluck('id')->all(), $admin);

        $this->command?->info(sprintf('Exam sessions demo: one held yesterday (1 sat, 1 absent), one tomorrow at 09:00 with %d candidates (password "%s").', count($booked), self::DEMO_PASSWORD));
    }

    /** @return Collection<int, Application> six new applications for the plan's job position */
    private function nominate(ExamPlanVersion $plan, CarbonImmutable $at, StaffUser $staff): Collection
    {
        $references = new DemoReferences;
        $applications = collect();

        foreach (range(self::FIRST_PERSON, self::FIRST_PERSON + 5) as $offset => $index) {
            Date::setTestNow($at->addMinutes($offset * 7));
            $input = array_replace($references->applicationInput($index), ['job_position_id' => $plan->job_position_id]);
            $applications->push($this->create->execute($input, $staff)['application']);
        }

        return $applications;
    }

    /** About four answers in five right (every demo question's right option is its first, as written in the bank). */
    private function answer(ExamAttempt $attempt): void
    {
        $items = ExamAttemptItem::query()->where('attempt_id', $attempt->id)->with('question.version')->orderBy('position')->get();

        foreach ($items as $index => $item) {
            $correct = $item->question->version->correct_option;
            $option = $index % 5 === 4 ? ($correct + 1) % count($item->question->version->options) : $correct;
            $this->exam->save($attempt, $item->position, $option, '10.0.0.21');
        }
    }
}
