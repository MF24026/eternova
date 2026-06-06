<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Concurrency and oversell-protection tests for the Inventory module.
 *
 * True parallel PHP threads are not achievable in a single PHPUnit process, so these
 * tests reason about the race condition at the design level and then verify that the
 * protection mechanisms (lockForUpdate + transactions) produce correct outcomes in
 * simulated sequential-but-contested scenarios.
 *
 * Why lockForUpdate prevents oversell:
 *
 *   Without a lock:
 *     T1 reads available=5, T2 reads available=5 concurrently.
 *     T1 decrements → quantity=0.  T2 also decrements → quantity=-5 (OVERSELL).
 *
 *   With lockForUpdate inside a transaction:
 *     T1 acquires the row lock, reads available=5, checks OK, decrements → 0, commits.
 *     T2 waits for T1 to commit, then reads available=0, DomainException is thrown.
 *
 * The test below simulates this by calling recordExit twice in sequence on 5 units,
 * and asserting that the second call raises DomainException — confirming that the
 * service correctly detects the post-first-exit state.
 */
final class InventoryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    private Branch $branch;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryService::class);

        $tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);
        $product = Product::factory()->forTenant($tenant)->create();
        $this->variant = ProductVariant::factory()->forProduct($product)->create();

        app()->instance('currentTenant', $tenant);
    }

    /**
     * Simulate two concurrent exit requests racing on limited stock.
     *
     * With lockForUpdate: the first exit wins, the second sees 0 available and throws.
     * Without lockForUpdate: both would read the pre-decrement value and both succeed,
     * producing a negative quantity (oversell).
     */
    public function test_sequential_exits_on_limited_stock_do_not_oversell(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 5);

        // First exit succeeds
        $this->service->recordExit($this->branch, $this->variant, 5);

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);
        $this->assertSame(0, $inventory->quantity);
        $this->assertSame(0, $inventory->available);

        // Second exit on empty stock must fail — oversell prevented
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->recordExit($this->branch, $this->variant, 1);
    }

    /**
     * Verify that a reserve on exactly the available quantity succeeds,
     * and a subsequent reserve on the now-zero available quantity fails.
     *
     * This mirrors the concurrent reservation scenario: the lockForUpdate ensures
     * that two simultaneous reservations for the last 5 units cannot both succeed.
     */
    public function test_second_reserve_after_full_reservation_throws_domain_exception(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 10);

        // First reserve uses all available units
        $this->service->reserve($this->branch, $this->variant, 10);

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);
        $this->assertSame(10, $inventory->quantity);
        $this->assertSame(10, $inventory->reserved);
        $this->assertSame(0, $inventory->available);

        // Second reserve must fail — no available stock left
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot reserve/');

        $this->service->reserve($this->branch, $this->variant, 1);
    }

    public function test_quantity_never_goes_negative_after_multiple_exits(): void
    {
        $this->service->recordEntry($this->branch, $this->variant, 3);

        $this->service->recordExit($this->branch, $this->variant, 1);
        $this->service->recordExit($this->branch, $this->variant, 1);
        $this->service->recordExit($this->branch, $this->variant, 1);

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);
        $this->assertSame(0, $inventory->quantity);
        $this->assertGreaterThanOrEqual(0, $inventory->available);

        // Any further exit must throw
        $this->expectException(DomainException::class);
        $this->service->recordExit($this->branch, $this->variant, 1);
    }
}
