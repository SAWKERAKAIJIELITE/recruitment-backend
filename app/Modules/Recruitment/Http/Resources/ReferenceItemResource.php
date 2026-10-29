<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a managed list. All list models share this shape (areas add district_id); Scramble needs
 * one of them named to read the column types.
 *
 * @mixin \App\Modules\Recruitment\Models\Area
 */
class ReferenceItemResource extends JsonResource
{
    public static $wrap = 'item';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'district_id' => $this->when(array_key_exists('district_id', $this->resource->getAttributes()), fn() => static::make($this->district()->first())),
            'archived_at' => $this->deleted_at,
        ];
    }
}
