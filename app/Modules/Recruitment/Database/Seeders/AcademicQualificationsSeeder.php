<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\AcademicQualification;
use Illuminate\Database\Seeder;

/**
 * Academic qualifications, lowest first. The template (lstEdu) has ثانوي … دكتوراه; "جامعي" there means
 * still studying at university and "خريج" a graduate.
 */
class AcademicQualificationsSeeder extends Seeder
{
    use SeedsReferenceList;

    public const FROM_TEMPLATE = ['ثانوي', 'معهد', 'جامعي', 'خريج', 'ماجستير', 'دكتوراه'];

    /** Suggested additions: the levels below and between the template's. */
    public const SUGGESTED = ['تعليم أساسي', 'دبلوم دراسات عليا'];

    public function run(): void
    {
        $this->seedList(AcademicQualification::class, [...self::SUGGESTED, ...self::FROM_TEMPLATE]);
    }
}
