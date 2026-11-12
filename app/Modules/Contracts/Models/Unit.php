<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: the units a contractor joins (الجهة الملتحق بها, السوية التنظيمية الثانية; D-098). */
class Unit extends Model
{
    use IsReferenceData;

    protected $table = 'units';
}
