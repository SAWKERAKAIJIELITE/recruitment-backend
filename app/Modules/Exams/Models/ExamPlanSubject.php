<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Models\Concerns\HasDifficultyCounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject in a plan version: scored as a whole (questions per difficulty) or from its parts.
 *
 * @property int $id
 * @property string $plan_version_id
 * @property int $subject_id
 * @property ?int $easy_count
 * @property ?int $medium_count
 * @property ?int $hard_count
 */
class ExamPlanSubject extends Model
{
    use HasDifficultyCounts;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class, 'subject_id')->withTrashed();
    }

    public function parts(): HasMany
    {
        return $this->hasMany(ExamPlanSubjectPart::class, 'plan_subject_id')->orderBy('position');
    }

    public function scoredFromParts(): bool
    {
        return $this->parts->isNotEmpty();
    }

    /** Online questions of the subject (manual parts ask none). */
    public function totalQuestions(): int
    {
        return $this->scoredFromParts()
            ? (int) $this->parts->sum(fn(ExamPlanSubjectPart $part) => $part->countedQuestions())
            : $this->countedQuestions();
    }
}
