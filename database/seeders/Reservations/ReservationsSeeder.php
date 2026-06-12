<?php

declare(strict_types=1);

namespace Database\Seeders\Reservations;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ~12 realistic reservations per demo tenant spanning all six statuses,
 * with status-history timelines, payment instalments, and per-tenant config.
 *
 * Design decisions — mirrors OrdersSeeder philosophy exactly:
 *
 *   Raw DB inserts with backdated/future timestamps:
 *     Reservations are future events. We do NOT call ReservationService::capture()
 *     because that would hit the sequence lock and trigger observers. We assign
 *     reservation numbers directly and advance reservation_sequences at the end.
 *
 *   DB::table() over Eloquent:
 *     Bypasses BelongsToTenant::creating() (requires currentTenant in container),
 *     observers, and the timestamps() restriction on created_at.
 *
 *   deposit_paid_cents consistency:
 *     We build the payment rows first, sum their amount_cents, and THEN write that
 *     sum into reservations.deposit_paid_cents. The reservation row is never inserted
 *     with a stale deposit_paid_cents value.
 *
 *   reservation_sequences advancement:
 *     After inserting all seeded reservations, we upsert the reservation_sequences
 *     row for each tenant so last_sequence >= the highest seeded number. The first
 *     real capture will increment from there without collision.
 *
 *   Per-tenant occasions and deposit_pct:
 *     The seeder also sets customised reservation_occasions and reservation_deposit_pct
 *     on each demo tenant so the UI showcases per-tenant configuration. rosa-eterna
 *     gets floral/event occasions at 30%; tatiana gets gift/celebration occasions at 50%.
 *
 *   Idempotency:
 *     Each tenant is skipped entirely if it already has reservations in the table.
 *     Tenant config (occasions, deposit_pct) is always updated — safe to call twice.
 */
final class ReservationsSeeder extends Seeder
{
    /**
     * Status distribution plan for the 12 reservations per tenant.
     * Realistic bias: confirmed and in_progress dominate active workload;
     * a handful delivered/cancelled for historical context.
     *
     * Count: 2 inquiry, 3 confirmed, 3 in_progress, 1 ready, 2 delivered, 1 cancelled = 12
     *
     * @var list<string>
     */
    private const STATUS_PLAN = [
        'inquiry',
        'inquiry',
        'confirmed',
        'confirmed',
        'confirmed',
        'in_progress',
        'in_progress',
        'in_progress',
        'ready',
        'delivered',
        'delivered',
        'cancelled',
    ];

    /**
     * State machine transition path (mirrors the reservations workflow documented
     * in sprint-5-plan.md). Each key maps to the next state on the canonical path.
     *
     * The full happy-path is: inquiry → confirmed → in_progress → ready → delivered.
     * Any non-terminal state can transition directly to cancelled.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITION_PATH = [
        'inquiry'     => ['confirmed'],
        'confirmed'   => ['in_progress'],
        'in_progress' => ['ready'],
        'ready'       => ['delivered'],
        'delivered'   => [],
        'cancelled'   => [],
    ];

    /**
     * How many days back to backdate the reservation's created_at by status.
     * Delivered/cancelled are oldest; inquiry/confirmed are newest.
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const CREATED_AT_RANGE = [
        'delivered'   => ['min' => 14, 'max' => 30],
        'cancelled'   => ['min' =>  7, 'max' => 20],
        'ready'       => ['min' =>  5, 'max' => 10],
        'in_progress' => ['min' =>  3, 'max' =>  8],
        'confirmed'   => ['min' =>  1, 'max' =>  5],
        'inquiry'     => ['min' =>  0, 'max' =>  2],
    ];

    /**
     * How many days ahead of NOW to place the event_date.
     * Delivered/cancelled events are in the past; active events are in the future.
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const EVENT_DATE_RANGE = [
        'inquiry'     => ['min' => 10, 'max' => 60],
        'confirmed'   => ['min' =>  7, 'max' => 45],
        'in_progress' => ['min' =>  3, 'max' => 21],
        'ready'       => ['min' =>  0, 'max' =>  7],
        'delivered'   => ['min' => -30, 'max' => -1],  // past events
        'cancelled'   => ['min' => -10, 'max' => 20],  // mix of past and future
    ];

    /**
     * Realistic total_cents ranges in centavos per status bracket.
     * Delivered reservations tend to be larger (they were actually fulfilled).
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const TOTAL_CENTS_RANGE = [
        'inquiry'     => ['min' =>  5000, 'max' =>  50000],
        'confirmed'   => ['min' => 15000, 'max'  =>  80000],
        'in_progress' => ['min' => 20000, 'max' => 120000],
        'ready'       => ['min' => 25000, 'max' =>  90000],
        'delivered'   => ['min' => 30000, 'max' => 120000],
        'cancelled'   => ['min' =>  5000, 'max' =>  40000],
    ];

    /** Spanish notes for the initial creation history row. */
    private const CREATION_NOTES = [
        'Reserva creada',
        'Reserva recibida por WhatsApp',
        'Reserva ingresada por mostrador',
        'Solicitud de reserva registrada',
        'Nueva reserva de evento',
    ];

