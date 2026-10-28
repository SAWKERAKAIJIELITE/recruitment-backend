<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\MaritalStatus;
use Illuminate\Database\Seeder;

/** Default marital statuses (ERD diagram 07). Administrators can change the list afterwards. */
class MaritalStatusesSeeder extends Seeder
{
    use SeedsReferenceList;

    private const NAMES = ['أعزب', 'متزوج', 'مطلق', 'أرمل'];

    public function run(): void
    {
        $this->seedList(MaritalStatus::class, [...self::NAMES]);
    }
}
