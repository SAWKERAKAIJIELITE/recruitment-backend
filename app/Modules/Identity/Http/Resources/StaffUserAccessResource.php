<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything about one staff user's access: roles, personal overrides, and the resulting permissions.
 *
 * @mixin \App\Modules\Identity\Models\StaffUser
 */
class StaffUserAccessResource extends JsonResource
{
    public static $wrap = 'user';

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
            'overrides' => $this->permissions->map(fn($permission) => [
                'permission' => $permission->slug,
                'effect' => $permission->pivot->is_granted ? 'grant' : 'deny',
            ])->values(),
            'effective_permissions' => $this->permissionSlugs(),
        ];
    }
}
