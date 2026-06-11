<?php

declare(strict_types=1);

namespace Database\Seeders\Orders;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds ~15 realistic orders per demo tenant spanning all six statuses,
 * with status-history timelines, order items, and tracking tokens.
 *
 * Design decisions — consistent with InventoryMovementsSeeder philosophy:
 *
 *   Raw DB inserts with backdated timestamps:
 *     These are historical demo orders. We do NOT call OrderService::createFromPos()
 *     because that would deduct inventory and fight the BranchInventorySeeder snapshot.
 *     We also do NOT call OrderRepository::nextOrderNumber() because we want to
 *     backdate created_at freely. Instead we assign order numbers directly and then
 *     advance order_sequences at the end so real orders continue cleanly.
 *
 *   DB::table() over Eloquent:
 *     Bypasses BelongsToTenant::creating() (which requires currentTenant in container),
 *     OrderObserver side-effects, and timestamps() restrictions on created_at.
 *
 *   Tracking token uniqueness:
 *     All tokens for a seeder run are generated into a local Set before any insert,
 *     guaranteeing intra-run uniqueness without a per-insert DB round-trip. The orders
 *     table has a UNIQUE index as the last line of defence.
 *
 *   order_sequences advancement:
 *     After inserting seeded orders, we upsert the order_sequences row for each tenant
 *     so last_sequence >= the highest seeded number. This ensures the first real order
 *     continues from the next number and never collides with a seeded order_number.
 *
 *   Customers:
 *     The demo dataset has no pre-seeded customers. The seeder inserts a small set of
 *     demo customers inline (idempotent via firstOrCreate logic on tenant+email) so
 *     some orders reference a named customer while others are walk-in (customer_id null).
 *
 *   Idempotency:
 *     Each tenant is skipped entirely if it already has orders in the table.
 */
final class OrdersSeeder extends Seeder
{
    /** Target orders per tenant. Distribution across statuses is defined in ORDER_STATUS_PLAN. */
    private const ORDERS_PER_TENANT = 15;

    /**
     * How far back in days to backdate the oldest orders.
     * delivered/cancelled orders are oldest; pending/preparing are most recent.
     */
    private const LOOKBACK_DAYS = 21;

