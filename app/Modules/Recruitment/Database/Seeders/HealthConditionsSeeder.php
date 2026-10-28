<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\HealthCondition;
use Illuminate\Database\Seeder;

/**
 * Health conditions. The template's column is "الحالة الصحية/بالتفصيل" and its rows all say سليم; the
 * details of any other condition go in the application's health_details, so the list stays short.
 */
class HealthConditionsSeeder extends Seeder
{
    use SeedsReferenceList;

    public const FROM_TEMPLATE = ['سليم'];

    public const SUGGESTED = ['مرض مزمن', 'إعاقة حركية', 'إعاقة بصرية', 'إعاقة سمعية', 'حالة أخرى'];

    public function run(): void
    {
        $this->seedList(HealthCondition::class, [...self::FROM_TEMPLATE, ...self::SUGGESTED]);
    }
}
