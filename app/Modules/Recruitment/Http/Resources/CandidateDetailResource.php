<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;

/**
 * A person with all their applications, newest first (each as in the applications list).
 *
 * @mixin \App\Modules\Recruitment\Models\Candidate
 */
class CandidateDetailResource extends CandidateResource
{
    public const RELATIONS = ['birthPlace', 'deactivator', 'applications.jobPosition', 'applications.nominatingEntity', 'applications.candidate'];

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'applications' => ApplicationSummaryResource::collection($this->applications->sortByDesc('created_at')->values()),
        ];
    }
}
