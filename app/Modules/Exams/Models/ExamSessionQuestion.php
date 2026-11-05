<?php

namespace App\Modules\Exams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question drawn for a session (D-056): one version of a bank question, for one item of the plan (a subject
 * scored as a whole, or one of its exam parts). Frozen once someone started.
 *
 * @property int $id
 * @property int $position
 * @property int $plan_subject_id
 * @property ?int $plan_part_id
 */
class ExamSessionQuestion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'session_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ExamQuestionVersion::class, 'question_version_id');
    }

    public function planSubject(): BelongsTo
    {
        return $this->belongsTo(ExamPlanSubject::class, 'plan_subject_id');
    }

    public function planPart(): BelongsTo
    {
        return $this->belongsTo(ExamPlanSubjectPart::class, 'plan_part_id');
    }
}
