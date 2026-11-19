<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamSession;
use App\Modules\Exams\Models\ExamSessionExtension;
use App\Modules\Recruitment\Http\Resources\ReferenceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/**
 * An exam session. Its password is never shown.
 *
 * @mixin ExamSession
 */
class ExamSessionResource extends JsonResource
{
    public const RELATIONS = ['plan.jobPosition', 'hallDownload.downloader', 'hallDownload.device', 'resultsApprover'];

    /** The counts a session shows: its questions, its bookings, and the results waiting for the approval (D-104). */
    public const COUNTS = ['questions', 'bookings', 'bookingsAwaitingApproval as awaiting_approval_count'];

    public function toArray(Request $request): array
    {
        $now = Date::now();
        $hall = $this->hallDownload;

        return [
            'id' => $this->id,
            'plan' => [
                'id' => $this->plan->id,
                'version' => $this->plan->version,
                'job_position' => ReferenceSummary::of($this->plan->jobPosition),
            ],
            'starts_at' => $this->starts_at->toIso8601String(),
            /** The planned start, when a hall began later (D-102): starts_at is then the real one. */
            'planned_starts_at' => $this->planned_starts_at?->toIso8601String(),
            /** The last moment to start: not started by then is absent. */
            'late_until' => $this->lateUntil()->toIso8601String(),
            /** When every attempt ends (start + duration + extensions for everyone), unless one was given more. */
            'ends_at' => $this->endsAt()->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'late_minutes' => $this->late_minutes,
            'extra_minutes' => $this->extra_minutes,
            /**
             * scheduled, open (candidates may start), running (late window closed, attempts still running), ended, or
             * out (taken out to a hall with no internet: see `hall`).
             */
            'stage' => match (true) {
                $hall !== null => 'out',
                $now->lessThan($this->starts_at) => 'scheduled',
                $now->lessThan($this->lateUntil()) => 'open',
                $now->lessThan($this->endsAt()) => 'running',
                default => 'ended',
            },
            /**
             * Taken out to a hall (D-102): by whom, on which laptop, when; overdue when still out
             * `exams.hall_overdue_hours` (48) after it should have ended. null when not out.
             */
            'hall' => $hall === null ? null : [
                'download_id' => $hall->id,
                'by' => ['id' => $hall->downloader->id, 'full_name' => $hall->downloader->full_name],
                'device' => ['id' => $hall->device->id, 'label' => $hall->device->label],
                'downloaded_at' => $hall->downloaded_at->toIso8601String(),
                'overdue' => $now->greaterThan($this->endsAt()->copy()->addHours((int) config('exams.hall_overdue_hours'))),
            ],
            /**
             * The exams office approved the session's results (D-104): when and who. null until then; until then the
             * candidates whose result is decided wait in exam_taken (neither passed nor failed yet).
             */
            'results_approval' => $this->results_approved_at === null ? null : [
                'approved_at' => $this->results_approved_at->toIso8601String(),
                'approved_by' => $this->resultsApprover === null ? null : ['id' => $this->resultsApprover->id, 'full_name' => $this->resultsApprover->full_name],
            ],
            /** Decided results waiting for the approval (the applications in exam_taken because of this session). */
            'awaiting_approval' => $this->whenCounted('awaiting_approval_count', fn() => (int) $this->awaiting_approval_count),
            'location' => $this->location,
            'notes' => $this->notes,
            /** How many questions were drawn (the same for every candidate). */
            'questions_count' => $this->whenCounted('questions'),
            'bookings_count' => $this->whenCounted('bookings'),
            /** Time added for everyone (one session only). */
            'extensions' => $this->whenLoaded('extensions', fn () => $this->extensions->map(fn (ExamSessionExtension $extension) => [
                'minutes' => $extension->minutes,
                'reason' => $extension->reason,
                'by' => $extension->author?->full_name,
                'at' => $extension->created_at?->toIso8601String(),
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
