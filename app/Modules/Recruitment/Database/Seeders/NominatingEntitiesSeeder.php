<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\NominatingEntity;
use Illuminate\Database\Seeder;

/**
 * The template's categories (lstCats): a new contractor, or an existing employee ("رقم ذاتي", whose
 * employee number goes in existing_employee_number). Nothing is suggested: the categories are a policy.
 */
class NominatingEntitiesSeeder extends Seeder
{
    use SeedsReferenceList;

    public const FROM_TEMPLATE = ['مديرية الأوقاف', 'مركز التحفيظ', 'الإدارة العامة', 'المعهد الشرعي', 'إعداد الدعاة'];

    public function run(): void
    {
        $this->seedList(NominatingEntity::class, self::FROM_TEMPLATE);
    }
}
