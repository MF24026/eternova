<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

final class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    private Tenant $tenant;

    private Branch $branch;

    private ProductVariant $variant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryService::class);
        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->forTenant($this->tenant)->create();

        // ProductVariant has no BelongsToTenant — requires a Product with a tenant
        app()->instance('currentTenant', $this->tenant);
        $product = Product::factory()->forTenant($this->tenant)->create();
        $this->variant = ProductVariant::factory()->forProduct($product)->create();
        $this->user = User::factory()->forTenant($this->tenant, role: 'owner')->create();

        // Keep tenant resolved for service calls throughout the test
        app()->instance('currentTenant', $this->tenant);
    }

    public function test_record_entry_increases_quantity_atomically(): void
    {
        $movement = $this->service->recordEntry(
            branch: $this->branch,
            variant: $this->variant,
            quantity: 10,
            user: $this->user,
        );

        $this->assertSame(InventoryMovement::TYPE_ENTRY, $movement->type);
        $this->assertSame(10, $movement->quantity);

        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $this->branch->id)
            ->where('product_variant_id', $this->variant->id)
            ->firstOrFail();

        $this->assertSame(10, $inventory->quantity);
        $this->assertSame(0, $inventory->reserved);
        $this->assertSame(10, $inventory->available);
    }

    public function test_record_exit_decreases_quantity_atomically(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 20);
        $movement = $this->service->recordExit(
            branch: $this->branch,
            variant: $this->variant,
            quantity: 8,
            user: $this->user,
        );

        $this->assertSame(InventoryMovement::TYPE_EXIT, $movement->type);
        $this->assertSame(-8, $movement->quantity);

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);
        $this->assertSame(12, $inventory->quantity);
        $this->assertSame(12, $inventory->available);
    }

    public function test_record_exit_throws_when_insufficient_stock(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 5);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->recordExit($this->branch, $this->variant, 10);
    }

    public function test_record_adjustment_can_be_positive_or_negative(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 50);

        $positiveAdj = $this->service->recordAdjustment(
            branch: $this->branch,
            variant: $this->variant,
            delta: 10,
            notes: 'Inventory count — found extra stock',
        );

        $this->assertSame(InventoryMovement::TYPE_ADJUSTMENT, $positiveAdj->type);
        $this->assertSame(10, $positiveAdj->quantity);
        $this->assertSame(60, $this->service->getStockSummary($this->branch, $this->variant)->quantity);

        $negativeAdj = $this->service->recordAdjustment($this->branch, $this->variant, -15);
        $this->assertSame(-15, $negativeAdj->quantity);
        $this->assertSame(45, $this->service->getStockSummary($this->branch, $this->variant)->quantity);
    }

    public function test_reserve_and_unreserve_affect_available_correctly(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 30);

        $this->service->reserve($this->branch, $this->variant, 10);

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);
        $this->assertSame(30, $inventory->quantity);
        $this->assertSame(10, $inventory->reserved);
        $this->assertSame(20, $inventory->available);

        // No movement row is created for reserve/unreserve
        $this->assertSame(1, InventoryMovement::withoutGlobalScope(TenantScope::class)->count());

        $this->service->unreserve($this->branch, $this->variant, 5);

        $inventory->refresh();
        $this->assertSame(30, $inventory->quantity);
        $this->assertSame(5, $inventory->reserved);
        $this->assertSame(25, $inventory->available);
    }

    public function test_transfer_between_same_tenant_branches_creates_two_atomic_movements_with_same_reference_id(): void
    {
        $branchB = Branch::factory()->forTenant($this->tenant)->create();

        $this->service->recordEntry($this->branch, $this->variant, 40);

        [$exitMovement, $entryMovement] = $this->service->transferBetweenBranches(
            fromBranch: $this->branch,
            toBranch: $branchB,
            variant: $this->variant,
            quantity: 15,
            user: $this->user,
        );

        // Both movements must share the same transfer reference_id
        $this->assertSame($exitMovement->reference_id, $entryMovement->reference_id);
        $this->assertNotNull($exitMovement->reference_id);

        // Exit is negative, entry is positive
        $this->assertSame(-15, $exitMovement->quantity);
        $this->assertSame(15, $entryMovement->quantity);

        // Stock levels updated correctly
        $sourceInventory = $this->service->getStockSummary($this->branch, $this->variant);
        $destInventory = $this->service->getStockSummary($branchB, $this->variant);

        $this->assertSame(25, $sourceInventory->quantity);
        $this->assertSame(15, $destInventory->quantity);
    }

    public function test_transfer_to_different_tenant_branch_throws_domain_exception(): void
    {
        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        $this->service->recordEntry($this->branch, $this->variant, 20);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/different tenants/');

        $this->service->transferBetweenBranches(
            fromBranch: $this->branch,
            toBranch: $branchB,
            variant: $this->variant,
            quantity: 5,
        );
    }

    public function test_all_movements_record_tenant_id_and_user_id(): void
    {
        $movement = $this->service->recordEntry(
            branch: $this->branch,
            variant: $this->variant,
            quantity: 10,
            user: $this->user,
        );

        $this->assertSame($this->tenant->id, $movement->tenant_id);
        $this->assertSame($this->user->id, $movement->user_id);
    }

    public function test_inventory_movements_cannot_be_updated_throws_logic_exception(): void
    {
        $movement = $this->service->recordEntry($this->branch, $this->variant, 5);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/append-only ledger/');

        $movement->update(['quantity' => 999]);
    }

    public function test_generated_available_column_equals_quantity_minus_reserved(): void
    {
        // Seed explicit values and read back the GENERATED column from the DB
        BranchInventory::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id,
            'product_variant_id' => $this->variant->id,
            'quantity' => 70,
            'reserved' => 25,
        ]);

        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $this->branch->id)
            ->where('product_variant_id', $this->variant->id)
            ->firstOrFail();

        // This verifies the MySQL GENERATED column, not application code
        $this->assertSame(45, $inventory->available);
    }

    public function test_record_exit_throws_when_quantity_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->recordExit($this->branch, $this->variant, 0);
    }

    public function test_record_entry_throws_when_quantity_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->recordEntry($this->branch, $this->variant, 0);
    }
}
