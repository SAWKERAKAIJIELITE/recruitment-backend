<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamAttemptExtension;
use App\Modules\Exams\Models\ExamBooking;
use App\Modules\Exams\Models\ExamManualMark;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking into an exam session: the candidate, the attempt (times, answered questions, right answers once closed,
 * extensions), the hand marks and the result.
 *
 * @mixin ExamBooking
 */
class ExamBookingResource extends JsonResource
{
    public const RELATIONS = [
        'application.candidate', 'application.jobPosition', 'application.nominatingEntity', 'canceller',
        'attempt.result', 'attempt.extensions.author', 'attempt.marks.author', 'attempt.marks.part.part',
    ];

    /** RELATIONS with the attempt's counts in the same queries (a session's list is refreshed live). */
    public static function relations(): array
    {
        return [
            ...array_values(array_diff(self::RELATIONS, ['attempt.result'])),
            'attempt' => fn($attempt) => $attempt
                ->withCount(['items as questions_count', 'items as answered_count' => fn($items) => $items->whereNotNull('selected_option'), 'items as correct_count' => fn($items) => $items->where('is_correct', true)])
                ->withMax('items as last_saved_at', 'answered_at'),
            'attempt.result',
        ];
    }

    public function toArray(Request $request): array
    {
        $attempt = $this->relationLoaded('attempt') ? $this->attempt : null;

        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'round' => $this->round,
            'status' => $this->status->value,
            'status_label' => $this->status->labelAr(),
            'application' => $this->whenLoaded('application', fn() => [
                'id' => $this->application->id,
                'nomination_number' => $this->application->nomination_number,
                'status' => $this->application->current_status->value,
                'candidate' => ['id' => $this->application->candidate->id, 'full_name' => $this->application->candidate->full_name, 'national_number' => $this->application->candidate->national_number],
                'job_position' => ReferenceSummary::of($this->application->jobPosition),
                'nominating_entity' => ReferenceSummary::of($this->application->nominatingEntity),
            ]),
            /** When the application moved because of it (absence or result). */
            'decided_at' => $this->decided_at?->toIso8601String(),
            'cancelled' => $this->cancelled_at === null ? null : ['at' => $this->cancelled_at->toIso8601String(), 'by' => $this->canceller?->full_name, 'reason' => $this->cancel_reason],
            'attempt' => $attempt === null ? null : [
                'id' => $attempt->id,
                'status' => $attempt->status->value,
                'started_at' => $attempt->started_at->toIso8601String(),
                'deadline_at' => $attempt->deadline_at->toIso8601String(),
                'extra_minutes' => $attempt->extra_minutes,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                /** candidate (submitted) or timeout (closed by the server at the deadline). */
                'closed_by' => $attempt->closed_by?->value,
                'questions' => (int) ($attempt->questions_count ?? $attempt->items()->count()),
                'answered' => (int) ($attempt->answered_count ?? $attempt->items()->whereNotNull('selected_option')->count()),
                /** Right answers, once closed. */
                'correct' => $attempt->isOpen() ? null : (int) ($attempt->correct_count ?? $attempt->items()->where('is_correct', true)->count()),
                /** The candidate's last saved answer: a desk that stopped saving shows here. */
                'last_saved_at' => ($saved = $attempt->getAttribute('last_saved_at') ?? $attempt->items()->max('answered_at')) === null ? null : \Illuminate\Support\Carbon::parse($saved)->toIso8601String(),
                'extensions' => $attempt->extensions->map(fn(ExamAttemptExtension $extension) => [
                    'minutes' => $extension->minutes,
                    'for_everyone' => $extension->session_extension_id !== null,
                    'reason' => $extension->reason,
                    'previous_deadline' => $extension->previous_deadline->toIso8601String(),
                    'new_deadline' => $extension->new_deadline->toIso8601String(),
                    'by' => $extension->author?->full_name,
                    'at' => $extension->created_at?->toIso8601String(),
                ])->values()->all(),
                /** Every hand mark entered, corrections included (the latest revision of a part counts). */
                'marks' => $attempt->marks->map(fn(ExamManualMark $mark) => [
                    'part_id' => $mark->plan_part_id,
                    'part' => $mark->part->part->name_ar,
                    'revision' => $mark->revision,
                    'mark' => (string) $mark->mark,
                    'max' => (string) $mark->part->max_score,
                    'reason' => $mark->reason,
                    'by' => $mark->author?->full_name,
                    'at' => $mark->created_at?->toIso8601String(),
                ])->values()->all(),
                /** The decided result (null while the attempt runs or a hand mark is missing). */
                'result' => $attempt->result === null ? null : self::result($attempt->result),
            ],
        ];
    }

    /** @return array{revision: int, passed: bool, average: string, outcome: array, reason: ?string, at: ?string} */
    public static function result(ExamResult $result): array
    {
        return [
            'revision' => $result->revision,
            'passed' => $result->passed,
            'average' => (string) $result->average,
            /** Subjects (score, band), reasons when failed, tolerated subjects, the pass policy version. */
            'outcome' => $result->outcome,
            'reason' => $result->reason,
            'at' => $result->created_at?->toIso8601String(),
        ];
    }
}
