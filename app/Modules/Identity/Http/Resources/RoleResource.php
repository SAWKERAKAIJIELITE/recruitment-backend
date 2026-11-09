<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public static $wrap = 'role';

    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label_ar' => $this->label_ar,
            /** True for the four offices (each controls its own section only). */
            'office' => $this->kind()->isOffice(),
            /** The system administrator: every permission, always (not editable). */
            'administrator' => $this->isAdministrator(),
            'permissions' => PermissionResource::collection($this->when(
                $this->isAdministrator() || $this->relationLoaded('permissions'),
                fn() => $this->isAdministrator() ? Permission::query()->orderBy('slug')->get() : $this->permissions->sortBy('slug')->values(),
            )),
        ];
    }
}
