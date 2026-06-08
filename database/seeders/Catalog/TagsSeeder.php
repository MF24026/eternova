<?php

declare(strict_types=1);

namespace Database\Seeders\Catalog;

use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds a standard set of tags for each demo tenant.
 *
 * Tags are keyed on (tenant_id, slug) so the seeder is idempotent.
 */
final class TagsSeeder extends Seeder
{
    /**
     * Tags created for every tenant.
     * Names are presentation strings; slugs are URL/DB keys.
     *
     * @var list<array{name: string, slug: string}>
     */
    private const STANDARD_TAGS = [
        ['name' => 'nuevo',       'slug' => 'nuevo'],
        ['name' => 'destacado',   'slug' => 'destacado'],
        ['name' => 'oferta',      'slug' => 'oferta'],
        ['name' => 'regalo',      'slug' => 'regalo'],
        ['name' => 'agotandose',  'slug' => 'agotandose'],
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);

        foreach (self::STANDARD_TAGS as $definition) {
            Tag::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $definition['slug']],
                ['name' => $definition['name']],
            );
        }

        app()->forgetInstance('currentTenant');
    }
}
