<?php

namespace App\Modules\Identity\Database\Factories;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffUser> */
class StaffUserFactory extends Factory
{
    protected $model = StaffUser::class;

    public function definition(): array
    {
        return [
            // 'type' => User::TYPE_STAFF,
            'username' => fake()->unique()->regexify('^[a-z._-]{3,50}$'),
            // The database refuses names shorter than 3 characters; Faker sometimes returns "Al" or "Ng".
            'first_name' => fake()->valid(fn(string $name) => mb_strlen($name) >= 3)->firstName(),
            'last_name' => fake()->valid(fn(string $name) => mb_strlen($name) >= 3)->lastName(),
            'personal_number' => fake()->unique()->regexify('[0-9]{9}'),
            'password' => 'password',          // the 'hashed' cast hashes it
            'is_active' => true,
            // 'reported_mac'=>fake()->unique()->macAddress
            // 'must_change_password' => false,
        ];
    }

    // public function candidate(): static
    // {
    //     return $this->state(['type' => User::TYPE_CANDIDATE]);
    // }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /** Requires RolesAndPermissionsSeeder to have run. */
    public function withRole(string $slug): static
    {
        return $this->afterCreating(function (StaffUser $user) use ($slug) {
            $user->roles()->attach(Role::where('slug', $slug)->firstOrFail());
        });
    }
}
