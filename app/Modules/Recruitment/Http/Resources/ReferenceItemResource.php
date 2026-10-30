<?php

namespace App\Modules\Recruitment\Http\Resources;

use App\Modules\Recruitment\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of a managed list. All list models share this shape (areas add district_id and district); Scramble needs
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
            // Areas only: the district id, and the district itself when it was loaded with the list (no query per area).
            'district_id' => $this->when($this->resource instanceof Area, fn() => $this->district_id),
            'district' => $this->when($this->resource instanceof Area, fn() => $this->whenLoaded('district', fn() => ReferenceSummary::of($this->district))),
            'archived_at' => $this->deleted_at,
        ];
    }
}
