<?php

namespace App\Modules\Recruitment\Http\Resources;

use App\Modules\Recruitment\Models\Candidate;
use Illuminate\Http\Request;

/**
 * A person with all their applications, newest first (each as in the applications list).
 *
 * @mixin Candidate
 */
class CandidateDetailResource extends CandidateResource
{
    /** Everything the person and each application row show, loaded with it (no query per application). */
    public const RELATIONS = ['birthPlace', 'deactivator', 'applications.category', 'applications.jobPosition', 'applications.nominatingEntity',
        'applications.academicQualification', 'applications.specialization', 'applications.maritalStatus', 'applications.healthCondition',
        'applications.residenceDistrict', 'applications.residenceArea', 'applications.candidate.birthPlace', 'applications.candidate.deactivator'];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'applications' => ApplicationSummaryResource::collection($this->applications->sortByDesc('created_at')->values()),
        ];
    }
}
