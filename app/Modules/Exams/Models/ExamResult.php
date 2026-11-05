<?php

namespace App\Modules\Exams\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decided result of an attempt (D-075): the engine's outcome under the plan's pass policy. Revised when a hand
 * mark is corrected (append-only); the latest revision counts.
 *
 * @property int $revision
 * @property bool $passed
 * @property string $average
 * @property array $outcome
 */
class ExamResult extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['passed' => 'boolean', 'average' => 'decimal:2', 'outcome' => 'array'];
    }

    public function passPolicy(): BelongsTo
    {
        return $this->belongsTo(PassPolicy::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by');
    }
}
