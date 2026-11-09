<?php

namespace App\Modules\Interviews\Database\Seeders;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Actions\ManageSittings;
use App\Modules\Interviews\Enums\SittingKind;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * LOCAL DEVELOPMENT ONLY. Interviews through the real actions, for the demo applications awaiting their interview:
 * a sitting three days ago with recorded results (passed, passed, failed, absent), a sitting in five days with two
 * bookings, and a one-off sitting (one interviewer) with two candidates. Needs RecruitmentDemoSeeder and DevUsersSeeder ("exams" of the
 * Exams and Interviews office). Runs once.
 */
class InterviewsDemoSeeder extends Seeder
{
    private const COMMITTEE = [
        ['name' => 'محمد السالم', 'capacity' => 'chair'],
        ['name' => 'خالد العمر', 'capacity' => 'member'],
        ['name' => 'سامر الحسن', 'capacity' => 'secretary'],
    ];

    public function __construct(private readonly ManageSittings $sittings, private readonly ManageInterviews $interviews) {}

    public function run(): void
    {
        if (Interview::query()->exists()) {
            $this->command?->info('Interviews already exist: demo skipped.');

            return;
        }

        $staff = StaffUser::query()->where('username', 'exams')->firstOrFail();
        $waiting = Application::query()->where('current_status', ApplicationStatus::AwaitingInterview)->orderBy('created_at')->get();
        $today = CarbonImmutable::today();

        // Booked before the sitting started (nothing is booked into an ended sitting), recorded after it.
        $start = $today->subDays(3)->setTime(9, 0);
        Date::setTestNow($start->subHour());
        $past = $this->sittings->create(['starts_at' => $start, 'ends_at' => $start->addHours(5), 'location' => 'مبنى الإدارة العامة - القاعة 2', 'committee_name' => 'لجنة دمشق الأولى'], self::COMMITTEE, $staff);
        $results = [
            ['result' => 'passed', 'scientific_grade' => 'excellent', 'general_grade' => 'very_good', 'overall_grade' => 'excellent', 'committee_summary' => 'إجابات متمكنة وحضور جيد'],
            ['result' => 'passed', 'scientific_grade' => 'good', 'general_grade' => 'good', 'overall_grade' => 'good'],
            ['result' => 'failed', 'scientific_grade' => 'weak', 'general_grade' => 'average', 'overall_grade' => 'weak', 'notes' => 'ضعف في المادة العلمية'],
            ['result' => 'absent', 'notes' => 'لم يحضر ولم يعتذر'],
        ];

        try {
            $booked = $waiting->take(count($results))->values()->map(fn(Application $application, int $index) => $this->interviews->schedule($application, ['sitting_id' => $past->id, 'scheduled_at' => $past->starts_at->addMinutes(30 * $index)], $staff));

            Date::setTestNow($past->ends_at->addHour());
            foreach ($booked as $index => $interview) {
                $this->interviews->record($interview, $results[$index], $staff);
            }
        } finally {
            Date::setTestNow();
        }

        $next = $this->sittings->create(['starts_at' => $today->addDays(5)->setTime(9, 0), 'ends_at' => $today->addDays(5)->setTime(14, 0), 'location' => 'مديرية الأوقاف - قاعة الاجتماعات', 'committee_name' => 'لجنة حمص'], array_slice(self::COMMITTEE, 0, 2), $staff);

        foreach ($waiting->slice(count($results), 2)->values() as $index => $application) {
            $this->interviews->schedule($application, ['sitting_id' => $next->id, 'scheduled_at' => $next->starts_at->addMinutes(45 * $index)], $staff);
        }

        $single = $this->sittings->create(
            ['starts_at' => $today->addDays(2)->setTime(11, 0), 'ends_at' => $today->addDays(2)->setTime(15, 0), 'location' => 'اتصال مرئي'],
            [['capacity' => 'chair', 'staff_user_id' => $staff->id]],
            $staff,
            SittingKind::OneOff,
        );

        foreach ($waiting->slice(count($results) + 2, 2)->values() as $index => $application) {
            $this->interviews->schedule($application, ['sitting_id' => $single->id, 'scheduled_at' => $single->starts_at->addMinutes(40 * $index)], $staff);
        }

        $this->command?->info('Interviews demo: ' . Interview::query()->count() . ' interviews in 2 committee sittings and a one-off sitting.');
    }
}
