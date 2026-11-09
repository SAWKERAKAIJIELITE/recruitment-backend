<?php

namespace App\Modules\Contracts\Database\Seeders;

use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Actions\ManageVacancies;
use App\Modules\Contracts\Actions\RemindExpiringContracts;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Models\ContractType;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\JobPosition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * LOCAL DEVELOPMENT ONLY. Contracts through the real actions, for the demo applications awaiting their contract: one
 * running for months, one ending within the reminder window (its reminder is sent), one whose end passed without
 * renewal, one renewed, one ended without renewal (the application is contract_ended), one without an end date.
 * The rest stay awaiting their contract. First, vacancies files (uploaded by "admin") for three consecutive periods
 * from DAYS_BACK days ago, three vacancies for every job position (D-088). Needs RecruitmentDemoSeeder,
 * ContractTypesSeeder and DevUsersSeeder ("contracts" of the Contracts office). Runs once.
 */
class ContractsDemoSeeder extends Seeder
{
    private const DAYS_BACK = 170;

    public function __construct(
        private readonly ManageContracts $contracts,
        private readonly RemindExpiringContracts $remind,
        private readonly ManageVacancies $vacancies,
    ) {}

    public function run(): void
    {
        if (Contract::query()->exists()) {
            $this->command?->info('Contracts already exist: demo skipped.');

            return;
        }

        $staff = StaffUser::query()->where('username', 'contracts')->firstOrFail();
        $types = ContractType::query()->orderBy('id')->pluck('id')->all();
        $waiting = Application::query()->where('current_status', ApplicationStatus::AwaitingContract)->orderBy('created_at')->get();
        $today = CarbonImmutable::today();
        $this->uploadVacancies($today->subDays(self::DAYS_BACK), StaffUser::query()->where('username', 'admin')->firstOrFail());

        // [ends_on relative to today (null: no end), then: renew | end]
        $plans = [[200, null], [10, null], [-5, null], [-20, 'renew'], [-9, 'end'], [null, null]];

        try {
            foreach ($waiting->take(count($plans))->values() as $index => $application) {
                [$endsIn, $then] = $plans[$index];
                $lastDecision = CarbonImmutable::parse(DB::table('application_status_events')->where('application_id', $application->id)->max('occurred_at'));
                $writtenAt = $lastDecision->addDays(2)->setTime(10, 0);
                Date::setTestNow($writtenAt);

                $contract = $this->contracts->write($application, [
                    'contract_type_id' => $types[$index % count($types)],
                    'reference_number' => sprintf('ع/%d/%03d', $writtenAt->year, $index + 1),
                    'written_on' => $writtenAt->toDateString(),
                    'starts_on' => $writtenAt->addDay()->toDateString(),
                    'ends_on' => $endsIn === null ? null : $today->addDays($endsIn)->toDateString(),
                    'notes' => $index === 0 ? 'العقد الأول بعد المقابلة' : null,
                ], $staff);

                if ($then === 'renew') {
                    $renewedAt = $today->subDays(25)->setTime(11, 0);
                    Date::setTestNow($renewedAt);
                    $this->contracts->renew($contract, [
                        'contract_type_id' => $contract->contract_type_id,
                        'reference_number' => sprintf('ع/%d/%03d-ت', $renewedAt->year, $index + 1),
                        'written_on' => $renewedAt->toDateString(),
                        'starts_on' => $contract->ends_on->addDay()->toDateString(),
                        'ends_on' => $today->addDays(345)->toDateString(),
                    ], $staff);
                }

                if ($then === 'end') {
                    $endedAt = $today->addDays($endsIn)->setTime(12, 0);
                    Date::setTestNow($endedAt);
                    $this->contracts->end($contract, $endedAt->startOfDay(), 'انتهت مدة العقد ولم تطلب الجهة تجديده', $staff);
                }
            }
        } finally {
            Date::setTestNow();
        }

        $reminded = $this->remind->run();

        $this->command?->info('Contracts demo: ' . Contract::query()->count() . " contracts, {$reminded} reminder(s) sent.");
    }

    /** Three consecutive vacancies files from $from, three vacancies for every job position. */
    private function uploadVacancies(CarbonImmutable $from, StaffUser $admin): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->fromArray([['المسمى الوظيفي', 'عدد الشواغر'], ...JobPosition::query()->orderBy('id')->get()->map(fn(JobPosition $position) => [$position->name_ar, 3])->all()]);
        $path = tempnam(sys_get_temp_dir(), 'demo-vacancies-') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        try {
            $start = $from;

            for ($period = 1; $period <= 3; $period++) {
                $file = new UploadedFile($path, "الشواغر {$period}.xlsx", 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
                $plan = $this->vacancies->upload($file, $start->toDateString(), 'ملف تجريبي', $admin);
                $start = CarbonImmutable::parse($plan->ends_on)->addDay();
            }
        } finally {
            @unlink($path);
        }
    }
}
