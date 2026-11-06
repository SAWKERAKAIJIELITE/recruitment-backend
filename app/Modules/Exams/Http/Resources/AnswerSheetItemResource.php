<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamAnswerRevision;
use App\Modules\Exams\Models\ExamAttemptItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question of a candidate's answer sheet, at the place the candidate saw it, with the answer key (exam_bank.manage
 * only).
 *
 * @mixin ExamAttemptItem
 */
class AnswerSheetItemResource extends JsonResource
{
    public const RELATIONS = ['question.version', 'question.planSubject.subject', 'question.planPart.part', 'revisions'];

    public function toArray(Request $request): array
    {
        $version = $this->question->version;

        return [
            'position' => $this->position,
            'subject' => $this->question->planSubject->subject->name_ar,
            'part' => $this->question->planPart?->part->name_ar,
            'question_id' => $version->question_id,
            'version' => $version->version,
            'prompt' => $version->prompt,
            'options' => $version->options,
            'correct_option' => $version->correct_option,
            /** The answer saved last (null: not answered). */
            'selected_option' => $this->selected_option,
            /** Set when the attempt closed. */
            'is_correct' => $this->is_correct,
            'answered_at' => $this->answered_at?->toIso8601String(),
            /** Every save, in order (null: the answer was cleared). */
            'history' => $this->revisions->map(fn (ExamAnswerRevision $revision) => ['option' => $revision->selected_option, 'at' => $revision->saved_at->toIso8601String(), 'ip' => $revision->ip])->values()->all(),
        ];
    }
}
