<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\District;
use Illuminate\Database\Seeder;

/** Default list of governorates. Administrators can rename, add and archive entries afterwards. */
class DistrictsSeeder extends Seeder
{
    use SeedsReferenceList;

    private const NAMES = [
        'دمشق',
        'ريف دمشق',
        'حلب',
        'حمص',
        'حماة',
        'اللاذقية',
        'طرطوس',
        'إدلب',
        'دير الزور',
        'الرقة',
        'الحسكة',
        'درعا',
        'السويداء',
        'القنيطرة',
        'تدمر'
    ];

    public function run(): void
    {
        $this->seedList(District::class, [...self::NAMES]);
    }
}
