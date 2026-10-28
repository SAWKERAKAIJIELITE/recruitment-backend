<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\Specialization;
use Illuminate\Database\Seeder;

/** Specializations. The template has no list for them; these are the values its rows use, plus suggestions. */
class SpecializationsSeeder extends Seeder
{
    use SeedsReferenceList;

    /** Values found in the template's rows (column P). */
    public const FROM_TEMPLATE = ['شريعة', 'تربية', 'لغة عربية', 'تاريخ', 'إدارة', 'محاسبة', 'حاسوب', 'هندسة'];

    /**
     * Suggested additions. "علمي" and "أدبي" are the two secondary-school branches, for applicants whose
     * highest qualification is ثانوي (a specialization is required on every application).
     */
    public const SUGGESTED = [
        'علمي', 'أدبي',
        'علوم قرآنية', 'دراسات إسلامية', 'لغة إنكليزية', 'رياضيات', 'فيزياء', 'كيمياء', 'علوم طبيعية',
        'جغرافيا', 'علم نفس', 'اقتصاد', 'حقوق', 'إعلام',
    ];

    public function run(): void
    {
        $this->seedList(Specialization::class, [...self::FROM_TEMPLATE, ...self::SUGGESTED]);
    }
}
