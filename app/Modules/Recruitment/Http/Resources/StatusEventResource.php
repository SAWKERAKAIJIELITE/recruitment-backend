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
            'decision_type_label' => $this->decision_type->labelAr(),
            'reason' => $this->reason,
            /** A failure by decision (D-104): cheating or other; null for any other decision. */
            'reason_type' => $this->reason_type?->value,
            'reason_type_label' => $this->reason_type?->labelAr(),
            /** رقم القرار of an exception, an accepted excuse or a failure by decision (D-100, D-104). */
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
