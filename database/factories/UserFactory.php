<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\PermissionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Users->value,
            'permission_group_id' => PermissionGroup::where('slug', 'user')->value('id'),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user has the admin role.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin->value,
            'permission_group_id' => PermissionGroup::where('slug', 'admin')->value('id'),
        ]);
    }

    public function sales(): static
    {
        return $this->state(fn (array $attributes) => [
            'permission_group_id' => PermissionGroup::where('slug', 'sales')->value('id'),
        ]);
    }

    public function technical(): static
    {
        return $this->state(fn (array $attributes) => [
            'permission_group_id' => PermissionGroup::where('slug', 'technical')->value('id'),
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => [
            'permission_group_id' => PermissionGroup::where('slug', 'manager')->value('id'),
        ]);
    }
}
