<?php

namespace App\Modules\Exams\Models;

use Illuminate\Database\Eloquent\Model;

/** Every answer saved, in order (append-only): the history behind an item's last answer. */
class ExamAnswerRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['saved_at' => 'datetime', 'selected_option' => 'integer'];
    }
}
