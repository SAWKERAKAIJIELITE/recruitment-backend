<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Models\District;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/** Default list of governorates. Administrators can rename, add and archive entries afterwards. */
class DistrictsSeeder extends Seeder
{
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
        foreach (self::NAMES as $name) {
            // Matched by normalized name, so re-running never duplicates or resurrects a renamed entry.
            District::withTrashed()->firstOrCreate(['name_key' => ArabicText::searchKey($name)], ['name_ar' => $name]);
            // District::withTrashed()->firstOrCreate(['name_key' => $name], ['name_ar' => $name]);
        }
    }
}
