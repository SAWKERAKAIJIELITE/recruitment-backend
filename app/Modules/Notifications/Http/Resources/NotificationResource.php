<?php

namespace App\Modules\Notifications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (array) $this->data;

        return [
            'id' => $this->id,
            /** What it is about: contract_ending_soon, applications_moved. */
            'kind' => (string) ($data['kind'] ?? 'general'),
            'title' => (string) ($data['title'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            /**
             * The details of its kind. contract_ending_soon: contract_id, application_id, nomination_number,
             * candidate_name, reference_number, ends_on, days_left. applications_moved: from_status, to_status,
             * to_status_label, decision_type, count, applications (the first 50: id, nomination_number,
             * candidate_name), actor (id, full_name, or null).
             */
            'details' => $data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
