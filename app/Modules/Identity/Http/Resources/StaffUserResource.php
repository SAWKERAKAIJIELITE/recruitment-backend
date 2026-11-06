<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffUser */
class StaffUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 'type' => $this->type,
            'username' => $this->username,
            'full_name' => $this->full_name,
            'roles' => $this->roles->pluck('slug')->values(),
            // For showing or hiding UI only. The server re-checks every request.
            'permissions' => $this->permissionSlugs(),
        ];
    }
}
