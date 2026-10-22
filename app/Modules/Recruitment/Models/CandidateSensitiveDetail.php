<?php

namespace App\Modules\Recruitment\Models;

use Illuminate\Database\Eloquent\Model;

/** Marital and health data. Only people holding candidates.view_sensitive may read or write it. */
class CandidateSensitiveDetail extends Model
{
    protected $primaryKey = 'candidate_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['marital_status', 'health_status', 'health_details'];

    protected function casts(): array
    {
        return ['health_details' => 'encrypted'];
    }
}
