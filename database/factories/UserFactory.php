<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password1'),
            'remember_token' => Str::random(10),
            'is_super_admin' => false,
            'avatar_url' => null,
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
     * Create a super-admin user (platform-level, no tenant membership needed).
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }

    /**
     * Create a user and attach them to a tenant with a given role.
     *
     * Usage:
     *   User::factory()->forTenant($tenant, role: 'owner')->create();
     *   User::factory()->forTenant($tenant, role: 'staff')->create();
     */
    public function forTenant(Tenant $tenant, string $role = 'owner'): static
    {
        return $this->afterCreating(static function (User $user) use ($tenant, $role): void {
            $tenant->users()->attach($user->id, [
                'role' => $role,
                'joined_at' => now(),
            ]);
        });
    }
}
