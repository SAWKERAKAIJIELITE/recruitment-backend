<?php

namespace App\Modules\Exams\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Time added to a whole session (append-only); each running attempt records its share. */
class ExamSessionExtension extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'extended_by');
    }
}
