<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Orchestrates POS order creation.
 *
 * The single most important invariant: every createFromPos() call is all-or-nothing.
 * If ANY product variant has insufficient stock, the entire order is rolled back —
 * no partial orders, no phantom rows in order_items.
 *
 * Tax handling (v1): tax_cents = 0 for all orders.
 * TODO(#80): read a per-tenant tax_rate from tenant Settings when that module ships.
 *
 * Cancel / restock (v1): cancel() sets status=cancelled only. It does NOT restock.
 * Rationale: auto-restock on cancel opens a window for fraudulent "cancel to get free
 * stock" flows. In v1, staff must create a manual inventory adjustment if needed.
 * TODO(#81): add an optional restock flag to cancel() when the Returns flow is built.
 */
final readonly class OrderService
{
    public function __construct(
        private InventoryService $inventoryService,
        private OrderRepositoryInterface $orders,
    ) {}

    /**
     * Create a POS sale atomically: order + items + inventory deduction.
     *
     * @param  list<array{product_variant_id: int, quantity: int}>  $items
     *
     * @throws DomainException When a variant has insufficient stock (rolls back all)
     * @throws InvalidArgumentException When $items is empty or quantities are invalid
     */
    public function createFromPos(
        Branch $branch,
        array $items,
        string $paymentMethod,
        ?Customer $customer = null,
        ?User $user = null,
        ?string $notes = null,
    ): Order {
        if (empty($items)) {
            throw new InvalidArgumentException('Cannot create a POS order with no items.');
        }

        $tenant = $this->resolveTenant($branch);

        return DB::transaction(function () use (
            $branch, $items, $paymentMethod, $customer, $user, $notes, $tenant
        ): Order {
            $orderNumber = $this->orders->nextOrderNumber($tenant);

            $resolvedItems = $this->resolveAndValidateItems($items, $branch);

            [$subtotalCents, $itemRows] = $this->buildItemRows($resolvedItems);

            // Tax v1: always zero.
            // TODO(#80): derive from tenant Settings tax_rate when that module ships.
            $taxCents = 0;
            $discountCents = 0;
            $totalCents = $subtotalCents + $taxCents - $discountCents;

            $order = $this->orders->create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer?->id,
                'order_number' => $orderNumber,
                'status' => 'preparing',
                'source' => 'pos',
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'discount_cents' => $discountCents,
                'total_cents' => $totalCents,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'notes' => $notes,
                'user_id' => $user?->id,
            ]);

            foreach ($itemRows as $row) {
                $order->items()->create([
                    'product_variant_id' => $row['variant']->id,
                    'quantity' => $row['quantity'],
                    'unit_price_cents' => $row['unit_price_cents'],
                    'total_cents' => $row['total_cents'],
                    'product_snapshot' => $row['snapshot'],
                ]);

                // Deduct stock. InventoryService::recordExit() uses lockForUpdate()
                // internally, so this is safe under concurrent POS sales.
                // DomainException here rolls back the entire transaction — the order
                // and all previously created items are discarded.
                $this->inventoryService->recordExit(
                    branch: $branch,
                    variant: $row['variant'],
                    quantity: $row['quantity'],
                    user: $user,
                    notes: "POS sale {$order->order_number}",
                    referenceType: 'Order',
                    referenceId: $order->id,
                );
            }

            Log::info('POS order created', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'total_cents' => $totalCents,
                'item_count' => count($itemRows),
                'user_id' => $user?->id,
            ]);

            return $order->load('items');
        });
    }

    /**
     * Cancel an order.
     *
     * v1: sets status to 'cancelled' only — does NOT restock inventory.
     * See class-level docblock for the restock decision rationale.
     *
     * @throws DomainException When the order is already cancelled or delivered
     */
    public function cancel(Order $order, ?User $user = null): void
    {
        if ($order->status === 'cancelled') {
            throw new DomainException(
                "Order #{$order->order_number} is already cancelled."
            );
        }

        if ($order->status === 'delivered') {
            throw new DomainException(
                "Cannot cancel order #{$order->order_number} — it has already been delivered. "
                .'Use a return/refund flow instead.'
            );
        }

        $order->update(['status' => 'cancelled']);

        Log::info('POS order cancelled', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'tenant_id' => $order->tenant_id,
            'cancelled_by' => $user?->id,
        ]);
    }

    /**
     * Load each ProductVariant within the current tenant scope and validate quantities.
     *
     * Runs withoutGlobalScope so that the lookup bypasses BelongsToTenant (ProductVariant
     * has no BelongsToTenant — it is scoped via its parent Product). We verify the product's
     * tenant_id matches instead.
     *
     * @param  list<array{product_variant_id: int, quantity: int}>  $items
     * @return list<array{variant: ProductVariant, quantity: int}>
     *
     * @throws DomainException When a variant does not exist or belongs to another tenant
     * @throws InvalidArgumentException When a quantity is not a positive integer
     */
    private function resolveAndValidateItems(array $items, Branch $branch): array
    {
        $resolved = [];

        foreach ($items as $item) {
            $variantId = (int) $item['product_variant_id'];
            $quantity = (int) $item['quantity'];

            if ($quantity <= 0) {
                throw new InvalidArgumentException(
                    "Item quantity must be greater than zero, got {$quantity} for variant #{$variantId}."
                );
            }

            // ProductVariant has no BelongsToTenant — always query with tenant join
            $variant = ProductVariant::withoutGlobalScope(TenantScope::class)
                ->with('product')
                ->whereHas('product', static function ($q) use ($branch): void {
                    $q->withoutGlobalScope(TenantScope::class)
                        ->where('products.tenant_id', $branch->tenant_id);
                })
                ->find($variantId);

            if ($variant === null) {
                throw new DomainException(
                    "Product variant #{$variantId} does not exist or does not belong to this tenant."
                );
            }

            $resolved[] = ['variant' => $variant, 'quantity' => $quantity];
        }

        return $resolved;
    }

    /**
     * Compute per-item price rows and the order subtotal.
     *
     * Variant price takes precedence over product base_price_cents. This matches
     * the Shopify model: variants can override the parent product price.
     *
     * @param  list<array{variant: ProductVariant, quantity: int}>  $resolvedItems
     * @return array{0: int, 1: list<array{variant: ProductVariant, quantity: int, unit_price_cents: int, total_cents: int, snapshot: array<string, mixed>}>}
     */
    private function buildItemRows(array $resolvedItems): array
    {
        $subtotalCents = 0;
        $rows = [];

        foreach ($resolvedItems as ['variant' => $variant, 'quantity' => $quantity]) {
            $unitPriceCents = $this->resolveUnitPrice($variant);
            $itemTotalCents = $unitPriceCents * $quantity;

            $subtotalCents += $itemTotalCents;

            $rows[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'total_cents' => $itemTotalCents,
                'snapshot' => $this->buildSnapshot($variant),
            ];
        }

        return [$subtotalCents, $rows];
    }

    /**
     * Variant price_cents overrides product base_price_cents.
     * If neither is set, the item is treated as free (0 cents) and a warning is logged.
     * This is intentional: a zero-priced item is detectable in reports rather than
     * throwing an error that blocks the sale.
     */
    private function resolveUnitPrice(ProductVariant $variant): int
    {
        if ($variant->price_cents !== null) {
            return $variant->price_cents;
        }

        /** @var Product|null $product */
        $product = $variant->product;

        if ($product !== null && $product->base_price_cents !== null) {
            return $product->base_price_cents;
        }

        Log::warning('ProductVariant has no price configured — charging zero cents', [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
        ]);

        return 0;
    }

    /**
     * Build the immutable product snapshot stored on each OrderItem.
     *
     * Captures name, variant_options, and sku at sale time. This is the receipt's
     * source of truth — immutable after creation.
     *
     * @return array{name: string, variant_options: array<string, string>, sku: string|null}
     */
    private function buildSnapshot(ProductVariant $variant): array
    {
        /** @var Product|null $product */
        $product = $variant->product;

        return [
            'name' => $product?->name ?? 'Unknown product',
            'variant_options' => is_array($variant->options) ? $variant->options : [],
            'sku' => $variant->sku,
        ];
    }

    /**
     * Resolve the Tenant model from a Branch.
     *
     * We read from the container-bound currentTenant when available (HTTP context).
     * Falling back to the branch's tenant relationship covers CLI/queue contexts.
     */
    private function resolveTenant(Branch $branch): Tenant
    {
        /** @var Tenant|null $current */
        $current = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($current instanceof Tenant) {
            return $current;
        }

        // Load via relationship when no tenant is bound (queue/CLI context)
        return $branch->tenant;
    }
}
