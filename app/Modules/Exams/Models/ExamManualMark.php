<?php

namespace App\Modules\Exams\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The mark of a hand-marked part (التجويد) for one attempt. A correction is a new revision with a reason
 * (append-only); the latest revision counts.
 *
 * @property int $plan_part_id
 * @property int $revision
 * @property string $mark
 */
class ExamManualMark extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['mark' => 'decimal:2'];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(ExamPlanSubjectPart::class, 'plan_part_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'entered_by');
    }
}