    /** Valid order statuses (mirrors the orders table enum). */
    private const STATUSES = ['pending', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled'];

    /**
     * Status distribution plan for the 15 orders per tenant.
     * Realistic bias: more delivered/preparing, fewer cancelled.
     *
     * Count: 1 pending, 3 preparing, 2 ready, 2 dispatched, 5 delivered, 2 cancelled = 15
     *
     * @var list<string>
     */
    private const STATUS_PLAN = [
        'pending',
        'preparing',
        'preparing',
        'preparing',
        'ready',
        'ready',
        'dispatched',
        'dispatched',
        'delivered',
        'delivered',
        'delivered',
        'delivered',
        'delivered',
        'cancelled',
        'cancelled',
    ];

    /**
     * For each status, how many days back to place the order's created_at.
     * Older orders are terminal (delivered/cancelled); newer ones are active.
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const CREATED_AT_RANGE = [
        'delivered'  => ['min' => 10, 'max' => 21],
        'cancelled'  => ['min' =>  8, 'max' => 18],
        'dispatched' => ['min' =>  3, 'max' =>  8],
        'ready'      => ['min' =>  2, 'max' =>  5],
        'preparing'  => ['min' =>  1, 'max' =>  3],
        'pending'    => ['min' =>  0, 'max' =>  1],
    ];

    /**
     * Valid state machine transitions (what comes after each status on the way to the terminal).
     *
     * These paths mirror the state machine documented in sprint-4-plan.md:
     *   pending → preparing → ready → dispatched → delivered
     *   ready → delivered  (store pickup shortcut)
     *   any non-terminal → cancelled
     *
     * @var array<string, list<string>>
     */
    private const TRANSITION_PATH = [
        'pending'    => ['preparing'],
        'preparing'  => ['ready'],
        'ready'      => ['dispatched', 'delivered'],   // dispatched preferred for most, delivered for pickup
        'dispatched' => ['delivered'],
        'delivered'  => [],
        'cancelled'  => [],
    ];

    /** Spanish notes for the initial creation history row. */
    private const CREATION_NOTES = [
        'Pedido creado desde POS',
        'Pedido creado',
        'Pedido registrado',
        'Pedido ingresado por mostrador',
        'Pedido nuevo',
    ];

    /** Spanish notes for intermediate transition history rows. */
    private const TRANSITION_NOTES = [
        'En preparacion',
        'Listo para despacho',
        'Asignado al repartidor',
        'Entregado al cliente',
        null,
        null,
        null,   // most transitions have no note (realistic)
    ];

    /**
     * Collected tracking tokens for this seeder run.
     * We pre-generate all tokens into this set before inserting to guarantee uniqueness
     * without a per-row DB query.
     *
     * @var array<string, true>
     */
    private array $issuedTokens = [];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        // Idempotency: skip if any orders already exist for this tenant.
        $alreadySeeded = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($alreadySeeded) {
            $this->command->info("OrdersSeeder: orders already exist for {$tenant->slug}, skipping.");

            return;
        }

        $branch = $this->resolveMainBranch($tenant);

        if ($branch === null) {
            $this->command->warn("OrdersSeeder: no main branch for tenant {$tenant->slug}, skipping.");

            return;
        }

        $variantRows = $this->resolveVariants($tenant);

        if ($variantRows->isEmpty()) {
            $this->command->warn("OrdersSeeder: no product variants for tenant {$tenant->slug}, skipping.");

            return;
        }

        $staffUserIds = $this->resolveStaffUserIds($tenant);
        $customerIds  = $this->provisionDemoCustomers($tenant);
        $userIdForCreation = $staffUserIds->first();  // staff member who "took" the order

        $year = now()->year;
        $highestSeq = 0;

        foreach (self::STATUS_PLAN as $seqIndex => $finalStatus) {
            $seq = $seqIndex + 1;

            if ($seq > $highestSeq) {
                $highestSeq = $seq;
            }

            $orderNumber = sprintf('CC-%d-%04d', $year, $seq);
            $orderId     = Str::ulid()->toString();
            $trackingToken = $this->generateUniqueToken();

            // Source follows the business rule: 'pending' orders come from catalog/reservation
            // (not POS — POS transactions are synchronous and start at 'preparing' immediately).
            // Non-pending orders default to pos, with ~1-in-5 being catalog.
            $source = match (true) {
                $finalStatus === 'pending' => 'catalog',
                $seq % 5 === 0            => 'catalog',
                default                   => 'pos',
            };

            // Assign ~half of active (preparing/ready/dispatched) orders to a staff member.
            $isActive = in_array($finalStatus, ['preparing', 'ready', 'dispatched'], true);
            $assignedTo = ($isActive && $seq % 2 === 0 && $staffUserIds->isNotEmpty())
                ? $staffUserIds->random()
                : null;

            // customer_id: alternate between registered customers and walk-in.
            $customerId = ($seq % 3 !== 0 && $customerIds->isNotEmpty())
                ? $customerIds->random()
                : null;

            // Payment state: terminal/near-terminal orders are paid; pending is not.
            [$paymentStatus, $paymentMethod] = $this->resolvePayment($finalStatus);

            // Timestamps: backdate created_at, updated_at >= created_at.
            $range     = self::CREATED_AT_RANGE[$finalStatus];
            $daysBack  = random_int($range['min'], $range['max']);
            $hoursBack = random_int(0, 23);
            $createdAt = Carbon::now()->subDays($daysBack)->subHours($hoursBack);
            $updatedAt = $this->resolveUpdatedAt($createdAt, $finalStatus);

            // Build order items (1–4 line items).
            $itemCount = random_int(1, min(4, $variantRows->count()));
            $selectedVariants = $variantRows->random($itemCount);
            [$subtotalCents, $itemRows] = $this->buildOrderItems($orderId, $selectedVariants);

            // Insert the order row.
            DB::table('orders')->insert([
                'id'              => $orderId,
                'tenant_id'       => $tenant->id,
                'branch_id'       => $branch->id,
                'customer_id'     => $customerId,
                'order_number'    => $orderNumber,
                'tracking_token'  => $trackingToken,
                'status'          => $finalStatus,
                'source'          => $source,
                'subtotal_cents'  => $subtotalCents,
                'tax_cents'       => 0,
                'discount_cents'  => 0,
                'total_cents'     => $subtotalCents,
                'payment_method'  => $paymentMethod,
                'payment_status'  => $paymentStatus,
                'notes'           => null,
                'user_id'         => $userIdForCreation,
                'assigned_to'     => $assignedTo,
                'created_at'      => $createdAt->toDateTimeString(),
                'updated_at'      => $updatedAt->toDateTimeString(),
                'deleted_at'      => null,
            ]);

            // Insert order items.
            DB::table('order_items')->insert($itemRows);

            // Insert coherent status history timeline.
            $historyRows = $this->buildStatusHistory(
                orderId: $orderId,
                tenantId: $tenant->id,
                finalStatus: $finalStatus,
                source: $source,
                orderCreatedAt: $createdAt,
                staffUserId: $userIdForCreation,
            );

            DB::table('order_status_history')->insert($historyRows);
        }

        // Advance the order_sequences row so real orders continue from seq+1.
        DB::table('order_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'year' => $year],
            ['last_sequence' => $highestSeq, 'updated_at' => now()->toDateTimeString(), 'created_at' => now()->toDateTimeString()],
        );

        $this->command->info(
            "OrdersSeeder: {$tenant->slug} — {$highestSeq} orders seeded (sequence advanced to {$highestSeq}).",
        );
    }

