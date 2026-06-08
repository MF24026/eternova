<?php

declare(strict_types=1);

namespace Database\Seeders\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Seeds a BranchInventory row for every ProductVariant at each tenant's main branch.
 *
 * Stock is set directly on the model (not via InventoryService/movements) because this
 * is the initial snapshot of current reality. InventoryMovementsSeeder will separately
 * create historical ledger rows that pre-date this snapshot — they are an audit trail,
 * not the source of truth for the current quantities.
 *
 * Edge cases deliberately created per tenant (for alert-testing purposes):
 *  - 3 variants with quantity = 0 (out of stock)
 *  - 3 variants with min_stock_alert = 10 and quantity = 5 (low stock, available <= threshold)
 *  - all other variants healthy (quantity 10–100)
 *
 * Idempotent: skips rows where (branch_id, product_variant_id) already exists.
 */
final class BranchInventorySeeder extends Seeder
{
    private const OUT_OF_STOCK_QUANTITY = 0;

    private const LOW_STOCK_QUANTITY = 5;

    private const LOW_STOCK_ALERT_LEVEL = 10;

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $branch = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();

        if ($branch === null) {
            $this->command->warn("BranchInventorySeeder: no main branch for tenant {$tenant->slug}, skipping.");

            return;
        }

        $variants = $this->variantsForTenant($tenant);

        if ($variants->isEmpty()) {
            $this->command->warn("BranchInventorySeeder: no variants for tenant {$tenant->slug}, skipping.");

            return;
        }

        $outOfStockCount = 0;
        $lowStockCount = 0;

        foreach ($variants as $index => $variant) {
            $alreadyExists = BranchInventory::withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->where('product_variant_id', $variant->id)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            [$quantity, $minStockAlert] = $this->resolveStockLevels(
                $index,
                $outOfStockCount,
                $lowStockCount,
            );

            if ($quantity === self::OUT_OF_STOCK_QUANTITY) {
                $outOfStockCount++;
            } elseif ($quantity === self::LOW_STOCK_QUANTITY) {
                $lowStockCount++;
                // Pin the alert threshold on the variant so the alert query fires.
                $variant->min_stock_alert = self::LOW_STOCK_ALERT_LEVEL;
                $variant->saveQuietly();
            }

            BranchInventory::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'reserved' => 0,
            ]);
        }
    }

    /**
     * Decide the stock quantity for the variant at the given index, ensuring the target
     * edge-case counts are met across the full variant list.
     *
     * Returns [quantity, min_stock_alert].
     *
     * @return array{int, int|null}
     */
    private function resolveStockLevels(
        int $index,
        int $outOfStockCount,
        int $lowStockCount,
    ): array {
        // First 3 variants in the list become "out of stock".
        if ($outOfStockCount < 3 && $index < 3) {
            return [self::OUT_OF_STOCK_QUANTITY, null];
        }

        // Next 3 variants become "low stock" (just below the alert threshold).
        if ($lowStockCount < 3 && $index < 6) {
            return [self::LOW_STOCK_QUANTITY, self::LOW_STOCK_ALERT_LEVEL];
        }

        // Remaining variants are healthy: 10–100 units.
        $quantity = random_int(10, 100);

        return [$quantity, null];
    }

    /**
     * Return all non-deleted variants belonging to the given tenant, ordered
     * deterministically so the edge-case slots are always the same variants.
     *
     * ProductVariant has no tenant_id (it belongs to Product which is tenant-scoped).
     * We join via products to get the right scope without relying on a global scope
     * that doesn't exist on ProductVariant.
     *
     * @return Collection<int, ProductVariant>
     */
    private function variantsForTenant(Tenant $tenant): Collection
    {
        return ProductVariant::withoutGlobalScopes()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.tenant_id', $tenant->id)
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->orderBy('product_variants.id')
            ->select('product_variants.*')
            ->get();
    }
}
