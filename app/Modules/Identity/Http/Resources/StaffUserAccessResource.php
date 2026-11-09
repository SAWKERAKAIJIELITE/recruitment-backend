<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything about one staff user's access: the role and the permissions it gives (D-085).
 *
 * @mixin StaffUser
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
            /** When, by whom and why the account was deactivated (null while active). */
            'deactivation' => $this->deactivated_at === null ? null : ['at' => $this->deactivated_at->toIso8601String(), 'by' => $this->deactivated_by, 'reason' => $this->deactivation_reason],
            'last_login_at' => $this->last_login_at,
            /** The user's one role (null only for an account set up outside the API). */
            'role' => $this->role === null ? null : ['slug' => $this->role->slug, 'label' => $this->role->label_ar, 'office' => $this->role->kind()->isOffice()],
            'role_assigned_at' => $this->role_assigned_at?->toIso8601String(),
            'effective_permissions' => $this->permissionSlugs(),
        ];
    }
}
