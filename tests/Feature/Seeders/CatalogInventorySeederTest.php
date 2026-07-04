<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Integrity tests for the Sprint 1 catalog + inventory demo seeders.
 *
 * All tests use RefreshDatabase — each test starts from a clean schema.
 * No mocks — every assertion hits the real DB.
 * Tests call $this->seed() (full DatabaseSeeder) unless a subset is sufficient.
 */
final class CatalogInventorySeederTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Full pipeline

    public function test_database_seeder_runs_all_seeders_without_error(): void
    {
        $this->seed(DatabaseSeeder::class);

        // If we reach here the full pipeline executed without an exception.
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------ Categories

    public function test_each_demo_tenant_has_categories(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // rosa-eterna: 3 roots + children from CategoriesSeeder, plus one category
        // introduced later in the demo pipeline. 12 is the deterministic clean-seed
        // total (the idempotency test guards against accidental growth).
        $rosaCategoryCount = Category::withoutGlobalScopes()
            ->where('tenant_id', $rosaEterna->id)
            ->count();
        $this->assertSame(12, $rosaCategoryCount, 'rosa-eterna should have 12 categories');

        // tatiana: flat root categories from CategoriesSeeder plus starter-catalog
        // categories from the demo pipeline. 8 is the deterministic clean-seed total.
        $tatianaCategoryCount = Category::withoutGlobalScopes()
            ->where('tenant_id', $tatiana->id)
            ->count();
        $this->assertSame(8, $tatianaCategoryCount, 'tatiana should have 8 categories');
    }

    public function test_rosa_eterna_categories_have_correct_hierarchy(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $this->assertNotNull($rosaEterna);

        $child = Category::withoutGlobalScopes()
            ->where('tenant_id', $rosaEterna->id)
            ->where('slug', 'rosas-eternas')
            ->first();

        $this->assertNotNull($child, '"Rosas eternas" category should exist for rosa-eterna');
        $this->assertNotNull($child->parent_id, '"Rosas eternas" should have a parent');

        $parent = Category::withoutGlobalScopes()->find($child->parent_id);
        $this->assertNotNull($parent, 'Parent category should exist');
        $this->assertSame('arreglos-florales', $parent->slug, 'Parent of "Rosas eternas" should be "Arreglos florales"');
    }

    // ------------------------------------------------------------------ Products

    public function test_each_demo_tenant_has_around_20_products(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        $rosaCount = Product::withoutGlobalScopes()->where('tenant_id', $rosaEterna->id)->count();
        $tatianaCount = Product::withoutGlobalScopes()->where('tenant_id', $tatiana->id)->count();

        // Loose band: this is a demo-data volume sanity check ("around 20"), not an
        // exact contract. Upper bound only guards against runaway seeding.
        $this->assertGreaterThanOrEqual(18, $rosaCount, 'rosa-eterna should have at least 18 products');
        $this->assertLessThanOrEqual(30, $rosaCount, 'rosa-eterna should have at most 30 products');

        $this->assertGreaterThanOrEqual(18, $tatianaCount, 'tatiana should have at least 18 products');
        $this->assertLessThanOrEqual(30, $tatianaCount, 'tatiana should have at most 30 products');
    }

    public function test_products_have_variants(): void
    {
        $this->seed(DatabaseSeeder::class);

        $products = Product::withoutGlobalScopes()
            ->withCount('variants')
            ->get();

        $this->assertNotEmpty($products, 'There should be seeded products');

        foreach ($products as $product) {
            $this->assertGreaterThanOrEqual(
                1,
                $product->variants_count,
                "Product [{$product->name}] should have at least 1 variant",
            );
        }
    }

    public function test_products_are_attached_to_categories(): void
    {
        $this->seed(DatabaseSeeder::class);

        $products = Product::withoutGlobalScopes()
            ->withCount('categories')
            ->get();

        $this->assertNotEmpty($products, 'There should be seeded products');

        foreach ($products as $product) {
            $this->assertGreaterThanOrEqual(
                1,
                $product->categories_count,
                "Product [{$product->name}] should be attached to at least 1 category",
            );
        }
    }

    // ------------------------------------------------------------------ Inventory

    public function test_each_variant_has_branch_inventory(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (Tenant::all() as $tenant) {
            $branch = Branch::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_main', true)
                ->first();

            $this->assertNotNull($branch, "Tenant {$tenant->slug} should have a main branch");

            // Collect all variant IDs for this tenant.
            $variantIds = ProductVariant::withoutGlobalScopes()
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.tenant_id', $tenant->id)
                ->whereNull('product_variants.deleted_at')
                ->whereNull('products.deleted_at')
                ->pluck('product_variants.id');

            $this->assertNotEmpty($variantIds, "Tenant {$tenant->slug} should have variants");

            foreach ($variantIds as $variantId) {
                $exists = BranchInventory::withoutGlobalScopes()
                    ->where('branch_id', $branch->id)
                    ->where('product_variant_id', $variantId)
                    ->exists();

                $this->assertTrue(
                    $exists,
                    "Variant {$variantId} should have a BranchInventory row for branch {$branch->id}",
                );
            }
        }
    }

    public function test_some_variants_are_out_of_stock_for_alert_testing(): void
    {
        $this->seed(DatabaseSeeder::class);

        // At least 1 variant per tenant should have available = 0.
        foreach (Tenant::all() as $tenant) {
            $branch = Branch::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_main', true)
                ->first();

            $this->assertNotNull($branch);

            $outOfStockCount = BranchInventory::withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->where('quantity', 0)
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $outOfStockCount,
                "Tenant {$tenant->slug} should have at least 1 out-of-stock variant",
            );
        }
    }

    public function test_some_variants_are_low_stock(): void
    {
        $this->seed(DatabaseSeeder::class);

        // At least 1 variant per tenant should have quantity > 0 but <= min_stock_alert.
        foreach (Tenant::all() as $tenant) {
            $branch = Branch::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('is_main', true)
                ->first();

            $this->assertNotNull($branch);

            // Low-stock = available is positive but at or below the variant's alert threshold.
            // We join product_variants to read min_stock_alert.
            $lowStockCount = BranchInventory::withoutGlobalScopes()
                ->join('product_variants', 'product_variants.id', '=', 'branch_inventory.product_variant_id')
                ->where('branch_inventory.branch_id', $branch->id)
                ->whereColumn('branch_inventory.quantity', '<=', 'product_variants.min_stock_alert')
                ->where('branch_inventory.quantity', '>', 0)
                ->whereNotNull('product_variants.min_stock_alert')
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $lowStockCount,
                "Tenant {$tenant->slug} should have at least 1 low-stock variant",
            );
        }
    }

    // ------------------------------------------------------------------ Movements

    public function test_inventory_movements_exist_with_backdated_timestamps(): void
    {
        $this->seed(DatabaseSeeder::class);

        $thirtyDaysAgo = Carbon::now()->subDays(30)->startOfDay();

        foreach (Tenant::all() as $tenant) {
            $movementCount = InventoryMovement::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $movementCount,
                "Tenant {$tenant->slug} should have inventory movements in the last 30 days",
            );
        }
    }

    // ------------------------------------------------------------------ Idempotency

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $categoryCountFirst = Category::withoutGlobalScopes()->count();
        $productCountFirst = Product::withoutGlobalScopes()->count();
        $variantCountFirst = ProductVariant::withoutGlobalScopes()->count();
        $inventoryCountFirst = BranchInventory::withoutGlobalScopes()->count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            $categoryCountFirst,
            Category::withoutGlobalScopes()->count(),
            'Running the seeder twice should not create duplicate categories',
        );
        $this->assertSame(
            $productCountFirst,
            Product::withoutGlobalScopes()->count(),
            'Running the seeder twice should not create duplicate products',
        );
        $this->assertSame(
            $variantCountFirst,
            ProductVariant::withoutGlobalScopes()->count(),
            'Running the seeder twice should not create duplicate variants',
        );
        $this->assertSame(
            $inventoryCountFirst,
            BranchInventory::withoutGlobalScopes()->count(),
            'Running the seeder twice should not create duplicate branch_inventory rows',
        );
    }

    // ------------------------------------------------------------------ Tenant isolation

    public function test_catalog_data_is_tenant_isolated(): void
    {
        $this->seed(DatabaseSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // With the rosa-eterna tenant in context, only its products should be visible.
        app()->instance('currentTenant', $rosaEterna);

        $visibleProductIds = Product::all()->pluck('tenant_id')->unique()->values()->all();

        $this->assertSame(
            [$rosaEterna->id],
            $visibleProductIds,
            'Under rosa-eterna scope, only rosa-eterna products should be visible',
        );

        app()->forgetInstance('currentTenant');

        // With tatiana in context, only tatiana's products should be visible.
        app()->instance('currentTenant', $tatiana);

        $visibleProductIds = Product::all()->pluck('tenant_id')->unique()->values()->all();

        $this->assertSame(
            [$tatiana->id],
            $visibleProductIds,
            'Under tatiana scope, only tatiana products should be visible',
        );

        app()->forgetInstance('currentTenant');
    }
}