    /** Spanish notes for intermediate status transitions. null = no note (realistic). */
    private const TRANSITION_NOTES = [
        'inquiry'     => ['Consulta recibida, pendiente de confirmar', 'Cliente interesado, esperando detalles', null],
        'confirmed'   => ['Adelanto recibido, reserva confirmada', 'Reserva confirmada con el cliente', null, null],
        'in_progress' => ['Arreglo en preparacion', 'Se inicio la elaboracion', 'Materiales listos, comenzando', null],
        'ready'       => ['Pedido listo para entrega', 'Arreglo terminado, esperando al cliente', null],
        'delivered'   => ['Entregado al cliente', 'Entrega exitosa', null],
        'cancelled'   => ['Cancelado a solicitud del cliente', 'Sin adelanto — reserva anulada', null],
    ];

    /**
     * Occasions list per tenant slug.
     * These are also written to tenants.reservation_occasions to showcase per-tenant config.
     *
     * @var array<string, list<string>>
     */
    private const TENANT_OCCASIONS = [
        'rosa-eterna' => [
            'Boda',
            'Quinceanos',
            'Bautizo',
            'Cumpleanos',
            'Aniversario',
            'Corporativo',
            'Funeral',
            'Graduacion',
            'Dia de la Madre',
            'San Valentin',
        ],
        'tatiana' => [
            'Cumpleanos',
            'Baby Shower',
            'Boda',
            'Dia de la Madre',
            'Graduacion',
            'Aniversario',
            'Regalo Corporativo',
            'Navidad',
            'Halloween',
            'Dia del Amor',
        ],
    ];

