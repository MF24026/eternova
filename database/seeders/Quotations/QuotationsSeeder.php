<?php

declare(strict_types=1);

namespace Database\Seeders\Quotations;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ~10 realistic quotations per demo tenant spanning all five statuses,
 * each with 1-4 line items and a coherent status-history timeline.
 *
 * Design decisions — mirrors ReservationsSeeder / OrdersSeeder philosophy:
 *
 *   Raw DB inserts (DB::table over Eloquent):
 *     Bypasses BelongsToTenant::creating() (needs currentTenant in container),
 *     observers, and the sequence lock in QuotationService. We assign quotation
 *     numbers directly and advance quotation_sequences at the end.
 *
 *   Integer money only:
 *     subtotal = sum(line_total_cents); taxableBase = max(0, subtotal - discount);
 *     tax = intdiv(taxableBase * tax_rate_bps, 10000); total = taxableBase + tax.
 *     This is the exact server formula — never floats.
 *
 *   converted_order_id stays null:
 *     Conversion to an Order is a runtime action. We never fabricate it here
 *     (same doctrine as ReservationsSeeder).
 *
 *   Idempotency:
 *     Each tenant is skipped entirely if it already has quotations.
 */
final class QuotationsSeeder extends Seeder
{
    /**
     * Status distribution for the 10 quotations per tenant. Covers all five
     * statuses with a realistic bias toward active drafts and sent quotes.
     *
     * Count: 3 draft, 2 sent, 2 accepted, 2 rejected, 1 expired = 10
     *
     * @var list<string>
     */
    private const STATUS_PLAN = [
        'draft',
        'draft',
        'draft',
        'sent',
        'sent',
        'accepted',
        'accepted',
        'rejected',
        'rejected',
        'expired',
    ];

    /**
     * Canonical status chain from creation (draft) to each final status. Used to
     * build the status-history timeline. Every quotation starts as draft.
     *
     * @var array<string, list<string>>
     */
    private const STATUS_CHAIN = [
        'draft'    => ['draft'],
        'sent'     => ['draft', 'sent'],
        'accepted' => ['draft', 'sent', 'accepted'],
        'rejected' => ['draft', 'sent', 'rejected'],
        'expired'  => ['draft', 'sent', 'expired'],
    ];

    /**
     * Days to backdate created_at by final status. Terminal states are older.
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const CREATED_AT_RANGE = [
        'draft'    => ['min' =>  0, 'max' =>  6],
        'sent'     => ['min' =>  2, 'max' => 12],
        'accepted' => ['min' =>  7, 'max' => 25],
        'rejected' => ['min' =>  7, 'max' => 25],
        'expired'  => ['min' => 20, 'max' => 40],
    ];

    /** Spanish note for the initial creation history row. */
    private const CREATION_NOTES = [
        'Cotizacion creada',
        'Cotizacion ingresada por mostrador',
        'Borrador de cotizacion registrado',
        'Nueva cotizacion para el cliente',
    ];

    /**
     * Spanish notes per transition target. null = no note (realistic).
     *
     * @var array<string, list<string|null>>
     */
    private const TRANSITION_NOTES = [
        'sent'     => ['Enviada al cliente por WhatsApp', 'Cotizacion enviada por correo', null],
        'accepted' => ['Cliente acepto la cotizacion', 'Aprobada por el cliente', null],
        'rejected' => ['Cliente declino la propuesta', 'Rechazada — precio fuera de presupuesto', null],
        'expired'  => ['Vencida automaticamente sin respuesta', 'Sin respuesta del cliente', null],
    ];

