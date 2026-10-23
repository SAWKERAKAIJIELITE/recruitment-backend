<?php

namespace App\Modules\Recruitment\Models;

use App\Modules\Recruitment\Models\Concerns\IsReferenceData;
use Illuminate\Database\Eloquent\Model;

/** Managed list: academic qualifications (المؤهلات العلمية). */
class AcademicQualification extends Model
{
    use IsReferenceData;

    protected $table = 'academic_qualifications';
}
