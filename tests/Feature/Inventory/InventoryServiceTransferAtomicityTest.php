<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class InventoryServiceTransferAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    private Tenant $tenant;

    private Branch $branchA;

    private Branch $branchB;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryService::class);
        $this->tenant = Tenant::factory()->create();
        $this->branchA = Branch::factory()->forTenant($this->tenant)->create();
        $this->branchB = Branch::factory()->forTenant($this->tenant)->create();

        app()->instance('currentTenant', $this->tenant);
        $product = Product::factory()->forTenant($this->tenant)->create();
        $this->variant = ProductVariant::factory()->forProduct($product)->create();

        app()->instance('currentTenant', $this->tenant);
    }

    public function test_transfer_returns_two_movements_with_same_reference_id(): void
    {
        $this->service->recordEntry($this->branchA, $this->variant, 50);

        [$exit, $entry] = $this->service->transferBetweenBranches(
            fromBranch: $this->branchA,
            toBranch: $this->branchB,
            variant: $this->variant,
            quantity: 20,
        );

        $this->assertSame($exit->reference_id, $entry->reference_id);
        $this->assertNotEmpty($exit->reference_id);
        $this->assertSame('Transfer', $exit->reference_type);
        $this->assertSame('Transfer', $entry->reference_type);
    }

    public function test_transfer_movements_have_correct_signs(): void
    {
        $this->service->recordEntry($this->branchA, $this->variant, 100);

        [$exit, $entry] = $this->service->transferBetweenBranches(
            fromBranch: $this->branchA,
            toBranch: $this->branchB,
            variant: $this->variant,
            quantity: 30,
        );

        // Exit from source is negative (stock left the branch)
        $this->assertSame(-30, $exit->quantity);
        $this->assertSame(InventoryMovement::TYPE_TRANSFER, $exit->type);
        $this->assertSame($this->branchA->id, $exit->branch_id);

        // Entry at destination is positive (stock arrived at the branch)
        $this->assertSame(30, $entry->quantity);
        $this->assertSame(InventoryMovement::TYPE_TRANSFER, $entry->type);
        $this->assertSame($this->branchB->id, $entry->branch_id);
    }

    public function test_transfer_failure_due_to_insufficient_stock_rolls_back_both_movements(): void
    {
        // Source has only 5 units, we try to transfer 10
        $this->service->recordEntry($this->branchA, $this->variant, 5);

        $movementsBefore = InventoryMovement::withoutGlobalScope(TenantScope::class)->count();

        try {
            $this->service->transferBetweenBranches(
                fromBranch: $this->branchA,
                toBranch: $this->branchB,
                variant: $this->variant,
                quantity: 10,
            );
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException) {
            // Expected — insufficient stock
        }

        // Transaction rolled back: no new movements created
        $movementsAfter = InventoryMovement::withoutGlobalScope(TenantScope::class)->count();
        $this->assertSame($movementsBefore, $movementsAfter);

        // Source quantity unchanged at 5
        $sourceInventory = $this->service->getStockSummary($this->branchA, $this->variant);
        $this->assertSame(5, $sourceInventory->quantity);

        // Destination still at 0
        $destInventory = $this->service->getStockSummary($this->branchB, $this->variant);
        $this->assertSame(0, $destInventory->quantity);
    }
}
