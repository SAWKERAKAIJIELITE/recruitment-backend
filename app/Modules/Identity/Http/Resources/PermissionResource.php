<?php

namespace App\Modules\Identity\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Identity\Models\Permission */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label_ar' => $this->label_ar,
            'individual_only' => $this->individual_only,
        ];
    }
}
