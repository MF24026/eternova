<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Central service for all inventory mutations.
 *
 * Atomicity rules:
 *  - Every operation that writes to branch_inventory uses lockForUpdate() to prevent
 *    race conditions under concurrent requests.
 *  - Every write (movement + inventory update) is wrapped in DB::transaction().
 *  - `reserve` / `unreserve` do NOT create movements — they adjust the `reserved`
 *    counter only. Actual stock changes happen when an order is completed.
 *  - Transfers are atomic: both the exit (source) and entry (destination) movements
 *    are written in a single transaction sharing the same reference_id UUID.
 */
final readonly class InventoryService
{
    public function recordEntry(
        Branch $branch,
        ProductVariant $variant,
        int $quantity,
        ?User $user = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "Entry quantity must be greater than zero, got {$quantity}."
            );
        }

        return DB::transaction(function () use (
            $branch, $variant, $quantity, $user, $notes, $referenceType, $referenceId
        ): InventoryMovement {
            $inventory = $this->lockInventoryRow($branch, $variant);
            $inventory->increment('quantity', $quantity);

            return InventoryMovement::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovement::TYPE_ENTRY,
                'quantity' => $quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);
        });
    }

    public function recordExit(
        Branch $branch,
        ProductVariant $variant,
        int $quantity,
        ?User $user = null,
        ?string $notes = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "Exit quantity must be greater than zero, got {$quantity}."
            );
        }

        return DB::transaction(function () use (
            $branch, $variant, $quantity, $user, $notes, $referenceType, $referenceId
        ): InventoryMovement {
            $inventory = $this->lockInventoryRow($branch, $variant);

            if ($inventory->available < $quantity) {
                throw new DomainException(
                    "Insufficient stock for variant #{$variant->id} at branch '{$branch->name}'. "
                    ."Available: {$inventory->available}, requested: {$quantity}."
                );
            }

            $inventory->decrement('quantity', $quantity);

            return InventoryMovement::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovement::TYPE_EXIT,
                'quantity' => -$quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);
        });
    }

    public function recordAdjustment(
        Branch $branch,
        ProductVariant $variant,
        int $delta,
        ?User $user = null,
        ?string $notes = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($branch, $variant, $delta, $user, $notes): InventoryMovement {
            $inventory = $this->lockInventoryRow($branch, $variant);

            // Adjustments can be positive or negative — but the resulting quantity
            // must not go below zero.
            $newQuantity = $inventory->quantity + $delta;

            if ($newQuantity < 0) {
                throw new DomainException(
                    "Adjustment of {$delta} would result in negative quantity "
                    ."for variant #{$variant->id} at branch '{$branch->name}'. "
                    ."Current quantity: {$inventory->quantity}."
                );
            }

            $inventory->update(['quantity' => $newQuantity]);

            return InventoryMovement::create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovement::TYPE_ADJUSTMENT,
                'quantity' => $delta,
                'reference_type' => 'ManualAdjustment',
                'reference_id' => null,
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);
        });
    }

    /**
     * Transfer stock between two branches of the SAME tenant atomically.
     *
     * Creates two movements sharing the same reference_id UUID:
     *   - Exit (negative quantity) at $fromBranch
     *   - Entry (positive quantity) at $toBranch
     *
     * @return array{0: InventoryMovement, 1: InventoryMovement}
     */
    public function transferBetweenBranches(
        Branch $fromBranch,
        Branch $toBranch,
        ProductVariant $variant,
        int $quantity,
        ?User $user = null,
        ?string $notes = null,
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "Transfer quantity must be greater than zero, got {$quantity}."
            );
        }

        if ($fromBranch->tenant_id !== $toBranch->tenant_id) {
            throw new DomainException(
                'Cannot transfer stock between branches of different tenants. '
                ."From tenant: {$fromBranch->tenant_id}, to tenant: {$toBranch->tenant_id}."
            );
        }

        return DB::transaction(function () use (
            $fromBranch, $toBranch, $variant, $quantity, $user, $notes
        ): array {
            // Lock both inventory rows in a consistent order (by branch id) to prevent
            // deadlocks when two concurrent transfers touch the same pair in opposite directions.
            $ids = [$fromBranch->id, $toBranch->id];
            sort($ids);

            $first = $ids[0] === $fromBranch->id ? $fromBranch : $toBranch;
            $second = $ids[0] === $fromBranch->id ? $toBranch : $fromBranch;

            $this->lockInventoryRow($first, $variant);
            $this->lockInventoryRow($second, $variant);

            // Re-fetch source after both locks are held
            $sourceInventory = $this->lockInventoryRow($fromBranch, $variant);

            if ($sourceInventory->available < $quantity) {
                throw new DomainException(
                    "Insufficient stock for transfer of variant #{$variant->id} "
                    ."from branch '{$fromBranch->name}'. "
                    ."Available: {$sourceInventory->available}, requested: {$quantity}."
                );
            }

            $transferId = (string) Str::uuid();

            $sourceInventory->decrement('quantity', $quantity);

            $exitMovement = InventoryMovement::create([
                'tenant_id' => $fromBranch->tenant_id,
                'branch_id' => $fromBranch->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovement::TYPE_TRANSFER,
                'quantity' => -$quantity,
                'reference_type' => 'Transfer',
                'reference_id' => $transferId,
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);

            $destinationInventory = $this->lockInventoryRow($toBranch, $variant);
            $destinationInventory->increment('quantity', $quantity);

            $entryMovement = InventoryMovement::create([
                'tenant_id' => $toBranch->tenant_id,
                'branch_id' => $toBranch->id,
                'product_variant_id' => $variant->id,
                'type' => InventoryMovement::TYPE_TRANSFER,
                'quantity' => $quantity,
                'reference_type' => 'Transfer',
                'reference_id' => $transferId,
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);

            return [$exitMovement, $entryMovement];
        });
    }

    /**
     * Increase the reserved counter without logging a movement.
     *
     * Reserved stock is held for pending orders. The physical quantity does not
     * change until the order is confirmed (recordExit) or cancelled (unreserve).
     */
    public function reserve(Branch $branch, ProductVariant $variant, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "Reserve quantity must be greater than zero, got {$quantity}."
            );
        }

        DB::transaction(function () use ($branch, $variant, $quantity): void {
            $inventory = $this->lockInventoryRow($branch, $variant);

            if ($inventory->available < $quantity) {
                throw new DomainException(
                    "Cannot reserve {$quantity} units of variant #{$variant->id} "
                    ."at branch '{$branch->name}'. "
                    ."Available: {$inventory->available}."
                );
            }

            $inventory->increment('reserved', $quantity);
        });
    }

    /**
     * Release previously reserved stock, making it available again.
     */
    public function unreserve(Branch $branch, ProductVariant $variant, int $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(
                "Unreserve quantity must be greater than zero, got {$quantity}."
            );
        }

        DB::transaction(function () use ($branch, $variant, $quantity): void {
            $inventory = $this->lockInventoryRow($branch, $variant);

            if ($inventory->reserved < $quantity) {
                throw new DomainException(
                    "Cannot unreserve {$quantity} units of variant #{$variant->id} "
                    ."at branch '{$branch->name}'. "
                    ."Currently reserved: {$inventory->reserved}."
                );
            }

            $inventory->decrement('reserved', $quantity);
        });
    }

    /**
     * Fetch the current stock summary, creating a zero-stock row if none exists yet.
     *
     * The firstOrCreate pattern is safe here: the UNIQUE constraint on
     * (tenant_id, branch_id, product_variant_id) means concurrent calls will either
     * both find the existing row or one will create it and the other will find it.
     */
    public function getStockSummary(Branch $branch, ProductVariant $variant): BranchInventory
    {
        return BranchInventory::firstOrCreate(
            [
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'product_variant_id' => $variant->id,
            ],
            [
                'quantity' => 0,
                'reserved' => 0,
            ]
        );
    }

    /**
     * Fetch (or create) the inventory row with a write lock.
     *
     * Called at the start of every mutating operation to prevent concurrent reads
     * from both seeing the same value and both decrementing past zero (oversell).
     */
    private function lockInventoryRow(Branch $branch, ProductVariant $variant): BranchInventory
    {
        $row = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)
            ->lockForUpdate()
            ->first();

        if ($row !== null) {
            return $row;
        }

        // Row does not exist yet — create it inside the transaction so the lock
        // applies immediately on the newly created row.
        return BranchInventory::create([
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
            'quantity' => 0,
            'reserved' => 0,
        ]);
    }
}
