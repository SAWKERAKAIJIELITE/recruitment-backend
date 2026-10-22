<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Identity\Models\Role */
class RoleResource extends JsonResource
{
    public static $wrap = 'role';

    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label_ar' => $this->label_ar,
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
        ];
    }
}