    /**
     * Deposit percentages per tenant slug.
     * rosa-eterna uses the system default (30%); tatiana customises to 50%
     * to showcase that the config is per-tenant, not global.
     *
     * @var array<string, int>
     */
    private const TENANT_DEPOSIT_PCT = [
        'rosa-eterna' => 30,
        'tatiana'     => 50,
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            // Apply per-tenant config regardless of idempotency guard below —
            // config updates are always safe to reapply.
            $this->applyTenantReservationConfig($tenant);
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $alreadySeeded = DB::table('reservations')
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($alreadySeeded) {
            $this->command->info("ReservationsSeeder: reservations already exist for {$tenant->slug}, skipping.");

            return;
        }

        $branch = $this->resolveMainBranch($tenant);

        if ($branch === null) {
            $this->command->warn("ReservationsSeeder: no main branch for tenant {$tenant->slug}, skipping.");

            return;
        }

        $staffUserIds = $this->resolveStaffUserIds($tenant);

        if ($staffUserIds->isEmpty()) {
            $this->command->warn("ReservationsSeeder: no staff users for tenant {$tenant->slug}, skipping.");

            return;
        }

        $customerIds    = $this->resolveCustomerIds($tenant);
        $occasions      = $this->occasionsFor($tenant);
        $depositPct     = self::TENANT_DEPOSIT_PCT[$tenant->slug] ?? 30;
        $year           = now()->year;
        $highestSeq     = 0;

        foreach (self::STATUS_PLAN as $seqIndex => $finalStatus) {
            $seq = $seqIndex + 1;

            if ($seq > $highestSeq) {
                $highestSeq = $seq;
            }

            $reservationNumber = sprintf('RSV-%d-%04d', $year, $seq);

            // Timestamps: reservation was created in the past; event date may be future or past.
            $createdAtRange = self::CREATED_AT_RANGE[$finalStatus];
            $daysBack       = random_int($createdAtRange['min'], $createdAtRange['max']);
            $hoursBack      = random_int(0, 23);
            $createdAt      = Carbon::now()->subDays($daysBack)->subHours($hoursBack);
            $updatedAt      = $this->resolveUpdatedAt($createdAt, $finalStatus);

            // event_date: future for active reservations, past for delivered/cancelled.
            $eventDateRange = self::EVENT_DATE_RANGE[$finalStatus];
            $eventDaysOffset = random_int($eventDateRange['min'], $eventDateRange['max']);
            $eventDate = Carbon::now()->addDays($eventDaysOffset)->toDateString();

            // Money: pick a total, derive deposit_required from it.
            $totalRange  = self::TOTAL_CENTS_RANGE[$finalStatus];
            $totalCents  = random_int($totalRange['min'], $totalRange['max']);

            // A few reservations get a custom deposit override (not just pct of total).
            // This exercises the "flexible deposit" path visible in the UI.
            $useCustomDeposit = ($seq % 4 === 0);

            $depositRequiredCents = $useCustomDeposit
                ? random_int((int) ($totalCents * 0.2), (int) ($totalCents * 0.6))
                : (int) round($totalCents * $depositPct / 100);

            // Build payments first so we can derive deposit_paid_cents from the actual sum.
            [$depositPaidCents, $paymentRows] = $this->buildPayments(
                tenantId: $tenant->id,
                finalStatus: $finalStatus,
                depositRequiredCents: $depositRequiredCents,
                totalCents: $totalCents,
                staffUserIds: $staffUserIds,
                reservationCreatedAt: $createdAt,
                seq: $seq,
            );

            // customer_id: mix of registered customers and walk-in inquiries.
            // inquiry reservations are more likely to be anonymous.
            $customerId = $this->pickCustomerId(
                customerIds: $customerIds,
                finalStatus: $finalStatus,
                seq: $seq,
            );

            // assigned_to: active reservations are ~half the time assigned to someone.
            $isActiveStatus = in_array($finalStatus, ['confirmed', 'in_progress', 'ready'], true);
            $assignedTo     = ($isActiveStatus && $seq % 2 === 0 && $staffUserIds->isNotEmpty())
                ? $staffUserIds->random()
                : null;

            $createdBy = $staffUserIds->first();

            // branch_id: mostly the main branch; a few null to exercise that path.
            $branchId = ($seq % 7 === 0) ? null : $branch->id;

            // Insert the reservation row.
            $reservationId = DB::table('reservations')->insertGetId([
                'tenant_id'               => $tenant->id,
                'branch_id'               => $branchId,
                'customer_id'             => $customerId,
                'reservation_number'      => $reservationNumber,
                'description'             => $this->buildDescription($tenant, $finalStatus, $occasions),
                'occasion'                => $occasions[array_rand($occasions)],
                'event_date'              => $eventDate,
                'total_cents'             => $totalCents,
                'deposit_required_cents'  => $depositRequiredCents,
                'deposit_paid_cents'      => $depositPaidCents,
                'status'                  => $finalStatus,
                'special_instructions'    => $this->maybeSpecialInstructions($seq),
                'admin_notes'             => $this->maybeAdminNotes($seq, $finalStatus),
                'converted_order_id'      => null,   // conversion is a runtime action; never fabricate
                'assigned_to'             => $assignedTo,
                'created_by'              => $createdBy,
                'created_at'              => $createdAt->toDateTimeString(),
                'updated_at'              => $updatedAt->toDateTimeString(),
                'deleted_at'              => null,
            ]);

            // Backfill the reservation_id now that we have it.
            $finalPaymentRows = array_map(
                fn (array $row): array => array_merge($row, ['reservation_id' => $reservationId]),
                $paymentRows,
            );

            if ($finalPaymentRows !== []) {
                DB::table('reservation_payments')->insert($finalPaymentRows);
            }

            // Insert the coherent status-history timeline.
            $historyRows = $this->buildStatusHistory(
                reservationId: $reservationId,
                tenantId: $tenant->id,
                finalStatus: $finalStatus,
                reservationCreatedAt: $createdAt,
                staffUserId: $staffUserIds->first(),
            );

            DB::table('reservation_status_history')->insert($historyRows);
        }

        // Advance reservation_sequences so the first real capture continues cleanly.
        DB::table('reservation_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'year' => $year],
            [
                'last_sequence' => $highestSeq,
                'updated_at'    => now()->toDateTimeString(),
                'created_at'    => now()->toDateTimeString(),
            ],
        );

        $this->command->info(
            "ReservationsSeeder: {$tenant->slug} — {$highestSeq} reservations seeded "
            . "(deposit_pct={$depositPct}%, sequence advanced to {$highestSeq}).",
        );
    }

