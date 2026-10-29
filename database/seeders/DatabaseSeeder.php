<?php

namespace Database\Seeders;

use App\Modules\Documents\Database\Seeders\DocumentsDemoSeeder;
use App\Modules\Documents\Database\Seeders\DocumentTypesSeeder;
use App\Modules\Exams\Database\Seeders\ExamsDemoSeeder;
use App\Modules\Exams\Database\Seeders\ExamSubjectsSeeder;
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
        $this->call(ReferenceDataSeeder::class);
        $this->call(ExamSubjectsSeeder::class);
        $this->call(DocumentTypesSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);

            // Demo data through the real actions: people and applications, import batches, the question bank and
            // exam plans, documents.
            $this->call(RecruitmentDemoSeeder::class);
            $this->call(ImportsDemoSeeder::class);
            $this->call(ExamsDemoSeeder::class);
            $this->call(DocumentsDemoSeeder::class);
        }
    }
}
