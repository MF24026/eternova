<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Events\StockLowDetected;
use App\Modules\Inventory\Listeners\NotifyOwnerOfLowStock;
use App\Modules\Inventory\Notifications\LowStockNotification;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class LowStockAlertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Use a dedicated database to avoid contention with parallel agents (#33, #36)
     * that also run migrate:fresh on the shared eternova_testing DB.
     *
     * Both this class and NotificationApiTest share testing_inv_s1e7 so migrate:fresh
     * runs only once per test suite execution (not once per test class).
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $dbName = 'testing_inv_s1e7';

        try {
            $this->app['db']->connection('mysql')
                ->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable) {
            $dbName = 'eternova_testing';
        }

        $this->app['config']->set('database.connections.mysql.database', $dbName);
        $this->app['db']->purge('mysql');

        RefreshDatabaseState::$migrated = false;
    }

    private InventoryService $service;

    private Tenant $tenant;

    private Branch $branch;

    private ProductVariant $variant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryService::class);
        $this->tenant = Tenant::factory()->create();
        $this->branch = Branch::factory()->forTenant($this->tenant)->create();

        app()->instance('currentTenant', $this->tenant);

        $product = Product::factory()->forTenant($this->tenant)->create();
        $this->variant = ProductVariant::factory()->forProduct($product)->create([
            'min_stock_alert' => 5,
        ]);
        $this->owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
    }

    public function test_event_fires_when_available_drops_below_threshold_via_record_exit(): void
    {
        Event::fake([StockLowDetected::class]);

        // Start with 10 units, threshold is 5. Exit 6 → available becomes 4 (below 5).
        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->recordExit($this->branch, $this->variant, 6);

        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event): bool {
            return $event->inventory->product_variant_id === $this->variant->id
                && $event->threshold === 5;
        });
    }

    public function test_event_does_not_fire_when_available_stays_above_threshold(): void
    {
        Event::fake([StockLowDetected::class]);

        // Start 10, exit 3 → available is 7, still above threshold of 5.
        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->recordExit($this->branch, $this->variant, 3);

        Event::assertNotDispatched(StockLowDetected::class);
    }

    public function test_event_fires_only_on_threshold_crossing_not_on_subsequent_decreases(): void
    {
        Event::fake([StockLowDetected::class]);

        // Start with 10. Exit 7 → available is 3 (crosses threshold). Event fires.
        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->recordExit($this->branch, $this->variant, 7);

        Event::assertDispatchedTimes(StockLowDetected::class, 1);

        // Now exit 1 more → available is 2 (already below threshold). Should NOT fire again.
        $this->service->recordExit($this->branch, $this->variant, 1);

        Event::assertDispatchedTimes(StockLowDetected::class, 1);
    }

    public function test_event_fires_when_negative_adjustment_crosses_threshold(): void
    {
        Event::fake([StockLowDetected::class]);

        // Start with 10. Adjust -7 → available 3 (crosses threshold of 5).
        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->recordAdjustment($this->branch, $this->variant, -7);

        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event): bool {
            return $event->threshold === 5;
        });
    }

    public function test_event_does_not_fire_on_positive_adjustment(): void
    {
        Event::fake([StockLowDetected::class]);

        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->recordAdjustment($this->branch, $this->variant, 5);

        Event::assertNotDispatched(StockLowDetected::class);
    }

    public function test_event_fires_for_source_branch_on_transfer(): void
    {
        Event::fake([StockLowDetected::class]);

        $destination = Branch::factory()->forTenant($this->tenant)->create();

        // Source has 10. Transfer 7 → source drops to 3 (below threshold 5).
        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->transferBetweenBranches(
            fromBranch: $this->branch,
            toBranch: $destination,
            variant: $this->variant,
            quantity: 7,
        );

        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event): bool {
            return $event->inventory->branch_id === $this->branch->id;
        });
    }

    public function test_listener_creates_notification_for_each_tenant_owner(): void
    {
        Queue::fake();
        Notification::fake();

        // Tenant has two owners.
        $ownerTwo = User::factory()->forTenant($this->tenant, role: 'owner')->create();

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);

        $listener = app(NotifyOwnerOfLowStock::class);
        $listener->handle(new StockLowDetected(inventory: $inventory, threshold: 5));

        Notification::assertSentTo(
            [$this->owner, $ownerTwo],
            LowStockNotification::class,
        );
    }

    public function test_notification_is_scoped_to_correct_tenant(): void
    {
        // Inline listener (synchronous) so we can inspect the DB row.
        Notification::fake();

        $tenantB = Tenant::factory()->create();
        $ownerB = User::factory()->forTenant($tenantB, role: 'owner')->create();

        // Inventory belongs to $this->tenant
        $inventory = $this->service->getStockSummary($this->branch, $this->variant);

        $listener = app(NotifyOwnerOfLowStock::class);
        $listener->handle(new StockLowDetected(inventory: $inventory, threshold: 5));

        // Tenant A's owner got notified.
        Notification::assertSentTo($this->owner, LowStockNotification::class);
        // Tenant B's owner did NOT get notified.
        Notification::assertNotSentTo($ownerB, LowStockNotification::class);
    }

    public function test_listener_sends_email_via_mail_fake(): void
    {
        Mail::fake();
        Notification::fake();

        $inventory = $this->service->getStockSummary($this->branch, $this->variant);

        $listener = app(NotifyOwnerOfLowStock::class);
        $listener->handle(new StockLowDetected(inventory: $inventory, threshold: 5));

        // The notification 'via' includes 'mail', so mail should be sent.
        Notification::assertSentTo($this->owner, LowStockNotification::class, function (LowStockNotification $notification): bool {
            return in_array('mail', $notification->via($this->owner), strict: true);
        });
    }

    public function test_uses_per_variant_threshold_when_min_stock_alert_is_set(): void
    {
        Event::fake([StockLowDetected::class]);

        // Override variant threshold to 3 (not the default 10 from config).
        $this->variant->update(['min_stock_alert' => 3]);
        $this->variant->refresh();

        // Start with 10. Exit 5 → available is 5. Should NOT fire (above variant threshold 3).
        $this->service->recordEntry($this->branch, $this->variant, 10);
        $this->service->recordExit($this->branch, $this->variant, 5);

        Event::assertNotDispatched(StockLowDetected::class);

        // Exit 3 more → available is 2. Now crosses variant threshold 3.
        $this->service->recordExit($this->branch, $this->variant, 3);

        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event): bool {
            return $event->threshold === 3;
        });
    }

    public function test_uses_global_config_default_when_variant_min_stock_alert_is_null(): void
    {
        Event::fake([StockLowDetected::class]);

        // Create a variant with NULL threshold — global config default (10) applies.
        app()->instance('currentTenant', $this->tenant);
        $product = Product::factory()->forTenant($this->tenant)->create();
        $variantNoAlert = ProductVariant::factory()->forProduct($product)->create([
            'min_stock_alert' => null,
        ]);

        // Start at 15. Exit 8 → available is 7. Still above default threshold 10? No, 7 < 10.
        $this->service->recordEntry($this->branch, $variantNoAlert, 15);
        $this->service->recordExit($this->branch, $variantNoAlert, 8);

        Event::assertDispatched(StockLowDetected::class, function (StockLowDetected $event) use ($variantNoAlert): bool {
            return $event->inventory->product_variant_id === $variantNoAlert->id
                && $event->threshold === config('inventory.default_min_stock', 10);
        });
    }
}
