<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\ReservedSubdomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservedSubdomain>
 */
class ReservedSubdomainFactory extends Factory
{
    protected $model = ReservedSubdomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subdomain' => $this->faker->unique()->slug(2),
            'category' => $this->faker->randomElement([
                'system',
                'brand',
                'trademark',
                'profanity',
                'regulated',
                'security-sensitive',
            ]),
        ];
    }

    /**
     * A system-reserved subdomain.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => ['category' => 'system']);
    }

    /**
     * A brand-reserved subdomain.
     */
    public function brand(): static
    {
        return $this->state(fn (array $attributes) => ['category' => 'brand']);
    }

    /**
     * A trademark-reserved subdomain.
     */
    public function trademark(): static
    {
        return $this->state(fn (array $attributes) => ['category' => 'trademark']);
    }

    /**
     * A profanity-reserved subdomain.
     */
    public function profanity(): static
    {
        return $this->state(fn (array $attributes) => ['category' => 'profanity']);
    }

    /**
     * A regulated-term subdomain.
     */
    public function regulated(): static
    {
        return $this->state(fn (array $attributes) => ['category' => 'regulated']);
    }

    /**
     * A security-sensitive subdomain.
     */
    public function securitySensitive(): static
    {
        return $this->state(fn (array $attributes) => ['category' => 'security-sensitive']);
    }
}
