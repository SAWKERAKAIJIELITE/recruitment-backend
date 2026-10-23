<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: health conditions (الحالات الصحية). */
class HealthCondition extends Model
{
    use IsReferenceData;

    protected $table = 'health_conditions';
}
