# POS Tax (IVA) Calculation — Design

**Goal:** Replace the hardcoded `tax_cents = 0` in POS sales with a real per-tenant IVA calculation, driven by the existing `tax` settings group, supporting both tax-exclusive and tax-inclusive pricing.

**Origin:** TODO(#80) in `OrderService`. The `tax` settings group (`enabled`, `rate_bps`) and the `PosCartPanel` IVA row already exist but are inert. Quotations already compute tax; this brings POS to parity.

**Scope (v1):** POS sales only (`OrderService::createFromPos`). Reservation- and quotation-derived orders are out of scope (quotations compute their own tax; reservation totals are negotiated deposits). The WhatsApp storefront checkout creates no server-side order and is unaffected.

---

## Decisions (approved)

- **Configurable per tenant:** a `prices_include_tax` flag decides exclusive vs inclusive. Branch-aware, like the rest of the `tax` group.
- **Server is the source of truth:** `createFromPos` computes and persists the breakdown. The POS cart preview is a client-side mirror for responsiveness only.
- **Snapshot the rate:** persist `tax_rate_bps` on the order (new nullable column) for historical audit, mirroring quotations.

---

## The tax model

Money is integer cents throughout. `rate_bps` is basis points (1300 = 13%). All math is integer; rounding is chosen so `subtotal_cents + tax_cents == total_cents` holds exactly (no drift).

Let `items` = sum of line totals (cents), `discount` = discount cents (0 in v1; POS has no discount UI yet, but the calculator accepts it for forward-compat), `bps` = rate.

**Disabled** (`enabled == false` OR `bps <= 0`):
```
subtotal = items
tax      = 0
total    = max(0, items - discount)
```

**Exclusive** (`prices_include_tax == false`) — price is pre-IVA, IVA added on top (matches QuotationService):
```
base     = max(0, items - discount)
tax      = intdiv(base * bps, 10_000)      // floor: never collect more than the rate
subtotal = items
total    = base + tax
```

**Inclusive** (`prices_include_tax == true`) — price already contains IVA; total is unchanged, IVA is broken out:
```
base     = max(0, items - discount)        // gross, IVA-inclusive
net      = intdiv(base * 10_000 + intdiv(10_000 + bps, 2), 10_000 + bps)   // round-half-up
tax      = base - net                       // derived → net + tax == base exactly
subtotal = net
total    = base
```

Invariant in every branch: `subtotal + tax == total` when `discount == 0`; with a discount, `subtotal - discount + tax == total` (exclusive) / `net + tax == total` (inclusive). Discount is clamped so the taxable base is never negative.

---

## Components

### 1. `TaxCalculator` (new domain unit)

`app/Modules/Orders/Support/TaxCalculator.php` — pure, no I/O, fully unit-testable.

```php
final class TaxCalculator
{
    /** @param array{enabled: bool, rate_bps: int, prices_include_tax: bool} $tax */
    public function compute(int $itemsCents, int $discountCents, array $tax): TaxBreakdown;
}
```

`TaxBreakdown` — a readonly value object: `subtotalCents`, `taxCents`, `discountCents`, `totalCents`, `rateBpsApplied` (the bps actually used, 0 when disabled).

### 2. Settings — `prices_include_tax`

- `config/tenant-settings.php`: add `'prices_include_tax' => false` to the `tax` defaults group (allow-list + coded default). Branch-aware via `branch_settings`.
- `UpdateSettingsRequest`: validate `tax.prices_include_tax` as `['required', 'boolean']`.
- Settings UI (Ajustes → Impuestos): a toggle "Los precios incluyen IVA", persisted through the existing settings write path. No new endpoint.

### 3. `OrderService::createFromPos`

Resolve the branch tax config with `BranchSetting::resolvedGroup('tax')`, call `TaxCalculator::compute($subtotalCents, 0, $tax)`, and persist `subtotal_cents`, `tax_cents`, `total_cents`, and the new `tax_rate_bps` from the breakdown. No other order path changes.

### 4. Migration

Add `tax_rate_bps` (unsigned small integer, nullable) to `orders`, after `tax_cents`. Nullable so existing rows are untouched; new POS orders snapshot the applied rate. Reversible `down()` drops the column.

### 5. POS live preview (frontend)

- `PosController::products` already returns `additional(['stock' => ...])`. Add `'tax' => ['enabled' => ..., 'rate_bps' => ..., 'prices_include_tax' => ...]` resolved from `BranchSetting::resolvedGroup('tax')` for the requested branch, so the terminal gets the config with the product grid it already loads.
- `resources/js/.../pos/computeTax.ts`: a TypeScript mirror of the three-branch formula above, returning `{ subtotalCents, taxCents, totalCents }`. Display-only.
- The POS store holds the branch tax config; `PosCartPanel` computes the live IVA line via `computeTax` as items change. `checkout` recomputes authoritatively server-side, so a stale client value can never persist a wrong total.

### 6. Display

- `PosCartPanel`: wire the existing IVA row to the computed `taxCents`. Label reflects the mode — "IVA (13%)" (exclusive) or "IVA incluido (13%)" (inclusive). When `enabled == false`, hide the IVA row entirely.
- `OrderDetailPage` and `PosReceipt` already read `tax_cents`; they now render a real value with no change.

---

## Error handling / edge cases

- `bps <= 0` or `enabled == false` → zero tax, no IVA row.
- Discount ≥ items → taxable base clamped to 0, tax 0.
- Rounding: inclusive uses round-half-up on `net`, then derives `tax = base - net` so the parts always reconcile to the total.
- Multi-tenant: the rate is resolved per branch via `BranchSetting::resolvedGroup('tax')`; tenant A's rate can never apply to tenant B's order (covered by an isolation test).

## Testing (dual-layer, non-negotiable)

**PHPUnit**
- `TaxCalculatorTest`: exclusive, inclusive, disabled, zero-rate, discount clamp, rounding boundary (e.g. odd cents), and the `subtotal + tax == total` invariant across a table of cases.
- `CreateFromPosTaxTest` (feature, RefreshDatabase + factories): POS order under exclusive settings persists non-zero `tax_cents` + `tax_rate_bps`; under inclusive settings the total equals the item sum with IVA broken out; under `enabled=false` tax is 0; tenant-isolation case.

**Playwright E2E**
- Owner sets a 13% rate in Ajustes → Impuestos → open POS → add items → assert the IVA row and total update → complete the sale → assert the receipt shows the IVA line. One run in exclusive mode; a second asserting the inclusive breakdown (total unchanged, IVA shown).

## Files

- New: `app/Modules/Orders/Support/TaxCalculator.php`, `app/Modules/Orders/Support/TaxBreakdown.php`
- New: `resources/js/components/Admin/Pos/computeTax.ts` (or `resources/js/utils/`)
- New: migration `..._add_tax_rate_bps_to_orders.php`
- Modified: `config/tenant-settings.php`, `app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php`, Settings tax UI component, `app/Modules/Orders/Services/OrderService.php` (`createFromPos`), `app/Modules/POS/Http/Controllers/Api/V1/PosController.php` (`products`), `resources/js/components/Admin/Pos/PosCartPanel.vue` + POS store
- New tests: `tests/Feature/Orders/TaxCalculatorTest.php`, `tests/Feature/Orders/CreateFromPosTaxTest.php`, `tests/e2e/admin/pos/pos-tax.spec.ts`

## Out of scope (v1)

- Tax on reservation- and quotation-derived orders.
- Per-line or per-category tax rates (single tenant/branch rate only).
- POS discounts (the calculator accepts a discount arg for forward-compat, but no POS discount UI ships here).
- Storefront/WhatsApp checkout tax.
