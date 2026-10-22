<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full application file: the application, the person, and the history.
 *
 * @mixin \App\Modules\Recruitment\Models\Application
 */
class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomination_number' => $this->nomination_number,
            'status' => $this->current_status,
            'status_label' => $this->current_status->labelAr(),
            'job_title' => ['id' => $this->jobTitle->id, 'name_ar' => $this->jobTitle->name_ar],
            'source_office' => ['id' => $this->sourceOffice->id, 'name_ar' => $this->sourceOffice->name_ar],
            'category' => $this->category === null ? null : ['id' => $this->category->id, 'name_ar' => $this->category->name_ar],
            'existing_employee_number' => $this->existing_employee_number,
            'notes' => $this->notes,
            'availability' => [
                'works_for_other_government_entity' => $this->works_for_other_government_entity,
                'can_stay_overnight' => $this->can_stay_overnight,
                'full_time_available' => $this->full_time_available,
                'declared_at' => $this->availability_declared_at,
            ],
            'candidate' => new CandidateResource($this->candidate),
            'history' => StatusEventResource::collection($this->whenLoaded('statusEvents')),
            'created_at' => $this->created_at,
        ];
    }
}
