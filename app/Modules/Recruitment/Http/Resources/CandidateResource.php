<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The person. The national ID is masked and marital/health data are left out unless the viewer
 * holds candidates.view_sensitive.
 *
 * @mixin \App\Modules\Recruitment\Models\Candidate
 */
class CandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canSeeSensitive = (bool) $request->user()?->can('candidates.view_sensitive');

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'mother_name' => $this->mother_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
            'national_id' => $canSeeSensitive ? $this->national_id : $this->maskedNationalId(),
            'national_id_is_masked' => ! $canSeeSensitive,
            'phone' => $this->phone,
            'governorate' => $this->whenLoaded('governorate', fn() => $this->governorate === null ? null : [
                'id' => $this->governorate->id,
                'name_ar' => $this->governorate->name_ar,
            ]),
            'residence_details' => $this->residence_details,
            'education_level' => $this->education_level,
            'specialization' => $this->specialization,
            'study_years' => $this->study_years,
            'quran_parts_memorized' => $this->quran_parts_memorized,
            'sensitive' => $this->when($canSeeSensitive && $this->relationLoaded('sensitiveDetails'), fn() => $this->sensitiveDetails === null ? null : [
                'marital_status' => $this->sensitiveDetails->marital_status,
                'health_status' => $this->sensitiveDetails->health_status,
                'health_details' => $this->sensitiveDetails->health_details,
            ]),
        ];
    }
}
