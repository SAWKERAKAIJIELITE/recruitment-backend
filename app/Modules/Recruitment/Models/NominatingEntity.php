<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: nominating entities (الجهات المرشِّحة). */
class NominatingEntity extends Model
{
    use IsReferenceData;

    protected $table = 'nominating_entities';
}
