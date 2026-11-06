<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Everything about one staff user's access: roles, personal overrides, and the resulting permissions.
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
            'roles' => $this->roles->pluck('slug')->values(),
            /**
             * Each role with how it came: `automatic` when the system added it because every permission of the role
             * is granted directly (the grants stay direct; `note` says so), otherwise given by hand.
             */
            'role_assignments' => $this->roles->map(fn ($role) => [
                'role' => $role->slug,
                'label' => $role->label_ar,
                'automatic' => (bool) $role->pivot->automatic,
                'note' => $role->pivot->note,
                'assigned_at' => $role->pivot->created_at === null ? null : Carbon::parse($role->pivot->created_at)->toIso8601String(),
            ])->values(),
            /** Personal grants and denials; `note` when the system acted on them (a role removed because all its permissions are denied). */
            'overrides' => $this->permissions->map(fn ($permission) => [
                'permission' => $permission->slug,
                'effect' => $permission->pivot->is_granted ? 'grant' : 'deny',
                'note' => $permission->pivot->note,
            ])->values(),
            'effective_permissions' => $this->permissionSlugs(),
        ];
    }
}
