<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 *
 * IMPORTANT: requires a tenant context. Use forTenant($tenant) state or resolve a
 * tenant in the container before calling create().
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /**
     * @var list<string>
     */
    private const TAG_NAMES = [
        'nuevo',
        'destacado',
        'oferta',
        'regalo',
        'agotandose',
        'temporada',
        'premium',
        'economico',
        'romantico',
        'corporativo',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = $this->faker->randomElement(self::TAG_NAMES);

        return [
            // tenant_id resolved from BelongsToTenant::creating() or forTenant() state
            'name' => $name,
            'slug' => Str::slug($name).'-'.strtolower(bin2hex(random_bytes(3))),
        ];
    }

    /**
     * Associate with a specific tenant.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }
}
