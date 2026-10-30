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
    /** Everything a row shows: load it with the page, so 25 rows cost a fixed number of queries. */
    public const RELATIONS = [
        'candidate.birthPlace',
        'candidate.deactivator',
        'nominatingEntity',
        'jobPosition',
        'category',
        'academicQualification',
        'specialization',
        'maritalStatus',
        'healthCondition',
        'residenceDistrict',
        'residenceArea',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomination_number' => $this->nomination_number,
            'status' => $this->current_status,
            'status_label' => $this->current_status->labelAr(),
            'nominating_entity' => $this->nominatingEntity->name_ar,
            'job_position' => $this->jobPosition->name_ar,
            'category' => $this->category->name_ar,
            'existing_employee_number' => $this->existing_employee_number,
            'marital_status' => $this->maritalStatus->name_ar,
            'phone_number' => $this->phone_number,
            'education' => [
                'academic_qualification' => $this->academicQualification->name_ar,
                'specialization' => $this->specialization->name_ar,
                'studying_years' => $this->studying_years,
                'studies_discontinued' => $this->studies_discontinued,
                'quran_parts_memorized' => $this->quran_parts_memorized,
            ],
            'availability' => [
                'works_for_other_government_entity' => $this->works_for_other_government_entity,
                'full_commitment' => $this->full_commitment,
                'overnight_work' => $this->overnight_work,
            ],
            'health' => [
                'health_details' => $this->health_details,
                'health_condition' => $this->healthCondition->name_ar,
            ],
            'residence' => [
                'residence_details' => $this->residence_details,
                'residence_district' => $this->residenceDistrict->name_ar,
                'residence_area' => $this->residenceArea?->name_ar,
            ],
            'notes' => $this->notes,
            'candidate' => new CandidateResource($this->candidate),
            'created_at' => $this->created_at,
        ];
    }
}
