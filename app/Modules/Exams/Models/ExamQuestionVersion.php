<?php

namespace App\Modules\Exams\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a question says at one version. Never changed (database trigger): a correction is a new version.
 *
 * @property int $id
 * @property int $version
 * @property string $prompt
 * @property list<string> $options
 * @property int $correct_option
 */
class ExamQuestionVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'created_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'question_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    /** Same prompt, options and answer. */
    public function says(string $prompt, array $options, int $correct): bool
    {
        return $this->prompt === $prompt && $this->options === array_values($options) && $this->correct_option === $correct;
    }
}
