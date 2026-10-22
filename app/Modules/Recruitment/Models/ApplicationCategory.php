<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Entry of the managed list of application categories. */
class ApplicationCategory extends Model
{
    use IsReferenceData;
}
