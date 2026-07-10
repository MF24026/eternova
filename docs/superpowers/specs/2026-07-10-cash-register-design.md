# Cash register (caja registradora) — Design

**Date:** 2026-07-10
**Status:** Approved (brainstorming)
**Reference:** POSLatam's completed cash epic (`CashRegisterService`, `CashRegisterSession`, `cash_flow`)
**Branch:** `feat/cash-register`

## Goal

Give the POS a cash-register session: a cashier **opens** the register with a starting
cash amount, sells during the shift, and **closes** it by counting the drawer — the
system computes the expected cash (opening + cash sales) and the **difference**
(arqueo: over/short). Mirrors POSLatam's epic, adapted to Eternova (multi-tenant,
multi-branch, cents, Vue SPA, vertical-gated).

The register is a **vertical-gated module** (`cash_register`) — retail / minimarket
enable it; a florist may not — plugging into the Phase 2 framework without changes to
its resolver.

## The complete epic (for architectural context)

1. **Session + arqueo** — open/close, expected vs counted, difference. Orders link to
   the session; the session aggregates totals by payment method. **(Slice 1 — this build)**
2. **Cash movements** — cash in/out (retiros/ingresos) during the shift, affecting
   expected. *(next slice)*
3. **Require-open-register** — a per-tenant setting that blocks POS checkout when no
   session is open. *(next slice)*
4. **UI** — open/close overlays in the POS + "Cierres de caja" history/arqueo. *(open/
   close is in Slice 1; history/report is a later slice)*
5. **Cashier stats / reports.** *(later slice)*

## Slice 1 — Session + arqueo + gating + open/close (this build)

### Data model

`cash_register_sessions` (new, `BelongsToTenant`):
- `tenant_id`, `branch_id` (FK constrained), `user_id` (cashier, FK constrained)
- `session_number` (per-tenant sequential, for the arqueo record)
- `opening_amount_cents` (bigint), `closing_amount_cents` (bigint, nullable — counted at close)
- `expected_amount_cents` (bigint, nullable — computed at close = cash sales)
- `difference_cents` (bigint, nullable — closing − (opening + expected))
- `status` (enum `open` | `closed`, default `open`)
- `opened_at`, `closed_at` (nullable), `opening_notes`, `closing_notes` (nullable text)
- Indexes: `(tenant_id, status)`, `(branch_id, status)`, `(user_id, status)`.
- Money in **cents** (bigint), never decimal.

`orders`: add `cash_register_session_id` (nullable, FK `nullOnDelete`, indexed). Set on
POS checkout when the cashier has an open session at that branch; null otherwise (so
existing/non-register sales keep working).

### Service — `App\Modules\POS\Services\CashRegisterService`

- `open(Branch $branch, User $cashier, int $openingAmountCents, ?string $notes): CashRegisterSession`
  — rejects (DomainException) if the cashier already has an open session at that branch;
  assigns the next `session_number` for the tenant; `status=open`, `opened_at=now`.
- `close(CashRegisterSession $session, int $closingAmountCents, ?string $notes, User $actor): CashRegisterSession`
  — only the owning cashier or an owner/admin; rejects if already closed; computes
  `expected_amount_cents = cashSalesCents($session)`,
  `difference_cents = closingAmountCents − (opening_amount_cents + expected_amount_cents)`;
  `status=closed`, `closed_at=now`.
- `currentFor(Branch $branch, User $cashier): ?CashRegisterSession` — the open session.
- `hasOpen(Branch $branch, User $cashier): bool`.
- `cashSalesCents(CashRegisterSession $session): int` — sum of `total_cents` of the
  session's linked orders where `payment_method = 'cash'` (excludes card/transfer/other).

The session model exposes read accessors for the arqueo view: `cash_sales_cents`,
`card_sales_cents`, `transfer_sales_cents`, `total_sales_cents`, `order_count` (derived
from linked orders), so the UI shows the breakdown at close.

### POS integration

`OrderService::createFromPos` already receives `Branch` + `User` + `paymentMethod`.
Add: after building the order, if the cashier has an open session at the branch, set the
order's `cash_register_session_id`. This is a **link, not a gate** — Slice 1 does not
block selling without an open register (that is Slice 3). Keep the existing signature;
resolve the open session inside via `CashRegisterService::currentFor`.

### API — `/api/v1/pos/cash-register`

Under `['auth:sanctum', 'tenant', 'module:cash_register']` (server-side gating, Phase 2):
- `GET /pos/cash-register/current` → the cashier's open session for a `branch_id` (or null),
  with the live totals breakdown.
- `POST /pos/cash-register/open` → `{ branch_id, opening_amount_cents, opening_notes? }`.
- `POST /pos/cash-register/{session}/close` → `{ closing_amount_cents, closing_notes? }`
  → returns the closed session with expected/difference (the arqueo).

Thin controller → `CashRegisterService`; Form Requests for open/close; a
`CashRegisterSessionResource`. Authorization: opening/closing requires an
owner/admin/staff role that can operate the POS (reuse the POS gate).

### Vertical gating

Add `cash_register` to `ModuleVisibilityService::GATEABLE` and to the giros that want it
in `config/verticals.php` (ropa_boutique, accesorios, minimarket, peluches → on;
floreria_regalos, otro → off by default — a florist rarely runs a drawer arqueo). Route
group carries `module:cash_register`. Nav/Settings module toggle come along for free
(Phase 2 framework).

### Frontend

- `useCashRegister` composable + service (axios to the endpoints).
- POS page: an "Abrir caja" affordance when no open session; when open, show the session
  chip (opening + live cash total) and a "Cerrar caja" action.
- **Open overlay**: opening amount (money input) + notes → opens.
- **Close overlay (arqueo)**: shows the breakdown (cash/card/transfer/total, order count,
  expected), a counted-amount input, computes the live difference (over/short), notes →
  closes and shows the result. House overlay pattern (Teleport, scrim, z-90, dark).
- The cash-register UI only appears when the tenant has the `cash_register` module enabled.

## Testing (dual-layer)

- **PHPUnit:**
  - open creates a session (status open, session_number assigned); a second open for the
    same cashier+branch is rejected.
  - close computes expected (= cash orders) and difference correctly (over, short, exact),
    sets status closed; closing an already-closed session is rejected; only owner/admin or
    the owning cashier can close.
  - POS checkout with an open session links the order (`cash_register_session_id`);
    checkout without a session leaves it null; card/transfer orders do not count toward
    `expected` (only cash does).
  - the `cash_register` route group 403s when the tenant has the module disabled (giro).
  - tenant isolation: a session/arqueo never leaks across tenants.
- **Playwright e2e:** open a register in the POS, make a cash sale, close counting an
  amount → the arqueo shows the expected and the difference. (Uses a tenant whose giro
  enables `cash_register`.)

## Out of scope (this slice)

- Cash movements / cash_flow (Slice 2).
- Require-open-register enforcement at checkout (Slice 3).
- Cierres-de-caja history page + cashier stats/reports (later slices).
- Thermal print of the arqueo (pairs with the future printing phase).

## Global constraints

- No emojis anywhere. No AI co-author trailer. Conventional Commits.
- Feature branch → PR against `develop`; squash-merge.
- Multi-tenant: `BelongsToTenant` on the session; money in cents; never leak across tenants.
- Vertical-gated: server-side `module:cash_register` (403 when disabled), not just nav.
- `abort(403)` renders as 500 in this app — raise `AccessDeniedHttpException` for 403s.
- House overlay pattern for the open/close flows; dark mode first-class.
- Dual-layer testing (PHPUnit + Playwright) before the PR closes.
