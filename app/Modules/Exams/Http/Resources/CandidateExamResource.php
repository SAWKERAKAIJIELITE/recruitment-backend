<?php

namespace App\Modules\Exams\Http\Resources;

use App\Modules\Exams\Models\ExamBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;

/**
 * What a candidate sees of their exam: when, where, how long, the server's time and deadline, and (once submitted)
 * the receipt. Never a result or an answer key.
 *
 * @mixin ExamBooking
 */
class CandidateExamResource extends JsonResource
{
    public static $wrap = 'exam';

    public function toArray(Request $request): array
    {
        $session = $this->session;
        $attempt = $this->attempt;
        $now = Date::now();

        return [
            'booking_id' => $this->id,
            'candidate' => ['full_name' => $this->application->candidate->full_name, 'nomination_number' => $this->application->nomination_number],
            'job_position' => $this->application->jobPosition->name_ar,
            'location' => $session->location,
            'starts_at' => $session->starts_at->toIso8601String(),
            /** Start by then, or be absent. */
            'late_until' => $session->lateUntil()->toIso8601String(),
            'duration_minutes' => $session->duration_minutes,
            /** The time on the server: the timer shown is a help only, the server decides. */
            'server_time' => $now->toIso8601String(),
            /** booked (not started), attended (started), absent or cancelled. */
            'status' => $this->status->value,
            'can_start' => $attempt === null && $this->status->value === 'booked' && $now->greaterThanOrEqualTo($session->starts_at) && $now->lessThan($session->lateUntil()),
            'attempt' => $attempt === null ? null : [
                'status' => $attempt->status->value,
                'started_at' => $attempt->started_at->toIso8601String(),
                'deadline_at' => $attempt->deadline_at->toIso8601String(),
                'seconds_left' => $attempt->isOpen() ? max(0, $now->diffInSeconds($attempt->deadline_at, false)) : 0,
                'questions' => (int) $attempt->items()->count(),
                'answered' => (int) $attempt->items()->whereNotNull('selected_option')->count(),
                /** The receipt: when and how it was closed (candidate or timeout). */
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'closed_by' => $attempt->closed_by?->value,
            ],
        ];
    }
}
