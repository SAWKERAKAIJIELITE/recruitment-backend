<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Models\MaritalStatus;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/** Default marital statuses (ERD diagram 07). Administrators can change the list afterwards. */
class MaritalStatusesSeeder extends Seeder
{
    private const NAMES = ['أعزب', 'متزوج', 'مطلق', 'أرمل'];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            MaritalStatus::withTrashed()->firstOrCreate(['name_key' => ArabicText::searchKey($name)], ['name_ar' => $name]);
        }
    }
}
