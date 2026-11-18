<?php

namespace App\Modules\Exams\Support;

use App\Modules\Exams\Models\ExamSession;
use Illuminate\Validation\ValidationException;

/**
 * A session taken out to a hall (D-102) is frozen on the server: the laptop carries a copy, and a change here would
 * no longer match it. Every action that changes a session or its bookings asks this first; the database refuses the
 * same writes (exam_hall_frozen), so a route that forgets cannot get through either.
 */
final class HallGuard
{
    public static function assertNotOut(ExamSession $session, string $field = 'session'): void
    {
        if ($session->isOut()) {
            throw ValidationException::withMessages([$field => [__('exams.hall.out')]]);
        }
    }
}
