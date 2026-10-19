<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Identity\Models\StaffDevice */
class StaffDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'fingerprint' => $this->displayFingerprint(),
            'reported_mac' => $this->reported_mac,
            'os_info' => $this->os_info,
            'requested_at' => $this->created_at,
            'approved_at' => $this->approved_at,
            'revoked_at' => $this->revoked_at,
            'staff_users' => $this->whenLoaded('staffUsers', fn() => $this->staffUsers->map(fn($user) => [
                'id' => $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name,
            ])->values()),
        ];
    }
}
