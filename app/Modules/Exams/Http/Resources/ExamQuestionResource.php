<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamQuestion;
use App\Modules\Exams\Models\ExamQuestionVersion;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A question for the staff who manage the bank: it includes the correct answer, which never reaches a candidate.
 *
 * @mixin ExamQuestion
 */
class ExamQuestionResource extends JsonResource
{
    public const RELATIONS = ['subject', 'part', 'currentVersion'];

    public function toArray(Request $request): array
    {
        $version = fn(ExamQuestionVersion $version) => [
            'version' => $version->version,
            'prompt' => $version->prompt,
            'options' => $version->options,
            'correct_option' => $version->correct_option,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at?->toIso8601String(),
        ];

        return [
            'id' => $this->id,
            'subject' => ReferenceSummary::of($this->subject),
            'part' => ReferenceSummary::of($this->part),
            'difficulty' => $this->difficulty->value,
            'archived' => $this->trashed(),
            'current' => $version($this->currentVersion),
            /** Every version, oldest first (one question only). */
            'versions' => $this->whenLoaded('versions', fn() => $this->versions->map($version)->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
