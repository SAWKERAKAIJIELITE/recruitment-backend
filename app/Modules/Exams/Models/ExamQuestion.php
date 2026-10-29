<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\Difficulty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A question of the bank (D-055). What it says is in its versions; the latest one is current.
 *
 * @property int $id
 * @property int $subject_id
 * @property ?int $part_id
 * @property Difficulty $difficulty
 */
class ExamQuestion extends Model
{
    use SoftDeletes;

    protected $fillable = ['difficulty'];

    protected function casts(): array
    {
        return ['difficulty' => Difficulty::class];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class, 'subject_id')->withTrashed();
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(ExamSubjectPart::class, 'part_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ExamQuestionVersion::class, 'question_id')->orderBy('version');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(ExamQuestionVersion::class, 'question_id')->latestOfMany('version');
    }

    /**
     * The questions an exam item can draw from: a subject scored as a whole uses every question of the subject;
     * a part uses its own questions only.
     */
    public function scopeForItem(Builder $query, int $subjectId, ?int $partId): Builder
    {
        return $query->where('subject_id', $subjectId)->when($partId !== null, fn(Builder $query) => $query->where('part_id', $partId));
    }
}
