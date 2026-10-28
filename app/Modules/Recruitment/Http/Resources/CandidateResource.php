<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The person: the facts that do not change between applications.
 *
 * @mixin \App\Modules\Recruitment\Models\Candidate
 */
class CandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'national_number' => $this->national_number,
            'full_name' => $this->full_name,
            'mother_name' => $this->mother_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->whenLoaded('birthPlace', fn() => ReferenceSummary::of($this->birthPlace)),
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'deactivation' => $this->is_active ? null : [
                'at' => $this->deactivated_at,
                'reason' => $this->deactivation_reason,
                'by' => $this->whenLoaded('deactivator', fn() => $this->deactivator === null ? null : [
                    'id' => $this->deactivator->id,
                    'username' => $this->deactivator->username,
                    'full_name' => $this->deactivator->full_name,
                ]),
            ],
        ];
    }
}
