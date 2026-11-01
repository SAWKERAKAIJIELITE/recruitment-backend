<?php

namespace App\Modules\Contracts\Actions;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Contracts\Notifications\ContractEndingSoon;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Reminds staff of contracts ending soon (owner: two weeks before; D-065). Daily (contracts:remind-expiring): every
 * latest contract of an application under contract that ends within the reminder window, not reminded yet, gets one
 * notification for whoever wrote it and for every active staff user holding contracts.manage (owner's choice: both).
 * A contract is reminded once; correcting its end date makes it due again.
 */
class RemindExpiringContracts
{
    /** @return int how many contracts were reminded */
    public function run(): int
    {
        $today = Date::today();
        $managers = $this->contractManagers();
        $count = 0;

        $due = Contract::query()
            ->latestInChain()
            ->whereNull('expiry_reminded_at')
            ->whereBetween('ends_on', [$today->toDateString(), $today->copy()->addDays(Contract::reminderDays())->toDateString()])
            ->whereHas('application', fn($application) => $application->where('current_status', ApplicationStatus::ContractWritten))
            ->orderBy('ends_on')
            ->pluck('id');

        foreach ($due as $id) {
            $count += DB::transaction(function () use ($id, $managers) {
                // Another run may have taken it: skip it rather than wait, and never remind twice.
                $contract = Contract::query()->whereKey($id)->whereNull('expiry_reminded_at')->lock('for update skip locked')->first();

                if ($contract === null) {
                    return 0;
                }

                $recipients = $managers->concat([$contract->creator])->filter(fn(?StaffUser $user) => $user?->is_active === true)->unique('id')->values();
                Notification::send($recipients, new ContractEndingSoon($contract->load('application.candidate')));
                $contract->forceFill(['expiry_reminded_at' => Date::now()])->save();

                Log::notice('contract.expiry_reminded', ['contract_id' => $contract->id, 'recipients' => $recipients->pluck('id')->all()]);

                return 1;
            });
        }

        return $count;
    }

    /** @return Collection<int, StaffUser> active staff holding contracts.manage (through a role or a grant, minus denials) */
    private function contractManagers(): Collection
    {
        return StaffUser::query()->where('is_active', true)->get()->filter(fn(StaffUser $user) => $user->hasPermission('contracts.manage'))->values();
    }
}
