<?php

namespace App\Modules\Contracts\Database\Seeders;

use App\Modules\Contracts\Actions\ManageContracts;
use App\Modules\Contracts\Actions\RemindExpiringContracts;
use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Models\ContractType;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEVELOPMENT ONLY. Contracts through the real actions, for the demo applications awaiting their contract: one
 * running for months, one ending within the reminder window (its reminder is sent), one whose end passed without
 * renewal, one renewed, one ended without renewal (the application is contract_ended), one without an end date.
 * The rest stay awaiting their contract. Needs RecruitmentDemoSeeder, ContractTypesSeeder and DevUsersSeeder ("staff"
 * holds contracts.manage). Runs once.
 */
class ContractsDemoSeeder extends Seeder
{
    public function __construct(private readonly ManageContracts $contracts, private readonly RemindExpiringContracts $remind)
    {
    }

    public function run(): void
    {
        if (Contract::query()->exists()) {
            $this->command?->info('Contracts already exist: demo skipped.');

            return;
        }

        $staff = StaffUser::query()->where('username', 'staff')->firstOrFail();
        $types = ContractType::query()->orderBy('id')->pluck('id')->all();
        $waiting = Application::query()->where('current_status', ApplicationStatus::AwaitingContract)->orderBy('created_at')->get();
        $today = CarbonImmutable::today();

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
}
