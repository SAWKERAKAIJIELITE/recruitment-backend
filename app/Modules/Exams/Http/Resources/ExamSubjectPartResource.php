<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamSubjectPart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExamSubjectPart */
class ExamSubjectPartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_id' => $this->subject_id,
            'name_ar' => $this->name_ar,
            /** Decimal as a string, two places ("60.00"). */
            'default_weight' => (string) $this->default_weight,
            /** exam or manual: how plans assess this part unless they say otherwise. */
            'default_assessment' => $this->default_assessment->value,
            'archived' => $this->trashed(),
        ];
    }
}
