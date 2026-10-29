<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\NominatingEntity;
use Illuminate\Database\Seeder;

/** The nominating entities used in the legacy template's rows (column B, typed by hand there; OD-13). */
class NominatingEntitiesSeeder extends Seeder
{
    use SeedsReferenceList;

    public const FROM_TEMPLATE = ['مديرية الأوقاف', 'مركز التحفيظ', 'الإدارة العامة', 'المعهد الشرعي', 'إعداد الدعاة'];

    public function run(): void
    {
        $this->seedList(NominatingEntity::class, self::FROM_TEMPLATE);
    }
}
