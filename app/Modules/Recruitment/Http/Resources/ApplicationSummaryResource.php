<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the applications list.
 *
 * @mixin \App\Modules\Recruitment\Models\Application
 */
class ApplicationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomination_number' => $this->nomination_number,
            'status' => $this->current_status,
            'status_label' => $this->current_status->labelAr(),
            'candidate_name' => $this->candidate->full_name,
            'national_number' => $this->candidate->national_number,
            'job_position' => $this->jobPosition->name_ar,
            'nominating_entity' => $this->nominatingEntity->name_ar,
            'created_at' => $this->created_at,
        ];
    }
}
