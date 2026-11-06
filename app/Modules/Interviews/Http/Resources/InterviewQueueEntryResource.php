<?php

namespace App\Modules\Interviews\Http\Resources;

use App\Modules\Recruitment\Http\Resources\ApplicationSummaryResource;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One application waiting to be booked, with its place in the queue.
 *
 * @mixin Application
 *
 * @property int $queue_position
 * @property ?string $waiting_since
 */
class InterviewQueueEntryResource extends ApplicationSummaryResource
{
    public function toArray(Request $request): array
    {
        return [
            /** Its place in the whole queue (1 is next), with the chosen priorities. */
            'position' => (int) $this->queue_position,
            /** When it started waiting for its interview (the exam result, an exception or an accepted excuse). */
            'waiting_since' => $this->waiting_since === null ? null : Carbon::parse($this->waiting_since)->toIso8601String(),
            ...parent::toArray($request),
        ];
    }
}
