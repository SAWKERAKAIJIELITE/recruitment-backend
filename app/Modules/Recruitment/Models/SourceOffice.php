<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Entry of the managed list of source offices. */
class SourceOffice extends Model
{
    use IsReferenceData;
}
