<?php

namespace App\Modules\Recruitment\Http\Resources;

use App\Modules\Recruitment\Models\ApplicationStatusEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One event of an application's history. from_status is the previous event's status (null for the first).
 *
 * @mixin ApplicationStatusEvent
 */
class StatusEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->fromStatus(),
            'to_status' => $this->to_status,
            'to_status_label' => $this->to_status->labelAr(),
            'decision_type' => $this->decision_type,
            'reason' => $this->reason,
            /** رقم القرار of an exception or an accepted excuse (D-100). */
            'decision_number' => $this->decision_number,
            /** The record behind the move (D-038, D-100): an exam booking (its round: an exam result, an exception, an accepted excuse), an interview, a contract. */
            'exam_booking_id' => $this->getAttribute('exam_booking_id'),
            'interview_id' => $this->getAttribute('interview_id'),
            'contract_id' => $this->getAttribute('contract_id'),
            'actor' => $this->whenLoaded('actor', fn() => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'username' => $this->actor->username,
                'full_name' => $this->actor->full_name,
            ]),
            'occurred_at' => $this->occurred_at,
        ];
    }
}
