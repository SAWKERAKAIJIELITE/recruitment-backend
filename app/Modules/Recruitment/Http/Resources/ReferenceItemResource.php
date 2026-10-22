<?php

namespace App\Modules\Recruitment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a managed list. All four list models share this shape; Scramble needs one of them
 * named to read the column types.
 *
 * @mixin \App\Modules\Recruitment\Models\SourceOffice
 */
class ReferenceItemResource extends JsonResource
{
    public static $wrap = 'item';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'archived_at' => $this->deleted_at,
        ];
    }
}
