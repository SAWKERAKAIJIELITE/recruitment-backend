<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The vacancies of one job position in a plan (D-088).
 *
 * @property int $id
 * @property string $plan_id
 * @property int $job_position_id
 * @property int $vacancies
 */
class VacancyPlanItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(VacancyPlan::class, 'plan_id');
    }

    public function jobPosition(): BelongsTo
    {
        return $this->belongsTo(JobPosition::class)->withTrashed();
    }
}