    // -------------------------------------------------------------------------
    // Payment builder
    // -------------------------------------------------------------------------

    /**
     * Build reservation_payments rows for one reservation.
     *
     * Rules by status:
     *   - inquiry      → no payments (deposit not yet requested)
     *   - confirmed    → 1 payment = deposit_required
     *   - in_progress  → 1-2 payments summing to >= deposit_required, may be partial or full
     *   - ready        → 1-2 payments >= deposit_required (customer picked up deposit)
     *   - delivered    → payments sum to total_cents (fully paid on delivery)
     *   - cancelled    → no payments (cancelled before payment, or refunded)
     *
     * The returned $depositPaidCents is the authoritative sum of the returned rows.
     * The caller MUST write this value into reservations.deposit_paid_cents — it is
     * never computed after the fact.
     *
     * @param  Collection<int, int>  $staffUserIds
     * @return array{int, list<array<string, mixed>>}  [deposit_paid_cents, paymentRows (no reservation_id yet)]
     */
    private function buildPayments(
        string $tenantId,
        string $finalStatus,
        int $depositRequiredCents,
        int $totalCents,
        Collection $staffUserIds,
        Carbon $reservationCreatedAt,
        int $seq,
    ): array {
        if (in_array($finalStatus, ['inquiry', 'cancelled'], true)) {
            return [0, []];
        }

        $payments      = [];
        $recordedBy    = $staffUserIds->isNotEmpty() ? $staffUserIds->random() : null;

        if ($finalStatus === 'delivered') {
            // Delivered = fully settled. Seed 2 payments: deposit first, remainder at delivery.
            $firstAmount  = $depositRequiredCents;
            $secondAmount = max(0, $totalCents - $firstAmount);

            $firstPaidAt  = $reservationCreatedAt->copy()->addHours(random_int(2, 48));
            $secondPaidAt = $firstPaidAt->copy()->addDays(random_int(1, 7));

            $payments[] = $this->paymentRow(
                tenantId: $tenantId,
                amountCents: $firstAmount,
                paidAt: $firstPaidAt,
                recordedBy: $recordedBy,
                seq: $seq,
            );

            if ($secondAmount > 0) {
                $payments[] = $this->paymentRow(
                    tenantId: $tenantId,
                    amountCents: $secondAmount,
                    paidAt: $secondPaidAt,
                    recordedBy: $recordedBy,
                    seq: $seq + 100,
                );
            }

            $paidCents = array_sum(array_column($payments, 'amount_cents'));

            return [$paidCents, $payments];
        }

        // confirmed / in_progress / ready — at least the deposit is paid.
        // Occasionally a second partial instalment is included for in_progress/ready.
        $paidAt = $reservationCreatedAt->copy()->addHours(random_int(1, 24));

        $payments[] = $this->paymentRow(
            tenantId: $tenantId,
            amountCents: $depositRequiredCents,
            paidAt: $paidAt,
            recordedBy: $recordedBy,
            seq: $seq,
        );

        $addExtraInstalment = in_array($finalStatus, ['in_progress', 'ready'], true)
            && ($seq % 3 === 0);

        if ($addExtraInstalment) {
            $extraAmount  = random_int(
                (int) ($depositRequiredCents * 0.5),
                (int) ($totalCents - $depositRequiredCents),
            );
            $extraPaidAt  = $paidAt->copy()->addDays(random_int(1, 5));

            $payments[] = $this->paymentRow(
                tenantId: $tenantId,
                amountCents: max(0, $extraAmount),
                paidAt: $extraPaidAt,
                recordedBy: $recordedBy,
                seq: $seq + 200,
            );
        }

        $paidCents = array_sum(array_column($payments, 'amount_cents'));

        return [$paidCents, $payments];
    }

