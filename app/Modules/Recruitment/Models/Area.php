<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Managed list: areas (neighborhoods) inside a district. The name is unique within its district only.
 * district_id is set when the area is created and never changes: applications point at the pair.
 */
class Area extends Model
{
    use IsReferenceData;

    protected $table = 'areas';

    protected $fillable = ['district_id'];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class)->withTrashed();
    }
}
