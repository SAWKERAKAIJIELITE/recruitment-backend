<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamSubject;
use App\Modules\Exams\Models\ExamSubjectPart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExamSubject */
class ExamSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'archived' => $this->trashed(),
            /** Empty: scored as a whole. */
            'parts' => $this->parts->map(fn(ExamSubjectPart $part) => ExamSubjectPartResource::make($part)->resolve($request))->values()->all(),
        ];
    }
}
