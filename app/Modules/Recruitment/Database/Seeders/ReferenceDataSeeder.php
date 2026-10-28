<?php

namespace App\Modules\Recruitment\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Every managed list except the nominating entities, which the owner seeds (OD-13). Safe in every
 * environment and safe to run again (SeedsReferenceList).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DistrictsSeeder::class,
            AreasSeeder::class,
            MaritalStatusesSeeder::class,
            JobPositionsSeeder::class,
            ApplicationCategoriesSeeder::class,
            AcademicQualificationsSeeder::class,
            SpecializationsSeeder::class,
            HealthConditionsSeeder::class,
            NominatingEntitiesSeeder::class,
        ]);
    }
}