    /**
     * Build a single reservation_payments row (without reservation_id — backfilled by caller).
     *
     * @return array<string, mixed>
     */
    private function paymentRow(
        string $tenantId,
        int $amountCents,
        Carbon $paidAt,
        ?int $recordedBy,
        int $seq,
    ): array {
        return [
            'tenant_id'      => $tenantId,
            // reservation_id is intentionally absent here; caller backfills after insert.
            'amount_cents'   => $amountCents,
            'payment_method' => $this->randomPaymentMethod($seq),
            'reference'      => null,
            'recorded_by'    => $recordedBy,
            'paid_at'        => $paidAt->toDateTimeString(),
            'created_at'     => $paidAt->toDateTimeString(),
            'updated_at'     => $paidAt->toDateTimeString(),
        ];
    }

    // -------------------------------------------------------------------------
    // Status-history timeline builder
    // -------------------------------------------------------------------------

    /**
     * Build a coherent reservation_status_history chain from creation to final status.
     *
     * Rules:
     *   - First row: from_status=null, to_status=inquiry (all reservations start as inquiry).
     *   - One row per real transition following TRANSITION_PATH until final status reached.
     *   - Last row's to_status MUST equal $finalStatus.
     *   - Timestamps are ascending from reservationCreatedAt toward now().
     *
     * @param  int|null  $staffUserId
     * @return non-empty-list<array<string, mixed>>
     */
    private function buildStatusHistory(
        int $reservationId,
        string $tenantId,
        string $finalStatus,
        Carbon $reservationCreatedAt,
        ?int $staffUserId,
    ): array {
        $statusChain = $this->buildStatusChain('inquiry', $finalStatus);

        $rows        = [];
        $currentTime = $reservationCreatedAt->copy();
        $timeSpanSecs = max(3600, (int) $reservationCreatedAt->diffInSeconds(now()));
        $stepSecs     = (int) ($timeSpanSecs / max(count($statusChain), 1));

        // First row: creation entry (from_status = null, to_status = inquiry).
        $creationNote = self::CREATION_NOTES[array_rand(self::CREATION_NOTES)];

        $rows[] = [
            'tenant_id'   => $tenantId,
            'reservation_id' => $reservationId,
            'from_status' => null,
            'to_status'   => $statusChain[0],
            'user_id'     => $staffUserId,
            'note'        => $creationNote,
            'created_at'  => $currentTime->toDateTimeString(),
        ];

        // Subsequent transition rows: one per step in the chain.
        for ($i = 1; $i < count($statusChain); $i++) {
            $advance     = $stepSecs + random_int(0, (int) max($stepSecs * 0.5, 600));
            $currentTime = $currentTime->copy()->addSeconds($advance);

            // Never write a future timestamp on a history row.
            if ($currentTime->isFuture()) {
                $currentTime = Carbon::now()->subMinutes(random_int(1, 30));
            }

            $toStatus = $statusChain[$i];
            $notePool = self::TRANSITION_NOTES[$toStatus] ?? [null];
            $note     = $notePool[array_rand($notePool)];

            $rows[] = [
                'tenant_id'      => $tenantId,
                'reservation_id' => $reservationId,
                'from_status'    => $statusChain[$i - 1],
                'to_status'      => $toStatus,
                'user_id'        => $staffUserId,
                'note'           => $note,
                'created_at'     => $currentTime->toDateTimeString(),
            ];
        }

        return $rows;
    }

