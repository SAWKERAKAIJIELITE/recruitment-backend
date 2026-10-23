<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Entry of the managed list of job titles. */
class JobPosition extends Model
{
    use IsReferenceData;

    protected $table = 'job_positions';
}
