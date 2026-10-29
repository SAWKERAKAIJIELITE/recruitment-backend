<?php

namespace App\Modules\Exams\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subject such as الحديث النبوي. Every subject counts once and equally in the average (analysis doc §04),
 * so it has no weight of its own.
 *
 * @property int $id
 * @property string $name_ar
 */
class ExamSubject extends Model
{
    use IsReferenceData;

    public function parts(): HasMany
    {
        return $this->hasMany(ExamSubjectPart::class, 'subject_id')->orderBy('id');
    }
}
