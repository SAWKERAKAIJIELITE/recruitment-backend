<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\Assessment;
use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part of a subject (التلاوة and التجويد in القرآن الكريم). Its weight inside the subject and how it is assessed
 * (in the exam, or marked by hand) are defaults: each plan version keeps its own copy.
 *
 * @property int $id
 * @property int $subject_id
 * @property string $default_weight
 * @property Assessment $default_assessment
 */
class ExamSubjectPart extends Model
{
    use IsReferenceData;

    protected $fillable = ['default_weight', 'default_assessment'];

    protected $attributes = ['default_assessment' => 'exam'];

    protected function casts(): array
    {
        return ['default_weight' => 'decimal:2', 'default_assessment' => Assessment::class];
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class, 'subject_id')->withTrashed();
    }
}
