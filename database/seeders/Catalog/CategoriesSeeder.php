<?php

declare(strict_types=1);

namespace Database\Seeders\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the category tree for each demo tenant.
 *
 * rosa-eterna (florist, SV): three root categories with children — 9 categories total.
 * tatiana (gift shop, CO): four flat root categories — no children.
 *
 * Keyed on (tenant_id, slug) so re-running the full db:seed is idempotent.
 */
final class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        // Bind the tenant so BelongsToTenant::creating() auto-sets tenant_id.
        app()->instance('currentTenant', $tenant);

        match ($tenant->slug) {
            'rosa-eterna' => $this->seedRosaEterna($tenant),
            'tatiana' => $this->seedTatiana($tenant),
            default => null,
        };

        // Remove the binding so subsequent seeders start clean.
        app()->forgetInstance('currentTenant');
    }

    /**
     * Florist hierarchy for rosa-eterna (SV, USD).
     *
     * Tree:
     *   Arreglos florales        (root)
     *     Rosas eternas          (child)
     *     Bouquets de novia      (child)
     *     Centros de mesa        (child)
     *   Regalos                  (root)
     *     Peluches               (child)
     *     Globos                 (child)
     *   Ocasiones especiales     (root)
     *     Cumpleanos             (child)
     *     Aniversarios           (child)
     *     Bodas                  (child)
     */
    private function seedRosaEterna(Tenant $tenant): void
    {
        // ---- root: Arreglos florales ----------------------------------------
        $arreglos = $this->upsertCategory($tenant, [
            'name' => 'Arreglos florales',
            'slug' => 'arreglos-florales',
            'sort_order' => 0,
        ]);

        $this->upsertCategory($tenant, [
            'name' => 'Rosas eternas',
            'slug' => 'rosas-eternas',
            'parent_id' => $arreglos->id,
            'sort_order' => 0,
        ]);
        $this->upsertCategory($tenant, [
            'name' => 'Bouquets de novia',
            'slug' => 'bouquets-de-novia',
            'parent_id' => $arreglos->id,
            'sort_order' => 1,
        ]);
        $this->upsertCategory($tenant, [
            'name' => 'Centros de mesa',
            'slug' => 'centros-de-mesa',
            'parent_id' => $arreglos->id,
            'sort_order' => 2,
        ]);

        // ---- root: Regalos --------------------------------------------------
        $regalos = $this->upsertCategory($tenant, [
            'name' => 'Regalos',
            'slug' => 'regalos',
            'sort_order' => 1,
        ]);

        $this->upsertCategory($tenant, [
            'name' => 'Peluches',
            'slug' => 'peluches',
            'parent_id' => $regalos->id,
            'sort_order' => 0,
        ]);
        $this->upsertCategory($tenant, [
            'name' => 'Globos',
            'slug' => 'globos',
            'parent_id' => $regalos->id,
            'sort_order' => 1,
        ]);

        // ---- root: Ocasiones especiales -------------------------------------
        $ocasiones = $this->upsertCategory($tenant, [
            'name' => 'Ocasiones especiales',
            'slug' => 'ocasiones-especiales',
            'sort_order' => 2,
        ]);

        $this->upsertCategory($tenant, [
            'name' => 'Cumpleanos',
            'slug' => 'cumpleanos',
            'parent_id' => $ocasiones->id,
            'sort_order' => 0,
        ]);
        $this->upsertCategory($tenant, [
            'name' => 'Aniversarios',
            'slug' => 'aniversarios',
            'parent_id' => $ocasiones->id,
            'sort_order' => 1,
        ]);
        $this->upsertCategory($tenant, [
            'name' => 'Bodas',
            'slug' => 'bodas',
            'parent_id' => $ocasiones->id,
            'sort_order' => 2,
        ]);
    }

    /**
     * Flat categories for tatiana (gift shop, CO, COP).
     * No children — this tenant keeps it simple.
     */
    private function seedTatiana(Tenant $tenant): void
    {
        $definitions = [
            ['name' => 'Regalos personalizados', 'slug' => 'regalos-personalizados', 'sort_order' => 0],
            ['name' => 'Joyeria',                'slug' => 'joyeria',                'sort_order' => 1],
            ['name' => 'Decoracion hogar',       'slug' => 'decoracion-hogar',       'sort_order' => 2],
            ['name' => 'Detalles corporativos',  'slug' => 'detalles-corporativos',  'sort_order' => 3],
        ];

        foreach ($definitions as $definition) {
            $this->upsertCategory($tenant, $definition);
        }
    }

    /**
     * Insert or update a category keyed on (tenant_id, slug).
     *
     * BelongsToTenant::creating() has already set currentTenant in the container, so
     * tenant_id is auto-filled on create. For the updateOrCreate path we pass tenant_id
     * in the search key to guarantee the WHERE clause hits the right row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertCategory(Tenant $tenant, array $attributes): Category
    {
        $searchKey = [
            'tenant_id' => $tenant->id,
            'slug' => $attributes['slug'],
        ];

        unset($attributes['slug']);

        return Category::withoutGlobalScopes()->updateOrCreate($searchKey, $attributes);
    }
}
