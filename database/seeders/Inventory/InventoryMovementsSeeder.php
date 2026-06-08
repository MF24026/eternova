<?php

declare(strict_types=1);

namespace Database\Seeders\Inventory;

use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ~40 historical inventory movements per tenant spread over the last 30 days.
 *
 * Design decision — raw inserts with backdated timestamps:
 *   These are historical ledger rows that pre-date the current stock snapshot set by
 *   BranchInventorySeeder. We do NOT call InventoryService::recordEntry/recordExit
 *   because that would recompute current BranchInventory quantities, overwriting the
 *   snapshot. The movements here are an audit trail for reporting/chart purposes only.
 *   Real branch_inventory quantities were already set to their correct current state.
 *
 * We use DB::table() rather than InventoryMovement::create() for two reasons:
 *   1. The InventoryMovement::updating() hook throws a LogicException on save() — using
 *      the model for bulk backdated inserts is fine for creates, but we want to be
 *      explicit that created_at is being overridden.
 *   2. BelongsToTenant::creating() would fire and require currentTenant in the container.
 *      DB::table() bypasses Eloquent entirely, keeping this seeder side-effect-free.
 *
 * Idempotent: skips the insert phase if movements already exist for the tenant's branch.
 */
final class InventoryMovementsSeeder extends Seeder
{
    private const MOVEMENTS_PER_TENANT = 40;

    private const LOOKBACK_DAYS = 30;

    private const MOVEMENT_TYPES = [
        InventoryMovement::TYPE_ENTRY,
        InventoryMovement::TYPE_ENTRY,    // entries are more frequent than exits
        InventoryMovement::TYPE_EXIT,
        InventoryMovement::TYPE_EXIT,
        InventoryMovement::TYPE_ADJUSTMENT,
    ];

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
            $this->command->warn("InventoryMovementsSeeder: no main branch for tenant {$tenant->slug}, skipping.");

            return;
        }

        // Idempotency check: if movements exist for this branch, do nothing.
        $alreadySeeded = DB::table('inventory_movements')
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->exists();

        if ($alreadySeeded) {
            $this->command->info("InventoryMovementsSeeder: movements already exist for {$tenant->slug}, skipping.");

            return;
        }

        $variantIds = $this->variantIdsForBranch($branch);

        if ($variantIds->isEmpty()) {
            $this->command->warn("InventoryMovementsSeeder: no variants for tenant {$tenant->slug}, skipping.");

            return;
        }

        $rows = [];

        for ($i = 0; $i < self::MOVEMENTS_PER_TENANT; $i++) {
            $variantId = $variantIds->random();
            $type = self::MOVEMENT_TYPES[array_rand(self::MOVEMENT_TYPES)];
            $quantity = $this->quantityForType($type);

            // Spread movements pseudo-randomly over the past 30 days.
            $daysBack = random_int(0, self::LOOKBACK_DAYS);
            $hoursBack = random_int(0, 23);
            $createdAt = Carbon::now()
                ->subDays($daysBack)
                ->subHours($hoursBack)
                ->toDateTimeString();

            $rows[] = [
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'product_variant_id' => $variantId,
                'type' => $type,
                'quantity' => $quantity,
                'reference_type' => null,
                'reference_id' => null,
                'notes' => null,
                'user_id' => null,
                'created_at' => $createdAt,
            ];
        }

        // Chunked insert to avoid hitting packet size limits on large datasets.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('inventory_movements')->insert($chunk);
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function variantIdsForBranch(Branch $branch): Collection
    {
        return BranchInventory::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->pluck('product_variant_id');
    }

    private function quantityForType(string $type): int
    {
        return match ($type) {
            InventoryMovement::TYPE_ENTRY => random_int(5, 50),
            InventoryMovement::TYPE_EXIT => -random_int(1, 20),
            InventoryMovement::TYPE_ADJUSTMENT => random_int(-10, 10),
            default => random_int(1, 30),
        };
    }
}
