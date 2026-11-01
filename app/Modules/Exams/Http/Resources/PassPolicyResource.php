<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\PassPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PassPolicy */
class PassPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'subject_pass_mark' => (string) $this->subject_pass_mark,
            'tolerance_floor' => (string) $this->tolerance_floor,
            'max_tolerated_subjects' => $this->max_tolerated_subjects,
            'average_must_exceed' => (string) $this->average_must_exceed,
            'description' => $this->description,
            /** The newest version: what the next published plan uses. */
            'is_current' => (bool) $this->is_current,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
