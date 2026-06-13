<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationItem;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use App\Modules\Quotations\Repositories\QuotationRepositoryInterface;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates quotation lifecycle: numbering, totals calculation, line item
 * persistence, and the status state machine with its audit history.
 *
 * This service owns the transition rules. All status changes MUST go through
 * transitionTo() — direct $quotation->update(['status' => ...]) calls bypass
 * the history log and break the audit trail.
 *
 * Totals calculation rules (integer math, no float):
 *   - subtotal_cents = SUM(quantity * unit_price_cents) over all lines
 *   - taxableBase    = max(0, subtotal_cents - discount_cents)
 *       Discount is clamped to subtotal — it cannot produce a negative taxable base.
 *       (e.g. a goodwill discount of 150% is treated as 100% — taxable base = 0)
 *   - tax_cents      = intdiv(taxableBase * taxRateBps, 10_000)
 *       Truncates towards zero (floor for positive values). Chosen over round()
 *       because tax collected should never exceed what the rate formula dictates —
 *       rounding UP would overcharge the customer. intdiv is deterministic and
 *       consistent across PHP versions with no float intermediary.
 *   - total_cents    = taxableBase + tax_cents
 *
 * State machine transitions:
 *   draft     → sent | accepted | rejected | expired
 *   sent      → accepted | rejected | expired
 *   accepted  → (terminal)
 *   rejected  → (terminal)
 *   expired   → (terminal)
 *
 * Every transition is recorded in quotation_status_history (append-only).
 * The initial creation row (from_status=null) is written by recordInitialHistory().
 */
