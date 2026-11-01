<?php

namespace App\Modules\Contracts\Notifications;

use App\Modules\Contracts\Models\Contract;
use Illuminate\Notifications\Notification;

/** In the system (database channel): a contract ends within the reminder window and is not renewed (D-065). */
class ContractEndingSoon extends Notification
{
    public function __construct(private readonly Contract $contract)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $contract = $this->contract;
        $application = $contract->application;
        $values = [
            'name' => $application->candidate->full_name,
            'nomination' => $application->nomination_number,
            'reference' => $contract->reference_number,
            'date' => $contract->ends_on->toDateString(),
            'days' => $contract->daysLeft(),
        ];

        return [
            'kind' => 'contract_ending_soon',
            'title' => __('contracts.ending_soon_title', [], 'ar'),
            'message' => __('contracts.ending_soon_message', $values, 'ar'),
            'contract_id' => $contract->id,
            'application_id' => $application->id,
            'nomination_number' => $application->nomination_number,
            'candidate_name' => $application->candidate->full_name,
            'reference_number' => $contract->reference_number,
            'ends_on' => $contract->ends_on->toDateString(),
            'days_left' => $contract->daysLeft(),
        ];
    }
}
