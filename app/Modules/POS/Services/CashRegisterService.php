<?php

declare(strict_types=1);

namespace App\Modules\POS\Services;

use App\Models\User;
use App\Modules\POS\Models\CashRegisterSession;
use App\Modules\Tenancy\Models\Branch;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Cash-register session lifecycle (open / close / arqueo). One open session per cashier
 * per branch. The expected cash at close is opening + the session's cash sales; the
 * difference against the counted amount is the arqueo (over/short). Money in cents.
 */
final class CashRegisterService
{
    /**
     * Open a session for a cashier at a branch. Rejects a second concurrent open session
     * for the same cashier + branch.
     */
    public function open(Branch $branch, User $cashier, int $openingAmountCents, ?string $notes = null): CashRegisterSession
    {
        return DB::transaction(function () use ($branch, $cashier, $openingAmountCents, $notes): CashRegisterSession {
            if ($this->hasOpen($branch, $cashier)) {
                throw new DomainException('Ya tienes una caja abierta en esta sucursal.');
            }

            return CashRegisterSession::create([
                'branch_id' => $branch->id,
                'user_id' => $cashier->id,
                'session_number' => $this->nextSessionNumber($branch),
                'opening_amount_cents' => $openingAmountCents,
                'status' => 'open',
                'opened_at' => now(),
                'opening_notes' => $notes,
            ]);
        });
    }

    /**
     * Close a session: compute expected (cash sales) and the difference against the
     * counted amount. Only the owning cashier, or an owner/admin, may close.
     */
    public function close(CashRegisterSession $session, int $closingAmountCents, ?string $notes, User $actor): CashRegisterSession
    {
        if ($session->isClosed()) {
            throw new DomainException('La caja ya está cerrada.');
        }

        if ($actor->id !== $session->user_id && ! in_array($actor->currentRole(), ['owner', 'admin'], strict: true) && ! $actor->is_super_admin) {
            throw new DomainException('No tenés permiso para cerrar esta caja.');
        }

        $expected = $this->cashSalesCents($session);

        $session->update([
            'closing_amount_cents' => $closingAmountCents,
            'expected_amount_cents' => $expected,
            'difference_cents' => $closingAmountCents - ($session->opening_amount_cents + $expected),
            'status' => 'closed',
            'closed_at' => now(),
            'closing_notes' => $notes,
        ]);

        return $session->fresh();
    }

    /** The cashier's currently open session at a branch, if any. */
    public function currentFor(Branch $branch, User $cashier): ?CashRegisterSession
    {
        return CashRegisterSession::query()
            ->where('branch_id', $branch->id)
            ->where('user_id', $cashier->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    public function hasOpen(Branch $branch, User $cashier): bool
    {
        return $this->currentFor($branch, $cashier) !== null;
    }

    /** Expected cash in the drawer from sales = opening is added separately at close. */
    public function cashSalesCents(CashRegisterSession $session): int
    {
        return $session->cash_sales_cents;
    }

    private function nextSessionNumber(Branch $branch): int
    {
        $max = CashRegisterSession::query()
            ->where('tenant_id', $branch->tenant_id)
            ->max('session_number');

        return (int) $max + 1;
    }
}