final readonly class QuotationService
{
    public function __construct(
        private QuotationRepositoryInterface $quotations,
        private OrderService $orderService,
    ) {}

    /**
     * Valid next statuses for each status.
     *
     * Terminal statuses (accepted, rejected, expired) map to empty arrays — no
     * transitions are allowed from them. Unknown statuses are rejected before this
     * map is consulted.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'draft'    => ['sent', 'accepted', 'rejected', 'expired'],
        'sent'     => ['accepted', 'rejected', 'expired'],
        'accepted' => [],
        'rejected' => [],
        'expired'  => [],
    ];

    // ── Creation ─────────────────────────────────────────────────────────────

    /**
     * Create a new quotation for the current tenant.
     *
     * Runs inside a DB transaction so that the sequence claim, quotation row,
     * and line item rows are persisted atomically. The quotation starts in
     * 'draft' status; recordInitialHistory() writes the birth entry.
     *
     * Tenant defaults applied when not explicitly provided:
     *   - tax_rate_bps : tenant.quotation_tax_rate_bps  (e.g. 1300 = 13% IVA)
     *   - valid_until  : issue_date + tenant.quotation_valid_days
     *   - terms        : tenant.quotation_terms
     *
     * @param  array<string, mixed>  $data  Validated payload
     *
     * @throws DomainException When no tenant context can be resolved
     */
    public function create(array $data, ?User $actor = null): Quotation
    {
        $tenant = $this->resolveTenant();

        return DB::transaction(function () use ($data, $actor, $tenant): Quotation {
            $number    = $this->quotations->nextQuotationNumber($tenant);
            $issueDate = Carbon::parse($data['issue_date'] ?? now());

            // Apply tenant defaults when the caller did not override them.
            $taxRateBps = isset($data['tax_rate_bps'])
                ? (int) $data['tax_rate_bps']
                : (int) $tenant->quotation_tax_rate_bps;

            $validUntil = isset($data['valid_until'])
                ? $data['valid_until']
                : $issueDate->copy()->addDays((int) $tenant->quotation_valid_days)->toDateString();

            $terms = $data['terms'] ?? $tenant->quotation_terms;

            $lines   = $this->normaliseLines($data['items'] ?? []);
            $totals  = $this->calculateTotals(
                lines: $lines,
                discountCents: (int) ($data['discount_cents'] ?? 0),
                taxRateBps: $taxRateBps,
            );

            $quotation = $this->quotations->create([
                'tenant_id'        => $tenant->id,
                'branch_id'        => $data['branch_id'] ?? null,
                'customer_id'      => $data['customer_id'] ?? null,
                'quotation_number' => $number,
                'issue_date'       => $issueDate->toDateString(),
                'valid_until'      => $validUntil,
                'subtotal_cents'   => $totals['subtotal_cents'],
                'discount_cents'   => $totals['discount_cents'],
                'tax_rate_bps'     => $taxRateBps,
                'tax_cents'        => $totals['tax_cents'],
                'total_cents'      => $totals['total_cents'],
                'status'           => 'draft',
                'notes'            => $data['notes'] ?? null,
                'terms'            => $terms,
                'assigned_to'      => $data['assigned_to'] ?? null,
                'created_by'       => $actor?->id,
            ]);

            $this->persistLines($quotation, $lines);

            $this->recordInitialHistory($quotation, $actor);

            Log::info('Quotation created', [
                'quotation_id'     => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'tenant_id'        => $quotation->tenant_id,
                'subtotal_cents'   => $quotation->subtotal_cents,
                'discount_cents'   => $quotation->discount_cents,
                'tax_cents'        => $quotation->tax_cents,
                'total_cents'      => $quotation->total_cents,
                'actor_id'         => $actor?->id,
            ]);

            return $quotation;
        });
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /**
     * Update the scalar fields and line items of a draft quotation.
     *
     * Only draft quotations may be edited — any other status means the quotation
     * was already shared with the customer and must not change silently.
     *
     * Line items are replaced wholesale (delete existing rows + re-insert from
     * payload) to avoid partial-update edge cases. sort_order is preserved from
     * the payload, allowing the caller to reorder lines on each save.
     *
     * @param  array<string, mixed>  $data  Validated payload
     *
     * @throws DomainException When the quotation is not in draft status
     */
    public function update(Quotation $quotation, array $data, ?User $actor = null): Quotation
    {
        if (! $quotation->isDraft()) {
            throw new DomainException(
                "Quotation #{$quotation->quotation_number} cannot be edited — "
                ."only draft quotations are editable. Current status: '{$quotation->status}'."
            );
        }

        return DB::transaction(function () use ($quotation, $data, $actor): Quotation {
            $taxRateBps = isset($data['tax_rate_bps'])
                ? (int) $data['tax_rate_bps']
                : (int) $quotation->tax_rate_bps;

            $lines  = $this->normaliseLines($data['items'] ?? []);
            $totals = $this->calculateTotals(
                lines: $lines,
                discountCents: (int) ($data['discount_cents'] ?? 0),
                taxRateBps: $taxRateBps,
            );

            $updateData = [
                'branch_id'      => $data['branch_id'] ?? $quotation->branch_id,
                'customer_id'    => $data['customer_id'] ?? $quotation->customer_id,
                'issue_date'     => $data['issue_date'] ?? $quotation->issue_date?->toDateString(),
                'valid_until'    => $data['valid_until'] ?? $quotation->valid_until?->toDateString(),
                'subtotal_cents' => $totals['subtotal_cents'],
                'discount_cents' => $totals['discount_cents'],
                'tax_rate_bps'   => $taxRateBps,
                'tax_cents'      => $totals['tax_cents'],
                'total_cents'    => $totals['total_cents'],
                'notes'          => array_key_exists('notes', $data) ? $data['notes'] : $quotation->notes,
                'terms'          => array_key_exists('terms', $data) ? $data['terms'] : $quotation->terms,
                'assigned_to'    => array_key_exists('assigned_to', $data) ? $data['assigned_to'] : $quotation->assigned_to,
            ];

            $this->quotations->update($quotation, $updateData);

            // Replace line items wholesale — simpler and safer than diffing.
            $quotation->items()->delete();
            $this->persistLines($quotation, $lines);

            Log::info('Quotation updated', [
                'quotation_id'     => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'tenant_id'        => $quotation->tenant_id,
                'subtotal_cents'   => $totals['subtotal_cents'],
                'discount_cents'   => $totals['discount_cents'],
                'tax_cents'        => $totals['tax_cents'],
                'total_cents'      => $totals['total_cents'],
                'actor_id'         => $actor?->id,
            ]);

            return $quotation->fresh()->load('items');
        });
    }

    // ── Totals ────────────────────────────────────────────────────────────────

    /**
     * Pure calculation of quotation totals from line data.
     *
     * All arithmetic is integer-only — no float intermediaries. Tax is computed
     * in basis points (bps): 1 bps = 0.01%, so 1300 bps = 13%.
     *
     * Rounding rule: intdiv (truncate towards zero / floor for positive values).
     * This ensures tax_cents never exceeds what the exact formula yields —
     * rounding up would systematically overcharge customers. See class docblock.
     *
     * Discount clamp: if discount_cents > subtotal_cents, discount is silently
     * clamped to subtotal_cents so taxableBase = 0. A discount that exceeds the
     * invoice value still produces a zero-total quotation — it never goes negative.
     *
     * @param  list<array{quantity: int, unit_price_cents: int}>  $lines
     * @return array{subtotal_cents: int, discount_cents: int, tax_cents: int, total_cents: int}
     */
    public function calculateTotals(array $lines, int $discountCents, int $taxRateBps): array
    {
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += (int) $line['quantity'] * (int) $line['unit_price_cents'];
        }

        // Clamp discount so the taxable base is never negative.
        $clampedDiscount = min($discountCents, $subtotal);
        $taxableBase     = $subtotal - $clampedDiscount;

        // intdiv truncates towards zero — equivalent to floor() for non-negative
        // integers and avoids any float intermediary or banker's rounding.
        $taxCents = intdiv($taxableBase * $taxRateBps, 10_000);
        $total    = $taxableBase + $taxCents;

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $clampedDiscount,
            'tax_cents'      => $taxCents,
            'total_cents'    => $total,
        ];
    }

    // ── State machine ─────────────────────────────────────────────────────────

    /**
     * Advance the quotation to a new status, recording the transition in the history.
     *
     * Validates the transition against the state machine map. Throws DomainException
     * for any invalid move — including unknown status values and terminal states.
     *
     * The DB write (status update + history row) is wrapped in a transaction so
     * that a partial write can never leave the quotation in an inconsistent state.
     *
     * @throws DomainException When the transition is not allowed by the state machine
     */
    public function transitionTo(
        Quotation $quotation,
        string $toStatus,
        ?User $actor = null,
        ?string $note = null,
    ): Quotation {
        $fromStatus = $quotation->status;

        $this->assertTransitionAllowed(quotation: $quotation, toStatus: $toStatus);

        DB::transaction(function () use ($quotation, $fromStatus, $toStatus, $actor, $note): void {
            $quotation->update(['status' => $toStatus]);

            QuotationStatusHistory::create([
                'tenant_id'    => $quotation->tenant_id,
                'quotation_id' => $quotation->id,
                'from_status'  => $fromStatus,
                'to_status'    => $toStatus,
                'user_id'      => $actor?->id,
                'note'         => $note,
            ]);
        });

        Log::info('Quotation status transitioned', [
            'quotation_id'     => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'tenant_id'        => $quotation->tenant_id,
            'from_status'      => $fromStatus,
            'to_status'        => $toStatus,
            'actor_id'         => $actor?->id,
        ]);

        return $quotation->fresh()->load('statusHistory');
    }

    /**
     * Return the list of valid next statuses for the quotation's current status.
     *
     * Returns an empty array for terminal statuses (accepted, rejected, expired).
     * The frontend uses this to render only the valid action buttons.
     *
     * @return list<string>
     */
    public function allowedTransitions(Quotation $quotation): array
    {
        return self::TRANSITIONS[$quotation->status] ?? [];
    }

    /**
     * Mark the quotation as sent to the customer.
     *
     * @throws DomainException When the current status does not allow a 'sent' transition
     */
    public function markSent(Quotation $quotation, ?User $actor = null, ?string $note = null): Quotation
    {
        return $this->transitionTo(
            quotation: $quotation,
            toStatus: 'sent',
            actor: $actor,
            note: $note,
        );
    }

    /**
     * Accept the quotation, optionally converting it to an Order in the same transaction.
     *
     * When convertToOrder is false: plain status transition to 'accepted' (same as before).
     *
     * When convertToOrder is true, inside a single DB transaction this method:
     *   (a) Transitions the quotation to 'accepted' via transitionTo() so the history row
     *       is written correctly.
     *   (b) Resolves the branch to attach the order to (quotation.branch if set, else the
     *       tenant's main branch). Throws if neither exists.
     *   (c) Calls OrderService::createFromQuotation() with the quotation's full cents
     *       breakdown, customer, and actor.
     *   (d) Sets quotation.converted_order_id = order.id.
     *
     * Idempotency guard: if converted_order_id is already set, the quotation has already
     * been converted — calling this again would create a duplicate order. A DomainException
     * is thrown instead. The transition guard in transitionTo() also blocks conversion of
     * terminal states (accepted/rejected/expired), but we surface an explicit message here
     * so callers get a meaningful error rather than a state-machine message.
     *
     * @throws DomainException When the transition is not allowed, or already converted
     */
    public function accept(
        Quotation $quotation,
        ?User $actor = null,
        bool $convertToOrder = false,
        ?string $note = null,
    ): Quotation {
        if ($convertToOrder) {
            return $this->acceptAndConvert(quotation: $quotation, actor: $actor, note: $note);
        }

        return $this->transitionTo(
            quotation: $quotation,
            toStatus: 'accepted',
            actor: $actor,
            note: $note,
        );
    }

    /**
     * Reject the quotation.
     *
     * @throws DomainException When the current status does not allow a 'rejected' transition
     */
    public function reject(Quotation $quotation, ?User $actor = null, ?string $note = null): Quotation
    {
        return $this->transitionTo(
            quotation: $quotation,
            toStatus: 'rejected',
            actor: $actor,
            note: $note,
        );
    }

    /**
     * Write the initial history row when a quotation is first created.
     *
     * Called immediately after persisting the new quotation. from_status=null
     * signals that this is the birth of the quotation into its initial status,
     * not a transition from a prior state.
     *
     * Idempotent by convention: the create flow calls this exactly once.
     */
    public function recordInitialHistory(Quotation $quotation, ?User $actor = null): void
    {
        QuotationStatusHistory::create([
            'tenant_id'    => $quotation->tenant_id,
            'quotation_id' => $quotation->id,
            'from_status'  => null,
            'to_status'    => $quotation->status,
            'user_id'      => $actor?->id,
            'note'         => 'Cotizacion creada',
        ]);

        Log::info('Quotation initial history recorded', [
            'quotation_id'     => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'tenant_id'        => $quotation->tenant_id,
            'initial_status'   => $quotation->status,
            'actor_id'         => $actor?->id,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Accept the quotation and convert it to an Order atomically.
     *
     * Extracted from accept() to keep the public API clean. All logic runs inside
     * a single DB::transaction so that a failure mid-way (e.g. branch lookup, order
     * creation) leaves the quotation untouched — no partially-accepted rows.
     *
     * @throws DomainException When already converted, transition blocked, or no branch found
     */
    private function acceptAndConvert(Quotation $quotation, ?User $actor, ?string $note): Quotation
    {
        // Idempotency guard: a second conversion would create a duplicate order.
        // This check runs before the transaction so the error message is clear.
        if ($quotation->converted_order_id !== null) {
            throw new DomainException(
                "Quotation #{$quotation->quotation_number} has already been converted to order "
                ."#{$quotation->converted_order_id}. Cannot convert again."
            );
        }

        return DB::transaction(function () use ($quotation, $actor, $note): Quotation {
            // (a) Transition to 'accepted' — transitionTo() validates the state machine
            //     and writes the history row. DomainException propagates for terminal states.
            $this->transitionTo(
                quotation: $quotation,
                toStatus: 'accepted',
                actor: $actor,
                note: $note,
            );

            // Refresh so we have the updated status on the model before branch resolution.
            $quotation->refresh();

            // (b) Resolve the branch for the new order.
            $branch = $this->resolveBranchForQuotation($quotation);

            // (c) Create the order via OrderService (crossing the module boundary
            //     with only Order-domain primitives — no Quotation type is passed).
            $order = $this->orderService->createFromQuotation(
                branch: $branch,
                subtotalCents: $quotation->subtotal_cents,
                taxCents: $quotation->tax_cents,
                discountCents: $quotation->discount_cents,
                totalCents: $quotation->total_cents,
                customer: $quotation->customer_id !== null ? $quotation->customer : null,
                user: $actor,
                notes: "Convertido desde cotizacion {$quotation->quotation_number}",
            );

            // (d) Link the converted order back to the quotation.
            $quotation->update(['converted_order_id' => $order->id]);

            Log::info('Quotation converted to order', [
                'quotation_id'     => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
                'tenant_id'        => $quotation->tenant_id,
                'order_id'         => $order->id,
                'order_number'     => $order->order_number,
                'total_cents'      => $quotation->total_cents,
                'actor_id'         => $actor?->id,
            ]);

            return $quotation->fresh()->load('statusHistory');
        });
    }

    /**
     * Resolve the branch for a quotation, falling back to the tenant's main branch.
     *
     * Mirrors ReservationService::resolveBranchForReservation() exactly.
     *
     * @throws DomainException When neither the quotation's branch nor a main branch exists
     */
    private function resolveBranchForQuotation(Quotation $quotation): Branch
    {
        if ($quotation->branch_id !== null) {
            $branch = $quotation->branch;

            if ($branch !== null) {
                return $branch;
            }
        }

        // Fall back to the tenant's main branch — covers quotations created before
        // branch assignment was introduced, or single-branch tenants.
        $mainBranch = Branch::where('tenant_id', $quotation->tenant_id)
            ->where('is_main', true)
            ->first();

        if ($mainBranch === null) {
            throw new DomainException(
                "No branch available to convert quotation #{$quotation->quotation_number}. "
                .'Assign a branch to the quotation or configure a main branch for the tenant.'
            );
        }

        return $mainBranch;
    }

    /**
     * Resolve the current tenant from the service container.
     *
     * Prefers the container-bound currentTenant (HTTP context). Falls back to a
     * direct find via the Auth facade for queue / CLI context.
     *
     * @throws DomainException When no tenant context can be resolved
     */
    private function resolveTenant(): Tenant
    {
        /** @var Tenant|null $current */
        $current = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($current instanceof Tenant) {
            return $current;
        }

        throw new DomainException('No active tenant context found. Cannot create quotation.');
    }

    /**
     * Assert that $toStatus is a valid next step from the quotation's current status.
     *
     * @throws DomainException When $toStatus is unknown or not reachable from current status
     */
    private function assertTransitionAllowed(Quotation $quotation, string $toStatus): void
    {
        if (! array_key_exists($toStatus, self::TRANSITIONS)) {
            throw new DomainException(
                "'{$toStatus}' is not a recognised quotation status."
            );
        }

        $allowed = self::TRANSITIONS[$quotation->status] ?? null;

        // Current status is not in the map — should not happen with clean data, but be safe.
        if ($allowed === null) {
            throw new DomainException(
                "Quotation #{$quotation->quotation_number} has an unrecognised status '{$quotation->status}'."
            );
        }

        if (! in_array($toStatus, $allowed, strict: true)) {
            throw new DomainException(
                "Cannot transition quotation #{$quotation->quotation_number} "
                ."from '{$quotation->status}' to '{$toStatus}'."
            );
        }
    }

    /**
     * Normalise the lines array so each entry carries typed int values.
     *
     * The input may come from a validated request payload where numeric
     * strings are common. Casting here once avoids repetitive casts in the
     * caller and in calculateTotals().
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{description: string, quantity: int, unit_price_cents: int, sort_order: int, product_id: int|null}>
     */
    private function normaliseLines(array $items): array
    {
        $normalised = [];

        foreach ($items as $i => $item) {
            $normalised[] = [
                'description'      => (string) ($item['description'] ?? ''),
                'quantity'         => (int) ($item['quantity'] ?? 1),
                'unit_price_cents' => (int) ($item['unit_price_cents'] ?? 0),
                'sort_order'       => (int) ($item['sort_order'] ?? $i),
                'product_id'       => isset($item['product_id']) ? (int) $item['product_id'] : null,
            ];
        }

        return $normalised;
    }

    /**
     * Persist line items for a quotation, computing line_total_cents per row.
     *
     * Each row's line_total_cents = quantity * unit_price_cents.
     * tenant_id is copied from the parent quotation so the item rows satisfy
     * the BelongsToTenant scope without requiring the caller to pass it explicitly.
     *
     * @param  list<array{description: string, quantity: int, unit_price_cents: int, sort_order: int, product_id: int|null}>  $lines
     */
    private function persistLines(Quotation $quotation, array $lines): void
    {
        foreach ($lines as $line) {
            QuotationItem::create([
                'tenant_id'        => $quotation->tenant_id,
                'quotation_id'     => $quotation->id,
                'product_id'       => $line['product_id'],
                'description'      => $line['description'],
                'quantity'         => $line['quantity'],
                'unit_price_cents' => $line['unit_price_cents'],
                'line_total_cents' => $line['quantity'] * $line['unit_price_cents'],
                'sort_order'       => $line['sort_order'],
            ]);
        }
    }
}
