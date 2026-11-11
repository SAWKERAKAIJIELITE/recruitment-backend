<?php

namespace App\Modules\Recruitment\Http\Resources;

use App\Modules\Recruitment\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Who the candidate is and where the application stands, nothing more (D-086): what the offices other than
 * Recruitment see of an application (the queues, the applications list without applications.view). No phone,
 * residence, health, education or notes: Recruitment is the only office that talks to the candidates.
 *
 * @mixin Application
 */
class ApplicationIdentityResource extends JsonResource
{
    public const RELATIONS = ['candidate', 'jobPosition'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomination_number' => $this->nomination_number,
            'status' => $this->current_status,
            'status_label' => $this->current_status->labelAr(),
            'job_position' => ReferenceSummary::of($this->jobPosition),
            'candidate' => [
                'id' => $this->candidate->id,
                'full_name' => $this->candidate->full_name,
                'national_number' => $this->candidate->national_number,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
