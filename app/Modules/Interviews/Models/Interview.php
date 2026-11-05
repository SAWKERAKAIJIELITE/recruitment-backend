<?php

namespace App\Modules\Interviews\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Enums\InterviewResult;
use App\Modules\Interviews\Enums\InterviewStatus;
use App\Modules\Recruitment\Contracts\StatusChangeCause;
use App\Modules\Recruitment\Models\Application;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One interview of one application (round 1, then a new round after an accepted absence excuse). Always booked into
 * a sitting, which gives its place and committee (a one-off appointment is a sitting of one, D-061). Its result
 * causes the application's status change.
 *
 * @property string $id
 * @property string $application_id
 * @property int $round
 * @property string $sitting_id
 * @property InterviewSitting $sitting
 * @property InterviewStatus $status
 * @property ?InterviewResult $result
 */
class Interview extends Model implements StatusChangeCause
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return [
            'status' => InterviewStatus::class,
            'result' => InterviewResult::class,
            'scheduled_at' => 'datetime',
            'held_at' => 'datetime',
            'recorded_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'scientific_grade' => InterviewGrade::class,
            'general_grade' => InterviewGrade::class,
            'overall_grade' => InterviewGrade::class,
        ];
    }

    public static function statusEventColumn(): string
    {
        return 'interview_id';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(InterviewSitting::class, 'sitting_id');
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(InterviewReschedule::class)->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(InterviewResultRevision::class)->orderBy('revision');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'recorded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'cancelled_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function isScheduled(): bool
    {
        return $this->status === InterviewStatus::Scheduled;
    }
}
