<?php

namespace App\Modules\Exams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question of an attempt, at the place this candidate sees it, with the answer saved last.
 *
 * @property int $id
 * @property int $position
 * @property ?int $selected_option
 * @property ?bool $is_correct
 */
class ExamAttemptItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'is_correct' => 'boolean', 'selected_option' => 'integer'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ExamAttempt::class, 'attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamSessionQuestion::class, 'session_question_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ExamAnswerRevision::class, 'attempt_item_id')->orderBy('id');
    }
}
