<?php

namespace App\Modules\Exams\Queries;

use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Queries\ApplicationSearch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who waits to be booked into an exam session: applications awaiting their exam with no booking waiting or
 * undecided, longest wait first (nominated, or back after an accepted excuse), then by nomination number. Takes the
 * filters of the applications list (a session's job position: `job_position_id`).
 */
final class ExamQueue
{
    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return array_diff_key(ApplicationSearch::rules(), array_flip(['status', 'status.*', 'live', 'sort', 'direction']));
    }

    public static function query(array $filters): Builder
    {
        $query = Application::query()
            ->select('applications.*')
            ->selectSub(fn($sub) => $sub->from('application_status_events as e')
                ->selectRaw('max(e.occurred_at)')
                ->whereColumn('e.application_id', 'applications.id')
                ->where('e.to_status', ApplicationStatus::AwaitingExam->value), 'waiting_since')
            ->where('applications.current_status', ApplicationStatus::AwaitingExam)
            ->whereNotExists(fn($booking) => $booking->from('exam_bookings')
                ->whereColumn('exam_bookings.application_id', 'applications.id')
                ->whereIn('exam_bookings.status', ['booked', 'attended'])
                ->whereNull('exam_bookings.decided_at'));

        ApplicationSearch::filter($query, $filters);

        return $query->orderBy('waiting_since')->orderBy('applications.nomination_number');
    }
}