    /**
     * Build the ordered list of statuses from $initial through to $final,
     * walking TRANSITION_PATH. cancelled is always reached in one hop.
     *
     * @return non-empty-list<string>
     */
    private function buildStatusChain(string $initial, string $final): array
    {
        if ($initial === $final) {
            return [$initial];
        }

        $chain   = [$initial];
        $current = $initial;

        while ($current !== $final) {
            $next = $this->nextStatusTowards($current, $final);

            if ($next === null) {
                break;
            }

            $chain[] = $next;
            $current = $next;
        }

        return $chain;
    }

    /**
     * Return the immediate next status step towards $target from $current.
     *
     * cancelled is reachable from any non-terminal status in one hop.
     */
    private function nextStatusTowards(string $current, string $target): ?string
    {
        if ($target === 'cancelled') {
            return 'cancelled';
        }

        $transitions = self::TRANSITION_PATH[$current] ?? [];

        if ($transitions === []) {
            return null;
        }

        if (in_array($target, $transitions, true)) {
            return $target;
        }

        return $transitions[0];
    }

    // -------------------------------------------------------------------------
    // Per-tenant config application
    // -------------------------------------------------------------------------

    /**
     * Write customised occasions list and deposit_pct to the tenant row.
     *
     * Called unconditionally (not guarded by the idempotency check) so that
     * re-running the seeder always ensures config is correct.
     */
    private function applyTenantReservationConfig(Tenant $tenant): void
    {
        $occasions  = self::TENANT_OCCASIONS[$tenant->slug] ?? null;
        $depositPct = self::TENANT_DEPOSIT_PCT[$tenant->slug] ?? 30;

        $tenant->update([
            'reservation_occasions'  => $occasions,
            'reservation_deposit_pct' => $depositPct,
        ]);
    }

    // -------------------------------------------------------------------------
    // Description / notes helpers
    // -------------------------------------------------------------------------

    /**
     * Build a realistic multi-word description for a reservation based on tenant
     * context and status. These strings appear in the list/board/detail views.
     *
     * @param  list<string>  $occasions
     */
    private function buildDescription(Tenant $tenant, string $finalStatus, array $occasions): string
    {
        $occasion = $occasions[array_rand($occasions)];

        $templates = match ($tenant->slug) {
            'rosa-eterna' => [
                "Arreglo floral para {$occasion}",
                "Bouquet personalizado — {$occasion}",
                "Decoracion floral para evento de {$occasion}",
                "Centro de mesa floral para {$occasion}",
                "Coronas y adornos florales — {$occasion}",
            ],
            'tatiana' => [
                "Canasta de regalos para {$occasion}",
                "Set de peluches personalizados — {$occasion}",
                "Caja regalo especial para {$occasion}",
                "Ramo de globos y accesorios — {$occasion}",
                "Paquete corporativo de regalos — {$occasion}",
            ],
            default => [
                "Pedido personalizado — {$occasion}",
                "Arreglo especial para {$occasion}",
            ],
        };

        return $templates[array_rand($templates)];
    }

