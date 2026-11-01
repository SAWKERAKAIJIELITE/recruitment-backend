<?php

namespace App\Modules\Contracts\Database\Seeders;

use App\Modules\Contracts\Models\ContractType;
use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use Illuminate\Database\Seeder;

/**
 * Contract types (نوع العقد). Suggestions until management sends the contract form (§19): staff change the list
 * through the API (contract-types). Safe in every environment and safe to run again.
 */
class ContractTypesSeeder extends Seeder
{
    use SeedsReferenceList;

    public const SUGGESTED = ['عقد سنوي', 'عقد مؤقت', 'عقد لمدة محددة'];

    public function run(): void
    {
        $this->seedList(ContractType::class, self::SUGGESTED);
    }
}