    /**
     * Per-tenant line-item description templates. {n} is replaced with a noun.
     *
     * @var array<string, list<string>>
     */
    private const LINE_TEMPLATES = [
        'rosa-eterna' => [
            'Arreglo floral premium',
            'Bouquet de rosas importadas',
            'Centro de mesa para evento',
            'Corona floral condolencias',
            'Ramo de temporada',
            'Decoracion de altar',
            'Caja sorpresa con flores',
            'Servicio de montaje en sitio',
        ],
        'tatiana' => [
            'Canasta de regalo deluxe',
            'Set de peluches personalizados',
            'Caja de chocolates artesanales',
            'Arreglo de globos metalicos',
            'Paquete corporativo de regalos',
            'Tarjeta personalizada premium',
            'Empaque de lujo',
            'Servicio de entrega a domicilio',
        ],
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $alreadySeeded = DB::table('quotations')
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($alreadySeeded) {
            $this->command->info("QuotationsSeeder: quotations already exist for {$tenant->slug}, skipping.");

            return;
        }

        $branchId    = $this->resolveMainBranchId($tenant);
        $staffUserId = $this->resolveOwnerUserId($tenant);

        if ($staffUserId === null) {
            $this->command->warn("QuotationsSeeder: no staff user for tenant {$tenant->slug}, skipping.");

            return;
        }

        $customerIds = $this->resolveCustomerIds($tenant);
        $templates   = self::LINE_TEMPLATES[$tenant->slug] ?? self::LINE_TEMPLATES['rosa-eterna'];
        $year        = now()->year;
        $highestSeq  = 0;

        foreach (self::STATUS_PLAN as $seqIndex => $finalStatus) {
            $seq = $seqIndex + 1;

            if ($seq > $highestSeq) {
                $highestSeq = $seq;
            }

            $quotationNumber = sprintf('COT-%d-%04d', $year, $seq);

            $createdAtRange = self::CREATED_AT_RANGE[$finalStatus];
            $daysBack       = random_int($createdAtRange['min'], $createdAtRange['max']);
            $createdAt      = Carbon::now()->subDays($daysBack)->subHours(random_int(0, 23));

            $issueDate  = $createdAt->copy()->toDateString();
            // ~1-in-4 quotations have no expiry; the rest expire 15 days after issue.
            $validUntil = ($seq % 4 === 0)
                ? null
                : $createdAt->copy()->addDays(15)->toDateString();

            // Build line items first so the financial summary derives from real lines.
            [$lineRows, $subtotalCents] = $this->buildLineItems(
                tenantId: $tenant->id,
                templates: $templates,
                createdAt: $createdAt,
                seq: $seq,
            );

            // Discount on roughly every 3rd quotation; otherwise none.
            $discountCents = ($seq % 3 === 0)
                ? random_int(500, max(500, (int) ($subtotalCents * 0.15)))
                : 0;
            $discountCents = min($discountCents, $subtotalCents);

            // Half the quotations carry 13% IVA; the rest are tax-exempt (bps = 0).
            $taxRateBps = ($seq % 2 === 0) ? 1300 : 0;

            $taxableBase = max(0, $subtotalCents - $discountCents);
            $taxCents    = intdiv($taxableBase * $taxRateBps, 10000);
            $totalCents  = $taxableBase + $taxCents;

            $customerId = $this->pickCustomerId($customerIds, $finalStatus, $seq);

            $updatedAt = $this->resolveUpdatedAt($createdAt, $finalStatus);

            $quotationId = DB::table('quotations')->insertGetId([
                'tenant_id'          => $tenant->id,
                'branch_id'          => ($seq % 7 === 0) ? null : $branchId,
                'customer_id'        => $customerId,
                'quotation_number'   => $quotationNumber,
                'issue_date'         => $issueDate,
                'valid_until'        => $validUntil,
                'subtotal_cents'     => $subtotalCents,
                'discount_cents'     => $discountCents,
                'tax_rate_bps'       => $taxRateBps,
                'tax_cents'          => $taxCents,
                'total_cents'        => $totalCents,
                'status'             => $finalStatus,
                'notes'              => $this->maybeNotes($seq),
                'terms'              => $this->maybeTerms($tenant, $seq),
                'converted_order_id' => null,   // conversion is a runtime action; never fabricate
                'assigned_to'        => ($seq % 2 === 0) ? $staffUserId : null,
                'created_by'         => $staffUserId,
                'created_at'         => $createdAt->toDateTimeString(),
                'updated_at'         => $updatedAt->toDateTimeString(),
                'deleted_at'         => null,
            ]);

            // Backfill quotation_id into the item rows and insert.
            $finalLineRows = array_map(
                fn (array $row): array => array_merge($row, ['quotation_id' => $quotationId]),
                $lineRows,
            );

            DB::table('quotation_items')->insert($finalLineRows);

            // Insert the coherent status-history timeline.
            $historyRows = $this->buildStatusHistory(
                quotationId: $quotationId,
                tenantId: $tenant->id,
                finalStatus: $finalStatus,
                createdAt: $createdAt,
                userId: $staffUserId,
            );

            DB::table('quotation_status_history')->insert($historyRows);
        }

        // Advance quotation_sequences so the first real create continues cleanly.
        DB::table('quotation_sequences')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'year' => $year],
            [
                'last_sequence' => $highestSeq,
                'updated_at'    => now()->toDateTimeString(),
                'created_at'    => now()->toDateTimeString(),
            ],
        );

        $this->command->info(
            "QuotationsSeeder: {$tenant->slug} — {$highestSeq} quotations seeded "
            . "(all five statuses, sequence advanced to {$highestSeq}).",
        );
    }

    // -------------------------------------------------------------------------
    // Line-item builder
    // -------------------------------------------------------------------------

    /**
     * Build 1-4 quotation_items rows (without quotation_id — backfilled by caller)
     * and return them alongside the subtotal (sum of line_total_cents).
     *
     * @param  list<string>  $templates
     * @return array{list<array<string, mixed>>, int}  [itemRows, subtotalCents]
     */
    private function buildLineItems(
        string $tenantId,
        array $templates,
        Carbon $createdAt,
        int $seq,
    ): array {
        $lineCount = random_int(1, 4);
        $rows      = [];
        $subtotal  = 0;

        for ($i = 0; $i < $lineCount; $i++) {
            $quantity       = random_int(1, 6);
            $unitPriceCents = random_int(1500, 25000);
            $lineTotalCents = $quantity * $unitPriceCents;
            $subtotal      += $lineTotalCents;

            $rows[] = [
                'tenant_id'        => $tenantId,
                // quotation_id intentionally absent here; caller backfills after insert.
                'product_id'       => null,   // free-text snapshot lines for the demo
                'description'      => $templates[($seq + $i) % count($templates)],
                'quantity'         => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'line_total_cents' => $lineTotalCents,
                'sort_order'       => $i,
                'created_at'       => $createdAt->toDateTimeString(),
                'updated_at'       => $createdAt->toDateTimeString(),
            ];
        }

        return [$rows, $subtotal];
    }

    // -------------------------------------------------------------------------
    // Status-history timeline builder
    // -------------------------------------------------------------------------

    /**
     * Build a coherent quotation_status_history chain from draft to the final status.
     * Timestamps ascend from createdAt toward now(), never in the future.
     *
     * @return non-empty-list<array<string, mixed>>
     */
    private function buildStatusHistory(
        int $quotationId,
        string $tenantId,
        string $finalStatus,
        Carbon $createdAt,
        int $userId,
    ): array {
        $chain = self::STATUS_CHAIN[$finalStatus] ?? ['draft'];

        $rows        = [];
        $currentTime = $createdAt->copy();
        $timeSpanSecs = max(3600, (int) $createdAt->diffInSeconds(now()));
        $stepSecs     = (int) ($timeSpanSecs / max(count($chain), 1));

        // First row: creation entry (from_status = null, to_status = draft).
        $rows[] = [
            'tenant_id'    => $tenantId,
            'quotation_id' => $quotationId,
            'from_status'  => null,
            'to_status'    => $chain[0],
            'user_id'      => $userId,
            'note'         => self::CREATION_NOTES[array_rand(self::CREATION_NOTES)],
            'created_at'   => $currentTime->toDateTimeString(),
        ];

        for ($i = 1; $i < count($chain); $i++) {
            $advance     = $stepSecs + random_int(0, (int) max($stepSecs * 0.5, 600));
            $currentTime = $currentTime->copy()->addSeconds($advance);

            if ($currentTime->isFuture()) {
                $currentTime = Carbon::now()->subMinutes(random_int(1, 30));
            }

            $toStatus = $chain[$i];
            $notePool = self::TRANSITION_NOTES[$toStatus] ?? [null];

            // The expiry transition is performed by the system job (no user).
            $rowUserId = ($toStatus === 'expired') ? null : $userId;

            $rows[] = [
                'tenant_id'    => $tenantId,
                'quotation_id' => $quotationId,
                'from_status'  => $chain[$i - 1],
                'to_status'    => $toStatus,
                'user_id'      => $rowUserId,
                'note'         => $notePool[array_rand($notePool)],
                'created_at'   => $currentTime->toDateTimeString(),
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Notes / terms helpers
    // -------------------------------------------------------------------------

    private function maybeNotes(int $seq): ?string
    {
        if ($seq % 2 !== 0) {
            return null;
        }

        $notes = [
            'Precios validos sujetos a disponibilidad de temporada.',
            'Incluye montaje y entrega dentro de la ciudad.',
            'Los colores pueden variar segun disponibilidad floral.',
            'Cotizacion personalizada para el evento del cliente.',
        ];

        return $notes[$seq % count($notes)];
    }

    private function maybeTerms(Tenant $tenant, int $seq): ?string
    {
        if ($seq % 3 === 0) {
            return null;
        }

        return 'Anticipo del 50% para confirmar. Saldo contra entrega. '
            . 'Cotizacion valida por 15 dias a partir de la fecha de emision.';
    }

    // -------------------------------------------------------------------------
    // Timestamp helper
    // -------------------------------------------------------------------------

    private function resolveUpdatedAt(Carbon $createdAt, string $finalStatus): Carbon
    {
        $updated = match ($finalStatus) {
            'accepted', 'rejected' => $createdAt->copy()->addDays(random_int(1, 10)),
            'expired'              => $createdAt->copy()->addDays(15),
            'sent'                 => $createdAt->copy()->addHours(random_int(1, 48)),
            default                => $createdAt->copy()->addMinutes(random_int(0, 120)),
        };

        return $updated->isFuture() ? Carbon::now() : $updated;
    }

    // -------------------------------------------------------------------------
    // Data resolution helpers
    // -------------------------------------------------------------------------

    private function resolveMainBranchId(Tenant $tenant): ?string
    {
        return DB::table('branches')
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->value('id');
    }

    private function resolveOwnerUserId(Tenant $tenant): ?int
    {
        $id = DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['owner', 'admin', 'staff'])
            ->value('user_id');

        return $id === null ? null : (int) $id;
    }

    /**
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
     * Pick a customer for a quotation. Drafts are more likely to be anonymous
     * (walk-in inquiries not yet registered).
     */
    private function pickCustomerId(Collection $customerIds, string $finalStatus, int $seq): ?int
    {
        if ($customerIds->isEmpty()) {
            return null;
        }

        if ($finalStatus === 'draft' && $seq % 2 === 0) {
            return null;
        }

        return $customerIds->random();
    }
}
