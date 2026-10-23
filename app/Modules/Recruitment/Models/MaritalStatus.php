<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: marital statuses (الحالات الاجتماعية). */
class MaritalStatus extends Model
{
    use IsReferenceData;

    protected $table = 'marital_statuses';
}
