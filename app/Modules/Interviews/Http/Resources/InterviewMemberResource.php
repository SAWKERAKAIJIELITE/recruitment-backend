<?php

namespace App\Modules\Interviews\Http\Resources;

use App\Modules\Interviews\Models\InterviewMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InterviewMember */
class InterviewMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            /** chair, member or secretary, with its Arabic label. */
            'capacity' => ['value' => $this->capacity->value, 'label' => $this->capacity->labelAr()],
            'staff_user_id' => $this->staff_user_id,
        ];
    }
}
