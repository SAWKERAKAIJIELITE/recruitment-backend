<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link of an application's history chain (D-033). Append-only: PostgreSQL rejects UPDATE, DELETE
 * and TRUNCATE, and accepts a new row only if it follows the application's latest event.
 * Nothing is mass assignable: rows are written only by ApplicationLifecycle.
 */
class ApplicationStatusEvent extends Model
{
    use HasUuids;

    const UPDATED_AT = null;   // a history row is never updated

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'to_status' => ApplicationStatus::class,
            'decision_type' => DecisionType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_event_id');
    }

    /** The status before this event: the previous event's status (null for the first event). */
    public function fromStatus(): ?ApplicationStatus
    {
        return $this->previous_event_id === null ? null : $this->previousEvent?->to_status;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'actor_id');
    }
}
