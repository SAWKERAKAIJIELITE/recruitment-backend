<?php

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Http\Resources\StaffUserAccessResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuditEvent */
class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** e.g. interview.recorded, access.role_assigned. */
            'event' => $this->event,
            /** operations, or security (access, devices, logins: audit.view only). */
            'category' => $this->category,
            /** The staff user who acted; null for the system (sweeps, reminders) or a candidate. */
            'actor' => $this->actor === null ?
                null :
                new StaffUserAccessResource($this->whenLoaded('actor')),
            // [
            //     'id' => $this->actor->id,
            //     'personal_number' => $this->personal_number,
            //     'username' => $this->actor->username,
            //     'full_name' => $this->actor->full_name,
            //     'is_active' => $this->actor->is_active,
            // ],
            /** A candidate's own action (exam login, start). */
            'candidate_id' => $this->candidate_id,
            /** What the action was about: the records' ids and the values that changed. */
            'context' => (object) $this->context,
            'ip' => $this->ip,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
