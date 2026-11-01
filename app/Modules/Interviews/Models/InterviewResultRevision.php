<?php

namespace App\Modules\Interviews\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Interviews\Enums\InterviewGrade;
use App\Modules\Interviews\Enums\InterviewResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One version of an interview's result: 1 as recorded, then each correction. Never changed (database trigger). */
class InterviewResultRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'result' => InterviewResult::class,
            'held_at' => 'datetime',
            'scientific_grade' => InterviewGrade::class,
            'general_grade' => InterviewGrade::class,
            'overall_grade' => InterviewGrade::class,
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'entered_by');
    }
}
