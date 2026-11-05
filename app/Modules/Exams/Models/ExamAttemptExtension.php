<?php

namespace App\Modules\Exams\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A move of one attempt's deadline: the previous and new deadline, how much, why, who (append-only). */
class ExamAttemptExtension extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['previous_deadline' => 'datetime', 'new_deadline' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'extended_by');
    }
}
