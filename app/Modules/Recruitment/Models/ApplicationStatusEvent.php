<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Enums\ApplicationStatus;
use App\Modules\Recruitment\Enums\DecisionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of an application's history. Append-only: PostgreSQL rejects UPDATE, DELETE and TRUNCATE.
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
            'from_status' => ApplicationStatus::class,
            'to_status' => ApplicationStatus::class,
            'decision_type' => DecisionType::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'actor_id');
    }
}
