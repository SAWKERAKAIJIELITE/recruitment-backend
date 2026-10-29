<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\ExamPlanStatus;
use App\Modules\Exams\Scoring\ScoringPlan;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a job position's exam plan (D-053). Changed only through the plan actions; the database
 * refuses changes once it is published.
 *
 * @property string $id
 * @property int $job_position_id
 * @property int $version
 * @property ExamPlanStatus $status
 * @property ?int $duration_minutes
 */
class ExamPlanVersion extends Model
{
    use HasUuids;

    protected $fillable = ['duration_minutes', 'notes'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'status' => ExamPlanStatus::class,
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class)->withTrashed();
    }

    public function passPolicy(): BelongsTo
    {
        return $this->belongsTo(PassPolicy::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(ExamPlanSubject::class, 'plan_version_id')->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === ExamPlanStatus::Draft;
    }

    /** Online questions the plan asks (manual parts ask none). */
    public function questionCount(): int
    {
        return $this->subjects->sum(fn(ExamPlanSubject $subject) => $subject->totalQuestions());
    }

    /** What the scoring engine needs from this version: its subjects, parts and weights. */
    public function scoringPlan(): ScoringPlan
    {
        $this->loadMissing('subjects.parts');

        return new ScoringPlan($this->subjects->mapWithKeys(fn(ExamPlanSubject $subject) => [
            $subject->subject_id => $subject->parts->isEmpty()
                ? null
                : $subject->parts->mapWithKeys(fn(ExamPlanSubjectPart $part) => [$part->part_id => (string) $part->weight])->all(),
        ])->all());
    }
}