    // -------------------------------------------------------------------------
    // Status-history timeline builder
    // -------------------------------------------------------------------------

    /**
     * Build a coherent chain of order_status_history rows that walks from creation
     * to the final status, with ascending backdated created_at timestamps.
     *
     * Rules:
     *  - First row: from_status=null, to_status = initial status (preparing for pos, pending for catalog).
     *  - One row per real transition until final status is reached.
     *  - The last row's to_status must equal $finalStatus.
     *  - No from==to (assignment-only) rows — only real status changes.
     *
     * @param  int|null  $staffUserId
     * @return list<array<string, mixed>>
     */
    private function buildStatusHistory(
        string $orderId,
        string $tenantId,
        string $finalStatus,
        string $source,
        Carbon $orderCreatedAt,
        ?int $staffUserId,
    ): array {
        // POS orders start at 'preparing'; catalog/reservation orders start at 'pending'.
        $initialStatus = ($source === 'pos') ? 'preparing' : 'pending';

        // Build the transition chain: from initialStatus to finalStatus.
        $statusChain = $this->buildStatusChain($initialStatus, $finalStatus);

        $rows         = [];
        $currentTime  = $orderCreatedAt->copy();
        $timeSpanSecs = max(0, (int) $orderCreatedAt->diffInSeconds(now()));
        $stepSecs     = $timeSpanSecs > 0 ? (int) ($timeSpanSecs / max(count($statusChain), 1)) : 3600;

        // First row: creation entry (from_status = null).
        $rows[] = [
            'tenant_id'   => $tenantId,
            'order_id'    => $orderId,
            'from_status' => null,
            'to_status'   => $statusChain[0],
            'user_id'     => $staffUserId,
            'note'        => self::CREATION_NOTES[array_rand(self::CREATION_NOTES)],
            'created_at'  => $currentTime->toDateTimeString(),
        ];

        // Subsequent transition rows.
        for ($i = 1; $i < count($statusChain); $i++) {
            // Advance time by a proportional step plus some jitter.
            $advance = $stepSecs + random_int(0, (int) max($stepSecs * 0.5, 600));
            $currentTime = $currentTime->copy()->addSeconds($advance);

            // Clamp to now so we never have future timestamps.
            if ($currentTime->isFuture()) {
                $currentTime = Carbon::now()->subMinutes(random_int(1, 30));
            }

            $note = self::TRANSITION_NOTES[array_rand(self::TRANSITION_NOTES)];

            $rows[] = [
                'tenant_id'   => $tenantId,
                'order_id'    => $orderId,
                'from_status' => $statusChain[$i - 1],
                'to_status'   => $statusChain[$i],
                'user_id'     => $staffUserId,
                'note'        => $note,
                'created_at'  => $currentTime->toDateTimeString(),
            ];
        }

        return $rows;
    }

    /**
     * Build the ordered list of statuses from $initial through to $final.
     *
     * The canonical path follows the state machine transitions defined in
     * TRANSITION_PATH. For cancelled orders, the order reaches $final directly
     * from the last non-terminal state before cancellation.
     *
     * @return non-empty-list<string>
     */
    private function buildStatusChain(string $initial, string $final): array
    {
        if ($initial === $final) {
            return [$initial];
        }

        $chain = [$initial];
        $current = $initial;

        // Walk the transition path until we reach the final status.
        while ($current !== $final) {
            $next = $this->nextStatusTowards($current, $final);

            if ($next === null) {
                // Cannot reach $final from $current — stop here.
                // This handles edge cases like catalog→preparing without going back to pending.
                break;
            }

            $chain[] = $next;
            $current = $next;
        }

        return $chain;
    }

