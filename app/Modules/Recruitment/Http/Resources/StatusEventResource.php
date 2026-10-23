<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One event of an application's history. from_status is the previous event's status (null for the first).
 *
 * @mixin \App\Modules\Recruitment\Models\ApplicationStatusEvent
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
            'actor' => $this->whenLoaded('actor', fn() => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'username' => $this->actor->username,
                'full_name' => $this->actor->full_name,
            ]),
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'occurred_at' => $this->occurred_at,
        ];
    }
}
