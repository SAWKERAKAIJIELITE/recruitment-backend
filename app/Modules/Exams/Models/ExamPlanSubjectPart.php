<?php

namespace App\Modules\Exams\Models;

use App\Modules\Exams\Enums\Assessment;
use App\Modules\Exams\Models\Concerns\HasDifficultyCounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part in a plan version, frozen for that version: its weight, and either its questions per difficulty (exam)
 * or the scale staff mark it out of (manual, D-054).
 *
 * @property int $part_id
 * @property string $weight
 * @property Assessment $assessment
 * @property ?string $max_score
 */
class ExamPlanSubjectPart extends Model
{
    use HasDifficultyCounts;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'max_score' => 'decimal:2', 'assessment' => Assessment::class];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(ExamSubjectPart::class, 'part_id')->withTrashed();
    }

    public function isManual(): bool
    {
        return $this->assessment === Assessment::Manual;
    }
}
