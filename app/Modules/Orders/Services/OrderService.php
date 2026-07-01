<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Orders\Support\TaxCalculator;
use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orchestrates POS order creation and the order status state machine.
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
 *
 * State machine transitions:
 *   pending    → preparing | cancelled
 *   preparing  → ready     | cancelled
 *   ready      → dispatched | delivered | cancelled
 *   dispatched → delivered | cancelled
 *   delivered  → (terminal)
 *   cancelled  → (terminal)
 *
 * Every transition is recorded in order_status_history (append-only).
 */
final readonly class OrderService
{
    /**
     * Valid next statuses for each status.
     *
     * Terminal statuses (delivered, cancelled) map to empty arrays — no transitions
     * are allowed from them. Unknown statuses are rejected before this map is consulted.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending'    => ['preparing', 'cancelled'],
        'preparing'  => ['ready', 'cancelled'],
        'ready'      => ['dispatched', 'delivered', 'cancelled'],
        'dispatched' => ['delivered', 'cancelled'],
        'delivered'  => [],
        'cancelled'  => [],
    ];

    public function __construct(
        private InventoryService $inventoryService,
        private OrderRepositoryInterface $orders,
    ) {}

    /**
     * Create a POS sale atomically: order + items + inventory deduction + initial history row.
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

            // Tax: resolve the branch's tax settings and compute the breakdown.
            // Server is authoritative; the POS preview only mirrors this.
            $discountCents = 0; // POS has no discount UI yet
            $tax           = BranchSetting::resolvedGroup('tax', $branch->id);
            $breakdown     = (new TaxCalculator())->compute($subtotalCents, $discountCents, $tax);

            $order = $this->orders->create([
                'tenant_id'      => $tenant->id,
                'branch_id'      => $branch->id,
                'customer_id'    => $customer?->id,
                'order_number'   => $orderNumber,
                'tracking_token' => $this->generateTrackingToken(),
                'status'         => 'preparing',
                'source'         => 'pos',
                'subtotal_cents' => $breakdown->subtotalCents,
                'tax_cents'      => $breakdown->taxCents,
                'tax_rate_bps'   => $breakdown->rateBpsApplied,
                'discount_cents' => $breakdown->discountCents,
                'total_cents'    => $breakdown->totalCents,
                'payment_method' => $paymentMethod,
                'payment_status' => 'paid',
                'notes'          => $notes,
                'user_id'        => $user?->id,
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

            // Write the initial history entry. from_status=null signals this is not
            // a transition but the birth of the order into its initial status.
            OrderStatusHistory::create([
                'tenant_id' => $tenant->id,
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => 'preparing',
                'user_id' => $user?->id,
                'note' => 'POS sale created',
            ]);

            Log::info('POS order created', [
                'order_id'    => $order->id,
                'order_number' => $order->order_number,
                'tenant_id'   => $tenant->id,
                'branch_id'   => $branch->id,
                'total_cents' => $order->total_cents,
                'tax_rate_bps' => $order->tax_rate_bps,
                'item_count'  => count($itemRows),
                'user_id'     => $user?->id,
            ]);

            return $order->load('items');
        });
    }

    /**
     * Create an Order that represents the delivery of a custom reservation.
     *
     * This is the Orders-side half of the reservation-to-order conversion flow.
     * The method deliberately accepts ONLY Order-domain primitives — Branch, ints,
     * Customer, User, strings — so the Orders module has zero knowledge of the
     * Reservations module. The calling orchestrator (ReservationService) passes
     * the derived values across the module boundary.
     *
     * Key differences from createFromPos():
     *   - No OrderItems: a custom reservation has no catalog ProductVariant, and
     *     OrderItem.product_variant_id is a NOT NULL FK. The order is a financial
     *     record of the completed delivery, not a line-item receipt.
     *   - No inventory deduction: the materials were consumed when the reservation
     *     was fulfilled, not at conversion time.
     *   - Status starts as 'delivered': the reservation is handed over at conversion,
     *     so the order is already in its terminal delivered state.
     *   - source = 'reservation': distinguishes this in reports from POS / catalog sales.
     *   - payment_method = null: the actual payment(s) were recorded on the reservation
     *     side; the order only carries the derived payment_status.
     *
     * @throws InvalidArgumentException When $paymentStatus is not pending|partial|paid
     */
    public function createFromReservation(
        Branch $branch,
        int $totalCents,
        ?Customer $customer,
        string $paymentStatus,
        ?User $user = null,
        ?string $notes = null,
    ): Order {
        $validPaymentStatuses = ['pending', 'partial', 'paid'];

        if (! in_array($paymentStatus, $validPaymentStatuses, strict: true)) {
            throw new InvalidArgumentException(
                "Invalid payment_status '{$paymentStatus}'. Must be one of: "
                .implode(', ', $validPaymentStatuses).'.'
            );
        }

        $tenant = $this->resolveTenant($branch);

        return DB::transaction(function () use (
            $branch, $totalCents, $customer, $paymentStatus, $user, $notes, $tenant
        ): Order {
            $orderNumber = $this->orders->nextOrderNumber($tenant);

            // Tax v1: always zero for reservation-derived orders.
            // TODO(#80): derive from tenant Settings tax_rate when that module ships.
            $order = $this->orders->create([
                'tenant_id'      => $tenant->id,
                'branch_id'      => $branch->id,
                'customer_id'    => $customer?->id,
                'order_number'   => $orderNumber,
                'tracking_token' => $this->generateTrackingToken(),
                'status'         => 'delivered',
                'source'         => 'reservation',
                'subtotal_cents' => $totalCents,
                'tax_cents'      => 0,
                'discount_cents' => 0,
                'total_cents'    => $totalCents,
                'payment_method' => null,
                'payment_status' => $paymentStatus,
                'notes'          => $notes,
                'user_id'        => $user?->id,
            ]);

            // Write the initial history entry. from_status=null signals this is the
            // birth of the order into its initial status, not a transition from prior state.
            OrderStatusHistory::create([
                'tenant_id'   => $tenant->id,
                'order_id'    => $order->id,
                'from_status' => null,
                'to_status'   => 'delivered',
                'user_id'     => $user?->id,
                'note'        => 'Created from reservation',
            ]);

            Log::info('Reservation order created', [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'tenant_id'      => $tenant->id,
                'branch_id'      => $branch->id,
                'total_cents'    => $totalCents,
                'payment_status' => $paymentStatus,
                'user_id'        => $user?->id,
            ]);

            return $order;
        });
    }

    /**
     * Create an Order that represents the acceptance of a quotation.
     *
     * This is the Orders-side half of the quotation-to-order conversion flow.
     * The method accepts ONLY Order-domain primitives — Branch, ints, Customer,
     * User, strings — so the Orders module has zero knowledge of the Quotations
     * module. The calling orchestrator (QuotationService) passes the derived
     * values across the module boundary.
     *
     * Key differences from createFromReservation():
     *   - subtotal_cents, tax_cents, discount_cents are passed through from the
     *     quotation's own breakdown (a quote already has a proper tax breakdown,
     *     unlike reservations which always pass tax_cents = 0).
     *   - Status starts as 'pending': an accepted quote is fresh work to fulfill —
     *     NOT 'delivered' like reservations (which are already handed over).
     *   - payment_status = 'pending': nothing has been paid on a quote.
     *   - source = 'quotation': distinguishes this in reports.
     *   - No OrderItems: quotation lines reference products/free-text, not
     *     ProductVariants. OrderItem.product_variant_id is NOT NULL, so itemless
     *     financial-summary orders are the correct representation here.
     *   - No inventory deduction: the work has not been started yet.
     */
    public function createFromQuotation(
        Branch $branch,
        int $subtotalCents,
        int $taxCents,
        int $discountCents,
        int $totalCents,
        ?Customer $customer,
        ?User $user = null,
        ?string $notes = null,
    ): Order {
        $tenant = $this->resolveTenant($branch);

        return DB::transaction(function () use (
            $branch, $subtotalCents, $taxCents, $discountCents, $totalCents,
            $customer, $user, $notes, $tenant
        ): Order {
            $orderNumber = $this->orders->nextOrderNumber($tenant);

            $order = $this->orders->create([
                'tenant_id'      => $tenant->id,
                'branch_id'      => $branch->id,
                'customer_id'    => $customer?->id,
                'order_number'   => $orderNumber,
                'tracking_token' => $this->generateTrackingToken(),
                'status'         => 'pending',
                'source'         => 'quotation',
                'subtotal_cents' => $subtotalCents,
                'tax_cents'      => $taxCents,
                'discount_cents' => $discountCents,
                'total_cents'    => $totalCents,
                'payment_method' => null,
                'payment_status' => 'pending',
                'notes'          => $notes,
                'user_id'        => $user?->id,
            ]);

            // Write the initial history entry. from_status=null signals this is the
            // birth of the order into its initial status, not a transition from prior state.
            OrderStatusHistory::create([
                'tenant_id'   => $tenant->id,
                'order_id'    => $order->id,
                'from_status' => null,
                'to_status'   => 'pending',
                'user_id'     => $user?->id,
                'note'        => 'Created from quotation',
            ]);

            Log::info('Quotation order created', [
                'order_id'       => $order->id,
                'order_number'   => $order->order_number,
                'tenant_id'      => $tenant->id,
                'branch_id'      => $branch->id,
                'subtotal_cents' => $subtotalCents,
                'tax_cents'      => $taxCents,
                'discount_cents' => $discountCents,
                'total_cents'    => $totalCents,
                'user_id'        => $user?->id,
            ]);

            return $order;
        });
    }

    /**
     * Cancel an order.
     *
     * v1: sets status to 'cancelled' only — does NOT restock inventory.
     * See class-level docblock for the restock decision rationale.
     *
     * The friendly pre-checks here produce targeted error messages before
     * delegating to transitionTo(). This preserves the exact exception
     * messages that existing callers and tests depend on.
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

        $this->transitionTo(order: $order, toStatus: 'cancelled', actor: $user);

        Log::info('Order cancelled', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'tenant_id' => $order->tenant_id,
            'cancelled_by' => $user?->id,
        ]);
    }

    /**
     * Advance the order to a new status, recording the transition in the history.
     *
     * Validates the transition against the state machine map. Throws DomainException
     * for any invalid move — including unknown status values and terminal states.
     *
     * The DB write (status update + history row) is wrapped in a transaction so that
     * a partial write can never leave the order in an inconsistent state.
     *
     * @throws DomainException When the transition is not allowed by the state machine
     */
    public function transitionTo(
        Order $order,
        string $toStatus,
        ?User $actor = null,
        ?string $note = null,
    ): Order {
        $fromStatus = $order->status;

        $this->assertTransitionAllowed(order: $order, toStatus: $toStatus);

        DB::transaction(function () use ($order, $fromStatus, $toStatus, $actor, $note): void {
            $order->update(['status' => $toStatus]);

            OrderStatusHistory::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'user_id' => $actor?->id,
                'note' => $note,
            ]);
        });

        Log::info('Order status transitioned', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'tenant_id' => $order->tenant_id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->id,
        ]);

        return $order->fresh()->load('statusHistory');
    }

    /**
     * Return the list of valid next statuses for the order's current status.
     *
     * Returns an empty array for terminal statuses (delivered, cancelled).
     * The frontend uses this to render only the valid action buttons.
     *
     * @return list<string>
     */
    public function allowedTransitions(Order $order): array
    {
        return self::TRANSITIONS[$order->status] ?? [];
    }

    /**
     * Assign (or un-assign) a staff member to an order.
     *
     * Passing null for $assignee clears the assignment — this is a valid operation,
     * not an error condition. The caller decides whether "unassigned" is allowed by
     * business rules; this method only enforces the cross-tenant invariant.
     *
     * Timeline entry design: we write an OrderStatusHistory row where
     * from_status === to_status === $order->status. The status does not change, but the
     * assignment event is recorded so the order's full activity timeline is visible.
     * The frontend can detect assignment-only events by checking from_status === to_status.
     * Alternative (a nullable note-only entry with null statuses) was rejected because
     * it would require widening the not-null constraint on to_status in the schema and
     * would make the history model semantically inconsistent.
     *
     * @throws DomainException When $assignee belongs to a different tenant than the order
     */
    public function assign(Order $order, ?User $assignee, ?User $actor = null): Order
    {
        if ($assignee !== null) {
            $this->assertAssigneeBelongsToOrderTenant(order: $order, assignee: $assignee);
        }

        $note = $assignee !== null
            ? "Assigned to {$assignee->name}"
            : 'Unassigned';

        DB::transaction(function () use ($order, $assignee, $actor, $note): void {
            $order->update(['assigned_to' => $assignee?->id]);

            OrderStatusHistory::create([
                'tenant_id' => $order->tenant_id,
                'order_id' => $order->id,
                'from_status' => $order->status,
                'to_status' => $order->status,
                'user_id' => $actor?->id,
                'note' => $note,
            ]);
        });

        Log::info('Order assignment updated', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'tenant_id' => $order->tenant_id,
            'assigned_to' => $assignee?->id,
            'actor_id' => $actor?->id,
        ]);

        return $order->fresh()->load('assignee');
    }

    /**
     * Assert that $assignee is a member of the same tenant as the order.
     *
     * Users are globally scoped (no tenant_id column on the users table). Membership
     * is tracked in the tenant_users pivot. User::belongsToTenant() queries that pivot.
     *
     * @throws DomainException When the user has no membership in the order's tenant
     */
    private function assertAssigneeBelongsToOrderTenant(Order $order, User $assignee): void
    {
        $tenant = Tenant::find($order->tenant_id);

        if ($tenant === null || ! $assignee->belongsToTenant($tenant)) {
            throw new DomainException(
                "Cannot assign order #{$order->order_number} to a user from another tenant."
            );
        }
    }

    /**
     * Assert that $toStatus is a valid next step from the order's current status.
     *
     * @throws DomainException When $toStatus is unknown or not reachable from current status
     */
    private function assertTransitionAllowed(Order $order, string $toStatus): void
    {
        if (! array_key_exists($toStatus, self::TRANSITIONS)) {
            throw new DomainException(
                "'{$toStatus}' is not a recognised order status."
            );
        }

        $allowed = self::TRANSITIONS[$order->status] ?? null;

        // Current status is not in the map — should not happen with clean data, but be safe
        if ($allowed === null) {
            throw new DomainException(
                "Order #{$order->order_number} has an unrecognised status '{$order->status}'."
            );
        }

        if (! in_array($toStatus, $allowed, strict: true)) {
            throw new DomainException(
                "Cannot transition order #{$order->order_number} from '{$order->status}' to '{$toStatus}'."
            );
        }
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

    /**
     * Generate a 32-char url-safe tracking token unique across all orders.
     *
     * Collision probability across millions of orders is astronomically low
     * (192 bits of entropy). The while loop is a cheap defensive guard — in
     * practice it never iterates more than once.
     */
    private function generateTrackingToken(): string
    {
        do {
            $token = Str::random(32);
        } while (Order::withoutGlobalScopes()->where('tracking_token', $token)->exists());

        return $token;
    }
}
