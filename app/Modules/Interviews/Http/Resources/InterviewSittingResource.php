<?php

namespace App\Modules\Interviews\Http\Resources;

use App\Modules\Interviews\Models\InterviewSitting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InterviewSitting */
class InterviewSittingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** committee (many candidates) or one_off (an appointment of one). */
            'kind' => $this->kind->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'location' => $this->location,
            'committee_name' => $this->committee_name,
            'notes' => $this->notes,
            'members' => InterviewMemberResource::collection($this->whenLoaded('members')),
            /** How many interviews are booked into it. */
            'interviews_count' => $this->whenCounted('interviews'),
            /** Its interviews, on one sitting only. */
            'interviews' => InterviewResource::collection($this->whenLoaded('interviews')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
