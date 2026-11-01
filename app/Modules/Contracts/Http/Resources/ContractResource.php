<?php

namespace App\Modules\Contracts\Http\Resources;

use App\Modules\Contracts\Models\Contract;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contract */
class ContractResource extends JsonResource
{
    /** What a row shows; load it with the page. */
    public const RELATIONS = ['application.candidate', 'application.jobPosition', 'application.nominatingEntity', 'type', 'renewal', 'document', 'creator', 'ender'];

    public function toArray(Request $request): array
    {
        $person = fn($user) => $user === null ? null : ['id' => $user->id, 'username' => $user->username, 'full_name' => $user->full_name];
        $state = $this->state();

        return [
            'id' => $this->id,
            'application' => $this->whenLoaded('application', fn() => [
                'id' => $this->application->id,
                'nomination_number' => $this->application->nomination_number,
                'status' => $this->application->current_status->value,
                'candidate' => ['id' => $this->application->candidate->id, 'full_name' => $this->application->candidate->full_name, 'national_number' => $this->application->candidate->national_number],
                'job_position' => ReferenceSummary::of($this->application->jobPosition),
                'nominating_entity' => ReferenceSummary::of($this->application->nominatingEntity),
            ]),
            /** 1: the first contract; 2, 3, ...: renewals. */
            'sequence' => $this->sequence,
            'renews_contract_id' => $this->renews_contract_id,
            /** The contract that renews this one, if any. */
            'renewed_by_contract_id' => $this->renewal?->id,
            'type' => ReferenceSummary::of($this->type),
            'reference_number' => $this->reference_number,
            'written_on' => $this->written_on->toDateString(),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            /** upcoming, active, ending_soon, expired (end passed, not renewed, not ended), renewed, ended. */
            'state' => ['value' => $state->value, 'label' => $state->labelAr()],
            /** Days until the end date (negative once passed); null without an end date. */
            'days_left' => $this->daysLeft(),
            'document' => $this->document === null ? null : ['id' => $this->document->id, 'original_filename' => $this->document->original_filename],
            'notes' => $this->notes,
            'ended' => $this->ended_on === null ? null : ['on' => $this->ended_on->toDateString(), 'reason' => $this->end_reason, 'by' => $person($this->ender), 'at' => $this->ended_at?->toIso8601String()],
            'expiry_reminded_at' => $this->expiry_reminded_at?->toIso8601String(),
            'created_by' => $person($this->creator),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
