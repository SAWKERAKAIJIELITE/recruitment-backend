<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: specializations (الاختصاصات). */
class Specialization extends Model
{
    use IsReferenceData;

    protected $table = 'specializations';
}
