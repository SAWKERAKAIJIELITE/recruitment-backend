<?php

namespace App\Modules\Recruitment\Database\Seeders;

use App\Modules\Recruitment\Database\Seeders\Concerns\SeedsReferenceList;
use App\Modules\Recruitment\Models\ApplicationCategory;
use Illuminate\Database\Seeder;

/**
 * The template's categories (lstCats): a new contractor, or an existing employee ("رقم ذاتي", whose
 * employee number goes in existing_employee_number). Nothing is suggested: the categories are a policy.
 */
class ApplicationCategoriesSeeder extends Seeder
{
    use SeedsReferenceList;

    public const FROM_TEMPLATE = ['متعاقد جديد', 'رقم ذاتي'];

    public function run(): void
    {
        $this->seedList(ApplicationCategory::class, self::FROM_TEMPLATE);
    }
}
