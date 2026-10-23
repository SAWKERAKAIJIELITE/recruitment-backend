<?php

namespace Database\Seeders;

use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Recruitment\Database\Seeders\DistrictsSeeder;
use App\Modules\Recruitment\Database\Seeders\MaritalStatusesSeeder;
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
        $this->call(DistrictsSeeder::class);
        $this->call(MaritalStatusesSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);
        }
    }
}
