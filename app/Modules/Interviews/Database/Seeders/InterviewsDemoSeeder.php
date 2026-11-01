<?php

namespace App\Modules\Interviews\Database\Seeders;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Actions\ManageInterviews;
use App\Modules\Interviews\Actions\ManageSittings;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY. Interviews through the real actions, for the demo applications awaiting their interview:
 * a sitting three days ago with recorded results (passed, passed, failed, absent), a sitting in five days with two
 * bookings, and a one-off appointment. Needs RecruitmentDemoSeeder and DevUsersSeeder ("staff" holds
 * interviews.manage). Runs once.
 */
class InterviewsDemoSeeder extends Seeder
{
    private const COMMITTEE = [
        ['name' => 'محمد السالم', 'capacity' => 'chair'],
        ['name' => 'خالد العمر', 'capacity' => 'member'],
        ['name' => 'سامر الحسن', 'capacity' => 'secretary'],
    ];

    public function __construct(private readonly ManageSittings $sittings, private readonly ManageInterviews $interviews)
    {
    }

    public function run(): void
    {
        if (Interview::query()->exists()) {
            $this->command?->info('Interviews already exist: demo skipped.');

            return;
        }

        $staff = StaffUser::query()->where('username', 'staff')->firstOrFail();
        $waiting = Application::query()->where('current_status', ApplicationStatus::AwaitingInterview)->orderBy('created_at')->get();
        $today = CarbonImmutable::today();

        $past = $this->sittings->create(['starts_at' => $today->subDays(3)->setTime(9, 0), 'location' => 'مبنى الإدارة العامة - القاعة 2', 'committee_name' => 'لجنة دمشق الأولى'], self::COMMITTEE, $staff);
        $results = [
            ['result' => 'passed', 'scientific_grade' => 'excellent', 'general_grade' => 'very_good', 'overall_grade' => 'excellent', 'committee_summary' => 'إجابات متمكنة وحضور جيد'],
            ['result' => 'passed', 'scientific_grade' => 'good', 'general_grade' => 'good', 'overall_grade' => 'good'],
            ['result' => 'failed', 'scientific_grade' => 'weak', 'general_grade' => 'average', 'overall_grade' => 'weak', 'notes' => 'ضعف في المادة العلمية'],
            ['result' => 'absent', 'notes' => 'لم يحضر ولم يعتذر'],
        ];

        foreach ($waiting->take(count($results))->values() as $index => $application) {
            $interview = $this->interviews->schedule($application, ['sitting_id' => $past->id, 'scheduled_at' => $past->starts_at->addMinutes(30 * $index)], $staff);
            $this->interviews->record($interview, $results[$index], $staff);
        }

        $next = $this->sittings->create(['starts_at' => $today->addDays(5)->setTime(9, 0), 'location' => 'مديرية الأوقاف - قاعة الاجتماعات', 'committee_name' => 'لجنة حمص'], array_slice(self::COMMITTEE, 0, 2), $staff);

        foreach ($waiting->slice(count($results), 2)->values() as $index => $application) {
            $this->interviews->schedule($application, ['sitting_id' => $next->id, 'scheduled_at' => $next->starts_at->addMinutes(45 * $index)], $staff);
        }

        if (($oneOff = $waiting->get(count($results) + 2)) !== null) {
            $this->interviews->schedule($oneOff, [
                'scheduled_at' => $today->addDays(2)->setTime(11, 0),
                'location' => 'اتصال مرئي',
                'committee_name' => 'لجنة خاصة',
                'members' => [['name' => 'محمد السالم', 'capacity' => 'chair', 'staff_user_id' => $staff->id]],
            ], $staff);
        }

        $this->command?->info('Interviews demo: ' . Interview::query()->count() . ' interviews in 2 sittings and a one-off appointment.');
    }
}
