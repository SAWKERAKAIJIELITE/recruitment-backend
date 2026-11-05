<?php

namespace Database\Seeders;

use App\Modules\Contracts\Database\Seeders\ContractsDemoSeeder;
use App\Modules\Contracts\Database\Seeders\ContractTypesSeeder;
use App\Modules\Documents\Database\Seeders\DocumentsDemoSeeder;
use App\Modules\Documents\Database\Seeders\DocumentTypesSeeder;
use App\Modules\Exams\Database\Seeders\ExamSessionsDemoSeeder;
use App\Modules\Exams\Database\Seeders\ExamsDemoSeeder;
use App\Modules\Exams\Database\Seeders\ExamSubjectsSeeder;
use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Interviews\Database\Seeders\InterviewsDemoSeeder;
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
        $this->call(ContractTypesSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);

            // Demo data through the real actions: people and applications, import batches, the question bank and
            // exam plans, contracts (with a reminder), documents, interviews.
            $this->call(RecruitmentDemoSeeder::class);
            $this->call(ImportsDemoSeeder::class);
            $this->call(ExamsDemoSeeder::class);
            $this->call(ContractsDemoSeeder::class);
            $this->call(DocumentsDemoSeeder::class);
            $this->call(InterviewsDemoSeeder::class);
            $this->call(ExamSessionsDemoSeeder::class);   // last: it moves applications on yesterday
        }
    }
}
