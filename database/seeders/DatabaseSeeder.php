<?php

namespace Database\Seeders;

use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Imports\Database\Seeders\ImportsDemoSeeder;
use App\Modules\Recruitment\Database\Seeders\RecruitmentDemoSeeder;
use App\Modules\Recruitment\Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        // Every managed list except the nominating entities, which the owner seeds (OD-13).
        $this->call(ReferenceDataSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);

            // Demo people, applications and import batches. They need the nominating entities: when none are
            // seeded yet, the demo adds the five names of the legacy template (DemoReferences).
            $this->call(RecruitmentDemoSeeder::class);
            $this->call(ImportsDemoSeeder::class);
        }
    }
}
