<?php

namespace App\Modules\Interviews\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of an interview's appointment, with its reason: from a sitting and a time to a sitting and a time (the
 * sitting gives the place and the committee). Never changed (database trigger).
 */
class InterviewReschedule extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['from_scheduled_at' => 'datetime', 'to_scheduled_at' => 'datetime'];
    }

    public function fromSitting(): BelongsTo
    {
        return $this->belongsTo(InterviewSitting::class, 'from_sitting_id');
    }

    public function toSitting(): BelongsTo
    {
        return $this->belongsTo(InterviewSitting::class, 'to_sitting_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'rescheduled_by');
    }
}