    /**
     * Return the next status step along the path from $current towards $target.
     *
     * For cancelled targets, we cancel directly from $current (one hop).
     * For non-cancelled targets, we follow TRANSITION_PATH.
     */
    private function nextStatusTowards(string $current, string $target): ?string
    {
        if ($target === 'cancelled') {
            return 'cancelled';
        }

        $transitions = self::TRANSITION_PATH[$current] ?? [];

        if (empty($transitions)) {
            return null;
        }

        // If the target is directly reachable from current, take it.
        if (in_array($target, $transitions, true)) {
            return $target;
        }

        // Otherwise, take the first transition and continue walking.
        // For ready→dispatched→delivered vs ready→delivered (pickup), we always
        // prefer dispatched unless the target is delivered with no dispatched in path.
        return $transitions[0];
    }

    // -------------------------------------------------------------------------
    // Order item builder
    // -------------------------------------------------------------------------

    /**
     * Build order_items rows for the given order and selected variants.
     *
     * @param  Collection<int, object>  $selectedVariants
     * @return array{int, list<array<string, mixed>>}  [subtotal_cents, itemRows]
     */
    private function buildOrderItems(string $orderId, Collection $selectedVariants): array
    {
        $subtotalCents = 0;
        $itemRows      = [];

        foreach ($selectedVariants as $variant) {
            $quantity      = random_int(1, 3);
            $unitPrice     = (int) $variant->price_cents;
            $totalCents    = $unitPrice * $quantity;
            $subtotalCents += $totalCents;

            // product_snapshot: name (product name), variant_options (array), sku.
            $options = is_string($variant->options)
                ? json_decode($variant->options, true)
                : (array) $variant->options;

            $snapshot = json_encode([
                'name'            => $variant->product_name,
                'variant_options' => $options ?? [],
                'sku'             => $variant->sku,
            ]);

            $itemRows[] = [
                'order_id'           => $orderId,
                'product_variant_id' => $variant->id,
                'quantity'           => $quantity,
                'unit_price_cents'   => $unitPrice,
                'total_cents'        => $totalCents,
                'product_snapshot'   => $snapshot,
                'created_at'         => now()->toDateTimeString(),
                'updated_at'         => now()->toDateTimeString(),
            ];
        }

        return [$subtotalCents, $itemRows];
    }

    // -------------------------------------------------------------------------
    // Payment resolution
    // -------------------------------------------------------------------------

    /**
     * @return array{string, string|null}  [payment_status, payment_method|null]
     */
    private function resolvePayment(string $status): array
    {
        return match ($status) {
            'delivered', 'dispatched', 'ready' => ['paid', $this->randomPaymentMethod()],
            'preparing'                         => [random_int(0, 1) ? 'paid' : 'partial', $this->randomPaymentMethod()],
            'cancelled'                         => ['pending', null],
            default                             => ['pending', null],  // pending
        };
    }

    private function randomPaymentMethod(): string
    {
        // Realistic distribution: cash-heavy florist/gift shop context.
        return match (random_int(1, 10)) {
            1, 2, 3, 4, 5, 6 => 'cash',
            7, 8               => 'transfer',
            9                  => 'card',
            default            => 'other',
        };
    }

    // -------------------------------------------------------------------------
    // Timestamp helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a realistic updated_at for the given final status.
     *
     * Terminal/active orders have an updated_at a few hours after created_at.
     * Pending orders may not have been updated since creation.
     */
    private function resolveUpdatedAt(Carbon $createdAt, string $status): Carbon
    {
        return match ($status) {
            'delivered', 'dispatched' => $createdAt->copy()->addHours(random_int(2, 24)),
            'cancelled'               => $createdAt->copy()->addHours(random_int(1, 6)),
            'ready'                   => $createdAt->copy()->addHours(random_int(1, 8)),
            'preparing'               => $createdAt->copy()->addHours(random_int(1, 4)),
            default                   => $createdAt->copy()->addMinutes(random_int(0, 30)),
        };
    }

    // -------------------------------------------------------------------------
    // Data resolution helpers
    // -------------------------------------------------------------------------

    private function resolveMainBranch(Tenant $tenant): ?Branch
    {
        return Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();
    }

    /**
     * Load product variants for the tenant with their parent product name.
     * We join products to capture the display name for the snapshot.
     *
     * @return Collection<int, object>
     */
    private function resolveVariants(Tenant $tenant): Collection
    {
        return DB::table('product_variants')
            ->join('products', 'product_variants.product_id', '=', 'products.id')
            ->where('products.tenant_id', $tenant->id)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereNull('product_variants.deleted_at')
            ->select(
                'product_variants.id',
                'product_variants.sku',
                'product_variants.price_cents',
                'product_variants.options',
                'products.name as product_name',
            )
            ->get();
    }

