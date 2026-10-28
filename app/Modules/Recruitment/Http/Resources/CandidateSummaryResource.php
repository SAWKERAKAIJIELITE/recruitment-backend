<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the people list.
 *
 * @mixin \App\Modules\Recruitment\Models\Candidate
 */
class CandidateSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'national_number' => $this->national_number,
            'full_name' => $this->full_name,
            'mother_name' => $this->mother_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => ReferenceSummary::of($this->birthPlace),
            'is_active' => $this->is_active,
            'applications_count' => (int) $this->applications_count,
            // The application that blocks a new nomination, if any.
            'live_application' => $this->liveApplication === null ? null : [
                'id' => $this->liveApplication->id,
                'nomination_number' => $this->liveApplication->nomination_number,
                'status' => $this->liveApplication->current_status,
                'status_label' => $this->liveApplication->current_status->labelAr(),
            ],
        ];
    }
}
