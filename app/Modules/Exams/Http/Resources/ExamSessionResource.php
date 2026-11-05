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
    public const RELATIONS = ['plan.jobPosition'];

    public function toArray(Request $request): array
    {
        $now = Date::now();

        return [
            'id' => $this->id,
            'plan' => [
                'id' => $this->plan->id,
                'version' => $this->plan->version,
                'job_position' => ReferenceSummary::of($this->plan->jobPosition),
            ],
            'starts_at' => $this->starts_at->toIso8601String(),
            /** The last moment to start: not started by then is absent. */
            'late_until' => $this->lateUntil()->toIso8601String(),
            /** When every attempt ends (start + duration + extensions for everyone), unless one was given more. */
            'ends_at' => $this->endsAt()->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'late_minutes' => $this->late_minutes,
            'extra_minutes' => $this->extra_minutes,
            /** scheduled, open (candidates may start), running (late window closed, attempts still running) or ended. */
            'stage' => match (true) {
                $now->lessThan($this->starts_at) => 'scheduled',
                $now->lessThan($this->lateUntil()) => 'open',
                $now->lessThan($this->endsAt()) => 'running',
                default => 'ended',
            },
            'location' => $this->location,
            'notes' => $this->notes,
            /** How many questions were drawn (the same for every candidate). */
            'questions_count' => $this->whenCounted('questions'),
            'bookings_count' => $this->whenCounted('bookings'),
            /** Time added for everyone (one session only). */
            'extensions' => $this->whenLoaded('extensions', fn() => $this->extensions->map(fn(ExamSessionExtension $extension) => [
                'minutes' => $extension->minutes,
                'reason' => $extension->reason,
                'by' => $extension->author?->full_name,
                'at' => $extension->created_at?->toIso8601String(),
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