    /**
     * Resolve staff and owner user IDs for the tenant.
     * Used for user_id (who took the order) and assigned_to (who preps it).
     *
     * @return Collection<int, int>
     */
    private function resolveStaffUserIds(Tenant $tenant): Collection
    {
        return DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['owner', 'admin', 'staff'])
            ->pluck('user_id');
    }

    /**
     * Insert a small set of demo customers for the tenant (idempotent).
     * Returns a Collection of customer IDs that can be used as customer_id on orders.
     *
     * We insert 3 customers per tenant so that some orders have named customers
     * and others are walk-in (customer_id null). The seeder does NOT call
     * CustomerService — it inserts directly so timestamps can be controlled.
     *
     * @return Collection<int, int>
     */
    private function provisionDemoCustomers(Tenant $tenant): Collection
    {
        $definitions = $this->demoCustomerDefinitions($tenant);

        $ids = [];

        foreach ($definitions as $def) {
            $existing = DB::table('customers')
                ->where('tenant_id', $tenant->id)
                ->where('email', $def['email'])
                ->value('id');

            if ($existing !== null) {
                $ids[] = (int) $existing;

                continue;
            }

            $id = DB::table('customers')->insertGetId(array_merge($def, [
                'tenant_id'        => $tenant->id,
                'total_purchases'  => 0,
                'last_purchase_at' => null,
                'created_at'       => now()->subDays(random_int(30, 90))->toDateTimeString(),
                'updated_at'       => now()->toDateTimeString(),
                'deleted_at'       => null,
            ]));

            $ids[] = $id;
        }

        return collect($ids);
    }

    /**
     * Per-tenant demo customer name/email/phone data.
     * Realistic for the tenant's locale (USD/SV vs COP/CO).
     *
     * @return list<array{name: string, email: string, phone: string|null, whatsapp: string|null, address: string|null, notes: string|null}>
     */
    private function demoCustomerDefinitions(Tenant $tenant): array
    {
        return match ($tenant->slug) {
            'rosa-eterna' => [
                ['name' => 'Ana Garcia',     'email' => 'ana@example.com',     'phone' => '50370002001', 'whatsapp' => '50370002001', 'address' => 'Col. Escalon, San Salvador', 'notes' => null],
                ['name' => 'Roberto Mejia',  'email' => 'roberto@example.com', 'phone' => '50370002002', 'whatsapp' => null,           'address' => null,                          'notes' => null],
                ['name' => 'Valeria Torres', 'email' => 'valeria@example.com', 'phone' => '50370002003', 'whatsapp' => '50370002003', 'address' => 'Santa Tecla, La Libertad',    'notes' => 'Prefiere entregas en la manana'],
            ],
            'tatiana' => [
                ['name' => 'Camila Rios',     'email' => 'camila@example.com',  'phone' => '573102001001', 'whatsapp' => '573102001001', 'address' => 'Chapinero, Bogota',     'notes' => null],
                ['name' => 'Andres Castro',   'email' => 'andres@example.com',  'phone' => '573102001002', 'whatsapp' => null,            'address' => null,                    'notes' => null],
                ['name' => 'Natalia Vargas',  'email' => 'natalia@example.com', 'phone' => '573102001003', 'whatsapp' => '573102001003', 'address' => 'Suba, Bogota',          'notes' => 'Envio a domicilio siempre'],
            ],
            default => [
                ['name' => 'Cliente Demo 1', 'email' => 'demo1@example.com', 'phone' => null, 'whatsapp' => null, 'address' => null, 'notes' => null],
                ['name' => 'Cliente Demo 2', 'email' => 'demo2@example.com', 'phone' => null, 'whatsapp' => null, 'address' => null, 'notes' => null],
            ],
        };
    }

    // -------------------------------------------------------------------------
    // Token generation
    // -------------------------------------------------------------------------

    /**
     * Generate a 32-char url-safe tracking token that is unique within this seeder run.
     *
     * All tokens are collected in $this->issuedTokens before any DB insert, ensuring
     * intra-run uniqueness without extra DB queries. The UNIQUE index on orders.tracking_token
     * is the last-resort guarantee against cross-run collisions (astronomically unlikely).
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(32);
        } while (isset($this->issuedTokens[$token]));

        $this->issuedTokens[$token] = true;

        return $token;
    }
}
