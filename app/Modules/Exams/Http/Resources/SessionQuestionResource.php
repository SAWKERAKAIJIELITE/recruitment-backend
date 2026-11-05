<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamSessionQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A drawn question with its answer (exam_bank.manage only).
 *
 * @mixin ExamSessionQuestion
 */
class SessionQuestionResource extends JsonResource
{
    public const RELATIONS = ['version', 'planSubject.subject', 'planPart.part'];

    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'question_id' => $this->version->question_id,
            'version' => $this->version->version,
            'subject' => $this->planSubject->subject->name_ar,
            'part' => $this->planPart?->part->name_ar,
            'prompt' => $this->version->prompt,
            'options' => $this->version->options,
            'correct_option' => $this->version->correct_option,
        ];
    }
}
