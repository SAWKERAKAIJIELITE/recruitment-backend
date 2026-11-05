<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamAttemptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A question as the candidate sees it, at its place in their exam: the prompt, the options in their written order
 * and the answer saved last. Never the correct option.
 *
 * @mixin ExamAttemptItem
 */
class CandidateQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'prompt' => $this->question->version->prompt,
            'options' => $this->question->version->options,
            'selected_option' => $this->selected_option,
            'answered_at' => $this->answered_at?->toIso8601String(),
        ];
    }
}
