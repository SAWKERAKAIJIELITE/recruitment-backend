<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Entry of the managed list of governorates. */
class District extends Model
{
    use IsReferenceData;

    protected $table = 'districts';

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
