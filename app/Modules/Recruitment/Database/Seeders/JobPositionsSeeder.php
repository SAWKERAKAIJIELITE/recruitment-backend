<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\JobPosition;
use Illuminate\Database\Seeder;

/** Default job positions. Staff holding candidates.manage edit the list afterwards. */
class JobPositionsSeeder extends Seeder
{
    use SeedsReferenceList;

    /** The legacy template's list (lstJobs). */
    public const FROM_TEMPLATE = ['موجه تربوي', 'مدير مكتب', 'مدخل بيانات', 'لوجستي', 'مستخدم', 'سائق'];

    /** Suggested additions (not in the template): remove the ones the organization does not hire for. */
    public const SUGGESTED = ['محاسب', 'أمين مستودع', 'موظف إداري', 'فني صيانة', 'حارس'];

    public function run(): void
    {
        $this->seedList(JobPosition::class, [...self::FROM_TEMPLATE, ...self::SUGGESTED]);
    }
}
