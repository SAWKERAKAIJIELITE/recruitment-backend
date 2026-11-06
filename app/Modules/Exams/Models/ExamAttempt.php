<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\AttemptStatus;
use App\Modules\Exams\Enums\ClosedBy;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Date;

/**
 * A candidate's attempt at a session (D-075): the session's questions in this candidate's own order, saved answer
 * by answer, until the candidate submits or the server deadline passes. Never reopened or deleted.
 *
 * @property string $id
 * @property string $booking_id
 * @property string $session_id
 * @property AttemptStatus $status
 * @property CarbonInterface $started_at
 * @property CarbonInterface $deadline_at
 * @property int $extra_minutes
 * @property ?CarbonInterface $submitted_at
 * @property ?ClosedBy $closed_by
 */
class ExamAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'in_progress'];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'closed_by' => ClosedBy::class,
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'submitted_at' => 'datetime',
            'extra_minutes' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(ExamBooking::class, 'booking_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamAttemptItem::class, 'attempt_id')->orderBy('position');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(ExamAttemptExtension::class, 'attempt_id')->orderBy('id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(ExamManualMark::class, 'attempt_id')->orderBy('plan_part_id')->orderBy('revision');
    }

    public function results(): HasMany
    {
        return $this->hasMany(ExamResult::class, 'attempt_id')->orderBy('revision');
    }

    public function result(): HasOne
    {
        return $this->hasOne(ExamResult::class, 'attempt_id')->latestOfMany('revision');
    }

    public function isOpen(): bool
    {
        return $this->status === AttemptStatus::InProgress;
    }

    /** Open, and its deadline has passed: the server closes it on the next look. */
    public function hasExpired(): bool
    {
        return $this->isOpen() && Date::now()->greaterThanOrEqualTo($this->deadline_at);
    }
}