    /**
     * Return a special instruction on every 3rd reservation; null otherwise.
     * Exercises the nullable special_instructions path in views.
     */
    private function maybeSpecialInstructions(int $seq): ?string
    {
        if ($seq % 3 !== 0) {
            return null;
        }

        $instructions = [
            'Entregar en la manana antes de las 10am',
            'Incluir tarjeta con mensaje personalizado',
            'Sin liliums — alergia del destinatario',
            'Colores pastel unicamente',
            'Empacar para transporte aereo',
        ];

        return $instructions[$seq % count($instructions)];
    }

    /**
     * Return an admin note for delivered and in_progress reservations; null otherwise.
     */
    private function maybeAdminNotes(int $seq, string $finalStatus): ?string
    {
        if (! in_array($finalStatus, ['delivered', 'in_progress'], true)) {
            return null;
        }

        $notes = [
            'Cliente pago el saldo al recoger',
            'Se ajusto el diseno a solicitud del cliente',
            'Materiales llegaron tarde, se adelanta al martes',
            'Cliente muy satisfecho — recomendar seguimiento',
        ];

        return $notes[$seq % count($notes)];
    }

    // -------------------------------------------------------------------------
    // Timestamp helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve a realistic updated_at >= created_at for the given final status.
     */
    private function resolveUpdatedAt(Carbon $createdAt, string $finalStatus): Carbon
    {
        return match ($finalStatus) {
            'delivered'   => $createdAt->copy()->addDays(random_int(1, 14)),
            'cancelled'   => $createdAt->copy()->addHours(random_int(1, 48)),
            'ready'       => $createdAt->copy()->addDays(random_int(1, 5)),
            'in_progress' => $createdAt->copy()->addDays(random_int(1, 3)),
            'confirmed'   => $createdAt->copy()->addHours(random_int(1, 12)),
            default       => $createdAt->copy()->addMinutes(random_int(0, 60)),  // inquiry
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
     * Resolve staff and owner user IDs for the tenant.
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
     * Resolve existing customer IDs for the tenant.
     * These were provisioned by OrdersSeeder; we reuse them here rather than
     * seeding duplicates. Returns empty if no customers yet (walk-in only).
     *
     * @return Collection<int, int>
     */
    private function resolveCustomerIds(Tenant $tenant): Collection
    {
        return DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->pluck('id');
    }

    /**
     * Pick a customer_id for a reservation.
     *
     * inquiry reservations are more likely to be anonymous (customer not yet registered).
     * Walk-ins account for roughly 1-in-3 of active reservations.
     */
    private function pickCustomerId(
        Collection $customerIds,
        string $finalStatus,
        int $seq,
    ): ?int {
        if ($customerIds->isEmpty()) {
            return null;
        }

        // inquiry → 50% chance of walk-in (customer not registered yet)
        if ($finalStatus === 'inquiry' && $seq % 2 === 0) {
            return null;
        }

        // Every 3rd non-inquiry reservation is a walk-in.
        if ($finalStatus !== 'inquiry' && $seq % 3 === 0) {
            return null;
        }

        return $customerIds->random();
    }

    /**
     * Return an occasion list for the given tenant, falling back to a generic list.
     *
     * @return list<string>
     */
    private function occasionsFor(Tenant $tenant): array
    {
        return self::TENANT_OCCASIONS[$tenant->slug] ?? [
            'Cumpleanos',
            'Boda',
            'Evento Corporativo',
        ];
    }

    // -------------------------------------------------------------------------
    // Payment method helper
    // -------------------------------------------------------------------------

    /**
     * Realistic payment method distribution for a LatAm florist/gift shop context.
     * cash-heavy, with transfers growing in popularity.
     */
    private function randomPaymentMethod(int $seq): string
    {
        return match ($seq % 10) {
            0, 1, 2, 3, 4 => 'cash',
            5, 6           => 'transfer',
            7              => 'card',
            default        => 'other',
        };
    }
}
