<?php

namespace App\Modules\Contracts\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: contract types (نوع العقد). */
class ContractType extends Model
{
    use IsReferenceData;

    protected $table = 'contract_types';
}
