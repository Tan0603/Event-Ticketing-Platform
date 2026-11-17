<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => strtolower(fake()->unique()->safeEmail()),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Admin,
        ];
    }

    public function doorStaff(): static
    {
        return $this->state(['role' => UserRole::DoorStaff]);
    }
}
