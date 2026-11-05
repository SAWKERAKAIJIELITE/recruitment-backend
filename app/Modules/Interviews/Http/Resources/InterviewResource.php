<?php

namespace App\Modules\Interviews\Http\Resources;

use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Models\Interview;
use App\Modules\Interviews\Models\InterviewReschedule;
use App\Modules\Interviews\Models\InterviewResultRevision;
use App\Modules\Interviews\Models\InterviewSitting;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Interview */
class InterviewResource extends JsonResource
{
    /** What a list row shows; load it with the page. */
    public const RELATIONS = ['application.candidate', 'application.jobPosition', 'application.nominatingEntity', 'sitting.members', 'recorder', 'canceller'];

    /** The same, inside one sitting: the sitting is shown once, above its interviews. */
    public const IN_SITTING = ['application.candidate', 'application.jobPosition', 'application.nominatingEntity', 'recorder', 'canceller'];

    /** What one interview shows besides: its reschedules and every version of its result. */
    public const DETAILS = ['reschedules.author', 'reschedules.fromSitting', 'reschedules.toSitting', 'revisions.author'];

    public function toArray(Request $request): array
    {
        $person = fn($user) => $user === null ? null : ['id' => $user->id, 'username' => $user->username, 'full_name' => $user->full_name];
        $grade = fn(?InterviewGrade $grade) => $grade === null ? null : ['value' => $grade->value, 'label' => $grade->labelAr()];
        $sitting = fn(InterviewSitting $sitting) => ['id' => $sitting->id, 'kind' => $sitting->kind->value, 'starts_at' => $sitting->starts_at->toIso8601String(), 'location' => $sitting->location, 'committee_name' => $sitting->committee_name];

        return [
            'id' => $this->id,
            'application' => $this->whenLoaded('application', fn() => [
                'id' => $this->application->id,
                'nomination_number' => $this->application->nomination_number,
                'status' => $this->application->current_status->value,
                'candidate' => [
                    'id' => $this->application->candidate->id,
                    'full_name' => $this->application->candidate->full_name,
                    'national_number' => $this->application->candidate->national_number
                ],
                'job_position' => ReferenceSummary::of($this->application->jobPosition),
                'nominating_entity' => ReferenceSummary::of($this->application->nominatingEntity),
            ]),
            'round' => $this->round,
            'status' => $this->status->value,
            'status_label' => $this->status->labelAr(),
            /**
             * Where and before whom: a committee sitting, or a one-off sitting with a single interviewer (kind
             * one_off). Left out inside a sitting (`GET /interview-sittings/{id}`), like the three fields below.
             */
            'sitting' => $this->whenLoaded('sitting', fn() => $sitting($this->sitting)),
            'sitting_id' => $this->sitting_id,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            /** The sitting's place and committee name, repeated for convenience. */
            'location' => $this->whenLoaded('sitting', fn() => $this->sitting->location),
            'committee_name' => $this->whenLoaded('sitting', fn() => $this->sitting->committee_name),
            /** The sitting's committee. */
            'members' => $this->whenLoaded('sitting', fn() => InterviewMemberResource::collection($this->sitting->members)),
            'result' => $this->result === null ? null : ['value' => $this->result->value, 'label' => $this->result->labelAr()],
            'held_at' => $this->held_at?->toIso8601String(),
            'grades' => [
                'scientific' => $grade($this->scientific_grade),
                'general' => $grade($this->general_grade),
                'overall' => $grade($this->overall_grade),
            ],
            'committee_summary' => $this->committee_summary,
            'notes' => $this->notes,
            'recorded_by' => $person($this->recorder),
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            'cancelled' => $this->cancelled_at === null ? null : ['at' => $this->cancelled_at->toIso8601String(), 'by' => $person($this->canceller), 'reason' => $this->cancel_reason],
            /** Every change of appointment (one interview only). */
            'reschedules' => $this->whenLoaded(
                'reschedules',
                fn() => $this->reschedules->map(fn(InterviewReschedule $row) => [
                    'from' => ['sitting' => $sitting($row->fromSitting), 'scheduled_at' => $row->from_scheduled_at->toIso8601String()],
                    'to' => ['sitting' => $sitting($row->toSitting), 'scheduled_at' => $row->to_scheduled_at->toIso8601String()],
                    'reason' => $row->reason,
                    'by' => $person($row->author),
                    'at' => $row->created_at?->toIso8601String(),
                ])->values()->all()
            ),
            /** Every version of the result, 1 as recorded then corrections (one interview only). */
            'revisions' => $this->whenLoaded('revisions', fn() => $this->revisions->map(fn(InterviewResultRevision $row) => [
                'revision' => $row->revision,
                'result' => $row->result->value,
                'held_at' => $row->held_at->toIso8601String(),
                'grades' => ['scientific' => $row->scientific_grade?->value, 'general' => $row->general_grade?->value, 'overall' => $row->overall_grade?->value],
                'committee_summary' => $row->committee_summary,
                'notes' => $row->notes,
                'reason' => $row->reason,
                'by' => $person($row->author),
                'at' => $row->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
