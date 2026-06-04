<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $businessNames = [
            'Floristeria Rosa Eterna',
            'Regalos Tatiana',
            'Boutique Las Flores',
            'Arreglos Florales Mia',
            'Peluches y Regalos Valentina',
            'Accesorios Florencia',
            'La Florista del Centro',
            'Detalles con Amor',
            'Flores y Sonrisas',
            'Regalo Perfecto',
        ];

        $businessName = $this->faker->randomElement($businessNames);
        $slug = $this->makeUniqueSlug($businessName);

        $localeData = $this->faker->randomElement([
            ['country_code' => 'SV', 'currency' => 'USD', 'timezone' => 'America/El_Salvador', 'language' => 'es'],
            ['country_code' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota',       'language' => 'es'],
        ]);

        return [
            'name' => $businessName,
            'business_name' => $businessName,
            'slug' => $slug,
            'email' => $this->faker->unique()->safeEmail(),
            'status' => 'active',
            'primary_color' => $this->faker->hexColor(),
            'country_code' => $localeData['country_code'],
            'currency' => $localeData['currency'],
            'timezone' => $localeData['timezone'],
            'language' => $localeData['language'],
            'trial_ends_at' => now()->addDays(30),
        ];
    }

    /**
     * Tenant in trial state (default, but explicit state for readability).
     */
    public function onTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    /**
     * Tenant with no active trial.
     */
    public function withoutTrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'trial_ends_at' => null,
        ]);
    }

    /**
     * Suspended tenant.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /**
     * El Salvador locale defaults (the primary target market).
     */
    public function elsalvador(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'SV',
            'currency' => 'USD',
            'timezone' => 'America/El_Salvador',
            'language' => 'es',
        ]);
    }

    /**
     * Colombia locale.
     */
    public function colombia(): static
    {
        return $this->state(fn (array $attributes) => [
            'country_code' => 'CO',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'language' => 'es',
        ]);
    }

    /**
     * Generate a URL-safe slug from a business name, ensuring test uniqueness.
     */
    private function makeUniqueSlug(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'tenant');
        $base = trim($base, '-');

        return $base.'-'.$this->faker->unique()->numberBetween(1000, 9999);
    }
}
