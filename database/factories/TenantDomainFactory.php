<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => $this->faker->unique()->domainName(),
            'status' => 'pending',
            'verification_token' => Str::random(40),
            'verified_at' => null,
            'ssl_status' => 'pending',
            'ssl_expires_at' => null,
        ];
    }

    /**
     * A verified domain with active SSL.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'verified',
            'verified_at' => now()->subDays(30),
            'ssl_status' => 'active',
            'ssl_expires_at' => now()->addDays(60),
        ]);
    }

    /**
     * A domain whose SSL has expired.
     */
    public function sslExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'verified',
            'verified_at' => now()->subDays(90),
            'ssl_status' => 'expired',
            'ssl_expires_at' => now()->subDays(1),
        ]);
    }
}
