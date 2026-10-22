<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the staff list.
 *
 * @mixin \App\Modules\Identity\Models\StaffUser
 */
class StaffUserSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'personal_number' => $this->personal_number,
            'username' => $this->username,
            'full_name' => $this->full_name,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'roles' => $this->roles->pluck('slug')->values(),
        ];
    }
}
