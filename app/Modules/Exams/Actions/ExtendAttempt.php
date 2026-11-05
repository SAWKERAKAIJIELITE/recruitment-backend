<?php

namespace App\Modules\Exams\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Exams\Models\ExamAttempt;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * More time for one attempt (analysis doc §06, §07: "duration, reason, attempt"; individual permission
 * attempts.extend): a latecomer, a power cut at one desk. Only while it is running: a closed attempt is never
 * reopened. The previous and new deadline, who and why are kept.
 */
class ExtendAttempt
{
    public function extend(ExamAttempt $attempt, int $minutes, string $reason, StaffUser $actor): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $minutes, $reason, $actor) {
            $attempt = ExamAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if (! $attempt->isOpen() || $attempt->hasExpired()) {
                throw ValidationException::withMessages(['attempt' => [__('exams.attempts.not_running')]]);
            }

            self::move($attempt, $minutes, $reason, $actor, null);
            $attempt->forceFill(['extra_minutes' => $attempt->extra_minutes + $minutes])->save();

            AuditTrail::record('exam_attempt.extended', $actor, ['attempt_id' => $attempt->id, 'minutes' => $minutes, 'reason' => trim($reason), 'deadline' => $attempt->deadline_at->toIso8601String()]);

            return $attempt;
        });
    }

    /** Moves a locked, running attempt's deadline and records it (for one attempt, or its share of a session's extension). */
    public static function move(ExamAttempt $attempt, int $minutes, string $reason, StaffUser $actor, ?int $sessionExtensionId): void
    {
        $previous = $attempt->deadline_at->copy();
        $attempt->forceFill(['deadline_at' => $previous->copy()->addMinutes($minutes)])->save();

        $attempt->extensions()->forceCreate([
            'session_extension_id' => $sessionExtensionId,
            'minutes' => $minutes,
            'reason' => trim($reason),
            'previous_deadline' => $previous,
            'new_deadline' => $attempt->deadline_at,
            'extended_by' => $actor->id,
            'created_at' => Date::now(),
        ]);
    }
}
