<?php

namespace Database\Seeders;

// use App\Models\User;
use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
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
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);
        }
    }
}
