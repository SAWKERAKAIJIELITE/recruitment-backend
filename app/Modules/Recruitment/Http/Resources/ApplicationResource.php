<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full application file: the application, the person, and the history in order.
 *
 * @mixin \App\Modules\Recruitment\Models\Application
 */
class ApplicationResource extends JsonResource
{
    /** The relations the file shows; load them before building the resource. */
    public const RELATIONS = [
        'candidate.birthPlace',
        'nominatingEntity',
        'jobPosition',
        'category',
        'academicQualification',
        'specialization',
        'maritalStatus',
        'healthCondition',
        'residenceDistrict',
        'residenceArea',
        'statusEvents.actor',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomination_number' => $this->nomination_number,
            'status' => $this->current_status,
            'status_label' => $this->current_status->labelAr(),
            'nominating_entity' => ReferenceSummary::of($this->nominatingEntity),
            'job_position' => ReferenceSummary::of($this->jobPosition),
            'category' => ReferenceSummary::of($this->category),
            'existing_employee_number' => $this->existing_employee_number,
            'education' => [
                'academic_qualification' => ReferenceSummary::of($this->academicQualification),
                'specialization' => ReferenceSummary::of($this->specialization),
                'studying_years' => $this->studying_years,
                'studies_discontinued' => $this->studies_discontinued,
                'quran_parts_memorized' => $this->quran_parts_memorized,
            ],
            'marital_status' => ReferenceSummary::of($this->maritalStatus),
            'health' => [
                'condition' => ReferenceSummary::of($this->healthCondition),
                'details' => $this->health_details,
            ],
            'residence' => [
                'district' => ReferenceSummary::of($this->residenceDistrict),
                'area' => ReferenceSummary::of($this->residenceArea),
                'details' => $this->residence_details,
            ],
            'phone_number' => $this->phone_number,
            'availability' => [
                'works_for_other_government_entity' => $this->works_for_other_government_entity,
                'full_commitment' => $this->full_commitment,
                'overnight_work' => $this->overnight_work,
            ],
            'notes' => $this->notes,
            'candidate' => new CandidateResource($this->candidate),
            'history' => StatusEventResource::collection($this->history()),
            'created_at' => $this->created_at,
        ];
    }
}
