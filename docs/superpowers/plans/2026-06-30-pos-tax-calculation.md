# POS Tax (IVA) Calculation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded `tax_cents = 0` in POS sales with a real per-tenant IVA calculation driven by the `tax` settings group, supporting tax-exclusive and tax-inclusive pricing.

**Architecture:** A pure `TaxCalculator` domain unit computes the breakdown from integer cents. `OrderService::createFromPos` resolves the branch's `tax` settings and calls it (server = source of truth), snapshotting `tax_rate_bps` on the order. The POS terminal mirrors the formula in TypeScript for a live cart preview; the server recomputes at checkout.

**Tech Stack:** Laravel 12 (PHP 8.4), Pest/PHPUnit, Vue 3 + TS, Playwright. Money in integer cents; `rate_bps` = basis points.

## Global Constraints

- Money is integer cents; `rate_bps` is basis points (1300 = 13%). All tax math is integer.
- Rounding must keep `subtotal_cents + tax_cents == total_cents` exactly (derive one part from the other; never round both).
- Spanish UI copy, English code/identifiers. No emojis anywhere. No `Co-Authored-By` trailer in commits.
- Scope is POS only (`createFromPos`). Do NOT touch `createFromReservation` or `createFromQuotation`.
- Dual-layer testing is mandatory: PHPUnit feature/unit (factories + RefreshDatabase, no DB mocking) AND Playwright E2E for UI flows.
- Reference spec: `docs/superpowers/specs/2026-06-30-pos-tax-calculation-design.md`.

---

### Task 1: TaxCalculator domain unit

**Files:**
- Create: `app/Modules/Orders/Support/TaxBreakdown.php`
- Create: `app/Modules/Orders/Support/TaxCalculator.php`
- Test: `tests/Feature/Orders/TaxCalculatorTest.php`

**Interfaces:**
- Produces: `TaxCalculator::compute(int $itemsCents, int $discountCents, array $tax): TaxBreakdown` where `$tax` has keys `enabled` (bool), `rate_bps` (int), `prices_include_tax` (bool). `TaxBreakdown` is `readonly` with public int props `subtotalCents`, `taxCents`, `discountCents`, `totalCents`, `rateBpsApplied`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Orders\Support\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    private function tax(bool $enabled, int $rateBps, bool $inclusive): array
    {
        return ['enabled' => $enabled, 'rate_bps' => $rateBps, 'prices_include_tax' => $inclusive];
    }

    public function test_disabled_yields_zero_tax(): void
    {
        $b = (new TaxCalculator())->compute(10_000, 0, $this->tax(false, 1300, false));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(0, $b->taxCents);
        $this->assertSame(10_000, $b->totalCents);
        $this->assertSame(0, $b->rateBpsApplied);
    }

    public function test_zero_rate_yields_zero_tax(): void
    {
        $b = (new TaxCalculator())->compute(10_000, 0, $this->tax(true, 0, false));
        $this->assertSame(0, $b->taxCents);
        $this->assertSame(10_000, $b->totalCents);
    }

    public function test_exclusive_adds_tax_on_top(): void
    {
        // 13% of 10_000 = 1_300; total 11_300
        $b = (new TaxCalculator())->compute(10_000, 0, $this->tax(true, 1300, false));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(1_300, $b->taxCents);
        $this->assertSame(11_300, $b->totalCents);
        $this->assertSame(1300, $b->rateBpsApplied);
    }

    public function test_inclusive_breaks_tax_out_without_changing_total(): void
    {
        // 11_300 gross incl 13%: net = round(11300*10000/11300) = 10_000; tax = 1_300
        $b = (new TaxCalculator())->compute(11_300, 0, $this->tax(true, 1300, true));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(1_300, $b->taxCents);
        $this->assertSame(11_300, $b->totalCents);
    }

    public function test_discount_clamped_and_taxed_on_net_base(): void
    {
        // exclusive: base = 10_000 - 3_000 = 7_000; tax = 910; total = 7_910
        $b = (new TaxCalculator())->compute(10_000, 3_000, $this->tax(true, 1300, false));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(3_000, $b->discountCents);
        $this->assertSame(910, $b->taxCents);
        $this->assertSame(7_910, $b->totalCents);
    }

    public function test_discount_larger_than_items_clamps_to_zero_base(): void
    {
        $b = (new TaxCalculator())->compute(5_000, 9_000, $this->tax(true, 1300, false));
        $this->assertSame(5_000, $b->discountCents); // clamped to items
        $this->assertSame(0, $b->taxCents);
        $this->assertSame(0, $b->totalCents);
    }

    public function test_invariant_subtotal_plus_tax_minus_discount_equals_total(): void
    {
        foreach ([[7_777, 0, 1300, false], [12_345, 1_111, 1300, true], [99_999, 0, 700, true]] as [$i, $d, $r, $inc]) {
            $b = (new TaxCalculator())->compute($i, $d, $this->tax(true, $r, $inc));
            $reconstructed = $inc ? ($b->subtotalCents + $b->taxCents) : ($b->subtotalCents - $b->discountCents + $b->taxCents);
            $this->assertSame($b->totalCents, $reconstructed, "case items={$i} disc={$d} bps={$r} inc=" . ($inc ? '1' : '0'));
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/phpunit tests/Feature/Orders/TaxCalculatorTest.php`
Expected: FAIL — `Class "App\Modules\Orders\Support\TaxCalculator" not found`.

- [ ] **Step 3: Write the DTO**

`app/Modules/Orders/Support/TaxBreakdown.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Immutable result of a tax computation. All values are integer cents except
 * rateBpsApplied (basis points). Invariant: subtotalCents + taxCents == totalCents
 * (inclusive) or subtotalCents - discountCents + taxCents == totalCents (exclusive).
 */
final readonly class TaxBreakdown
{
    public function __construct(
        public int $subtotalCents,
        public int $taxCents,
        public int $discountCents,
        public int $totalCents,
        public int $rateBpsApplied,
    ) {}
}
```

- [ ] **Step 4: Write the calculator**

`app/Modules/Orders/Support/TaxCalculator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Pure per-tenant tax computation over integer cents. Mirrors QuotationService's
 * exclusive formula and adds an inclusive mode. Rounding derives one part from the
 * other so the breakdown always reconciles to the total.
 *
 * @phpstan-param array{enabled?: bool, rate_bps?: int, prices_include_tax?: bool} $tax
 */
final class TaxCalculator
{
    public function compute(int $itemsCents, int $discountCents, array $tax): TaxBreakdown
    {
        $enabled   = (bool) ($tax['enabled'] ?? false);
        $rateBps   = (int) ($tax['rate_bps'] ?? 0);
        $inclusive = (bool) ($tax['prices_include_tax'] ?? false);

        $discount = min(max(0, $discountCents), max(0, $itemsCents));

        if (! $enabled || $rateBps <= 0) {
            return new TaxBreakdown($itemsCents, 0, $discount, max(0, $itemsCents - $discount), 0);
        }

        $base = $itemsCents - $discount; // >= 0 by the clamp above

        if ($inclusive) {
            // net = round-half-up(base * 10000 / (10000 + bps)); tax = base - net.
            $divisor = 10_000 + $rateBps;
            $net     = intdiv($base * 10_000 + intdiv($divisor, 2), $divisor);
            $tax     = $base - $net;

            return new TaxBreakdown($net, $tax, $discount, $base, $rateBps);
        }

        // Exclusive: floor tax, add on top (matches QuotationService::calculateTotals).
        $tax = intdiv($base * $rateBps, 10_000);

        return new TaxBreakdown($itemsCents, $tax, $discount, $base + $tax, $rateBps);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail exec -T laravel.test ./vendor/bin/phpunit tests/Feature/Orders/TaxCalculatorTest.php`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Orders/Support tests/Feature/Orders/TaxCalculatorTest.php
git commit -m "feat(orders): pure TaxCalculator for exclusive/inclusive IVA"
```

---

### Task 2: prices_include_tax setting

**Files:**
- Modify: `config/tenant-settings.php` (the `tax` block under `defaults`)
- Modify: `app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php:66-69` (tax rules)
- Test: `tests/Feature/Settings/SettingsApiTest.php` (add one test)

**Interfaces:**
- Produces: `BranchSetting::resolvedGroup('tax')` now includes key `prices_include_tax` (bool, default false).

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/Settings/SettingsApiTest.php` inside the class:

```php
public function test_owner_can_toggle_prices_include_tax(): void
{
    [$owner, $tenant] = $this->ownerAndTenant(); // existing helper used by sibling tests
    $this->actingAsTenantMember($owner, $tenant);

    $this->putJson('/api/v1/settings/tax', [
        'enabled' => true,
        'rate_bps' => 1300,
        'id_label' => 'NIT',
        'id_number' => null,
        'prices_include_tax' => true,
    ])->assertOk();

    $this->assertTrue(\App\Modules\Settings\Models\BranchSetting::resolvedGroup('tax')['prices_include_tax']);
}
```

> Note for implementer: match the exact auth/setup helpers the neighbouring brand/tax tests in this file already use (`ownerAndTenant`, `actingAsTenantMember`, or their local equivalents). Read the top of the file first and copy its setup pattern verbatim.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Settings/SettingsApiTest.php --filter=prices_include_tax`
Expected: FAIL — validation rejects the unknown key OR the resolved value is missing/false.

- [ ] **Step 3: Add the coded default** — in `config/tenant-settings.php`, the `tax` array under `defaults`:

```php
        // Tax — IVA toggle + rate (basis points) + local tax-id label/number.
        'tax' => [
            'enabled'            => true,
            'rate_bps'           => 1300,   // 13% IVA (El Salvador default)
            'id_label'           => null,   // e.g. 'NIT' (SV), 'RFC' (MX), 'NIT' (CO)
            'id_number'          => null,
            'prices_include_tax' => false,  // false = IVA added on top; true = price already includes IVA
        ],
```

- [ ] **Step 4: Add validation** — in `UpdateSettingsRequest.php`, the `tax` group rules (near line 66):

```php
                'enabled'            => ['required', 'boolean'],
                'rate_bps'           => ['required', 'integer', 'min:0', 'max:9999'],
                'id_label'           => ['nullable', 'string', 'max:20'],
                'id_number'          => ['nullable', 'string', 'max:40', new ValidTaxId($this->tenantCountryCode())],
                'prices_include_tax' => ['required', 'boolean'],
```

> Keep the existing keys/rules that are already there; only add the `prices_include_tax` line and preserve `id_label`/`id_number` exactly as they currently are.

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Settings/SettingsApiTest.php --filter=prices_include_tax`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add config/tenant-settings.php app/Modules/Settings/Http/Requests/UpdateSettingsRequest.php tests/Feature/Settings/SettingsApiTest.php
git commit -m "feat(settings): add prices_include_tax flag to the tax group"
```

---

### Task 3: Migration — snapshot tax_rate_bps on orders

**Files:**
- Create: `database/migrations/2026_06_30_000001_add_tax_rate_bps_to_orders.php`

**Interfaces:**
- Produces: `orders.tax_rate_bps` (unsigned small int, nullable) available for `createFromPos` in Task 4.

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Basis points snapshot of the rate applied at sale time (nullable:
            // pre-existing orders and non-POS sources leave it null).
            $table->unsignedSmallInteger('tax_rate_bps')->nullable()->after('tax_cents');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tax_rate_bps');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `./vendor/bin/sail artisan migrate`
Expected: `... add_tax_rate_bps_to_orders ... DONE`.

- [ ] **Step 3: Verify rollback works**

Run: `./vendor/bin/sail artisan migrate:rollback --step=1 && ./vendor/bin/sail artisan migrate`
Expected: rollback drops the column, re-migrate re-adds it, both DONE.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_30_000001_add_tax_rate_bps_to_orders.php
git commit -m "feat(orders): add nullable tax_rate_bps snapshot column"
```

---

### Task 4: createFromPos computes and persists tax

**Files:**
- Modify: `app/Modules/Orders/Services/OrderService.php` (`createFromPos`, the tax block ~line 102-125)
- Test: `tests/Feature/Orders/CreateFromPosTaxTest.php`

**Interfaces:**
- Consumes: `TaxCalculator::compute(...)` (Task 1), `BranchSetting::resolvedGroup('tax')` (returns the array incl. `prices_include_tax` from Task 2), `orders.tax_rate_bps` (Task 3).
- Produces: POS orders with real `subtotal_cents`, `tax_cents`, `total_cents`, `tax_rate_bps`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Settings\Models\BranchSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateFromPosTaxTest extends TestCase
{
    use RefreshDatabase;

    // Use the project's existing helper to build a tenant + branch + a product
    // variant priced at a known amount, and to bind the current tenant. Read a
    // sibling POS/Orders feature test (e.g. tests/Feature/... that calls
    // createFromPos) and copy its factory setup verbatim.

    public function test_pos_order_applies_exclusive_tax_from_branch_settings(): void
    {
        [$branch, $variant] = $this->posFixture(unitPriceCents: 10_000); // helper: see note
        BranchSetting::writeDefault('tax', ['enabled' => true, 'rate_bps' => 1300, 'prices_include_tax' => false]);

        $order = app(\App\Modules\Orders\Services\OrderService::class)->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
            user: null,
        );

        $this->assertSame(10_000, $order->subtotal_cents);
        $this->assertSame(1_300, $order->tax_cents);
        $this->assertSame(11_300, $order->total_cents);
        $this->assertSame(1300, $order->tax_rate_bps);
    }

    public function test_pos_order_breaks_out_inclusive_tax_without_changing_total(): void
    {
        [$branch, $variant] = $this->posFixture(unitPriceCents: 11_300);
        BranchSetting::writeDefault('tax', ['enabled' => true, 'rate_bps' => 1300, 'prices_include_tax' => true]);

        $order = app(\App\Modules\Orders\Services\OrderService::class)->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
            user: null,
        );

        $this->assertSame(10_000, $order->subtotal_cents);
        $this->assertSame(1_300, $order->tax_cents);
        $this->assertSame(11_300, $order->total_cents);
    }

    public function test_pos_order_has_zero_tax_when_disabled(): void
    {
        [$branch, $variant] = $this->posFixture(unitPriceCents: 10_000);
        BranchSetting::writeDefault('tax', ['enabled' => false, 'rate_bps' => 1300, 'prices_include_tax' => false]);

        $order = app(\App\Modules\Orders\Services\OrderService::class)->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
            user: null,
        );

        $this->assertSame(0, $order->tax_cents);
        $this->assertSame(10_000, $order->total_cents);
        $this->assertSame(0, $order->tax_rate_bps);
    }
}
```

> Note for implementer: this plan does not know the exact factory helpers. Before writing the test, open an existing `createFromPos` feature test (search `grep -rl createFromPos tests/`) and reuse its tenant/branch/variant setup and the exact `createFromPos` argument names/shape. The `items` element shape above (`['product_variant_id' => ..., 'quantity' => ...]`) MUST match what `createFromPos` actually expects — verify against `OrderService::resolveAndValidateItems`.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Orders/CreateFromPosTaxTest.php`
Expected: FAIL — tax_cents is 0 / tax_rate_bps null (current hardcoded behaviour).

- [ ] **Step 3: Implement** — replace the tax block in `createFromPos`:

```php
            [$subtotalCents, $itemRows] = $this->buildItemRows($resolvedItems);

            // Tax: resolve the branch's tax settings and compute the breakdown.
            // Server is authoritative; the POS preview only mirrors this.
            $discountCents = 0; // POS has no discount UI yet
            $tax = BranchSetting::resolvedGroup('tax', $branch->id);
            $breakdown = (new \App\Modules\Orders\Support\TaxCalculator())
                ->compute($subtotalCents, $discountCents, $tax);
```

Then set the order columns from `$breakdown`:

```php
                'subtotal_cents' => $breakdown->subtotalCents,
                'tax_cents' => $breakdown->taxCents,
                'tax_rate_bps' => $breakdown->rateBpsApplied,
                'discount_cents' => $breakdown->discountCents,
                'total_cents' => $breakdown->totalCents,
```

Add `use App\Modules\Settings\Models\BranchSetting;` and `use App\Modules\Orders\Support\TaxCalculator;` at the top; call `TaxCalculator` via the import rather than the FQN if the import is added. Ensure `tax_rate_bps` is in the Order model's `$fillable` (add it if missing).

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test tests/Feature/Orders/CreateFromPosTaxTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the existing POS/orders suite for regressions**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter="Pos|Order"`
Expected: green (existing POS checkout tests that asserted `tax_cents === 0` must be updated to the new expected values as part of THIS task if they now fail — the zero was a placeholder, not a spec).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Orders/Services/OrderService.php app/Modules/Orders/Models/Order.php tests/Feature/Orders/CreateFromPosTaxTest.php
git commit -m "feat(pos): compute IVA on POS orders from tenant tax settings"
```

---

### Task 5: Expose tax config to the POS terminal

**Files:**
- Modify: `app/Modules/POS/Http/Controllers/Api/V1/PosController.php` (`products`, ~line 96)
- Test: `tests/Feature/POS/...` (add a test asserting the products response carries tax config; reuse the module's existing POS controller test file — find it with `grep -rl PosController tests/`)

**Interfaces:**
- Consumes: `BranchSetting::resolvedGroup('tax', $branch->id)`.
- Produces: the `products` JSON response includes `meta.tax` (or top-level `tax` via `additional`) = `{ enabled: bool, rate_bps: int, prices_include_tax: bool }`.

- [ ] **Step 1: Write the failing test** — assert the products endpoint returns the tax config:

```php
public function test_products_response_includes_branch_tax_config(): void
{
    // reuse the file's existing setup that authenticates a tenant user and a branch
    BranchSetting::writeDefault('tax', ['enabled' => true, 'rate_bps' => 1300, 'prices_include_tax' => false]);

    $res = $this->getJson("/api/v1/pos/products?branch_id={$branch->id}")->assertOk();

    $res->assertJsonPath('tax.enabled', true)
        ->assertJsonPath('tax.rate_bps', 1300)
        ->assertJsonPath('tax.prices_include_tax', false);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=products_response_includes_branch_tax_config`
Expected: FAIL — `tax` path missing.

- [ ] **Step 3: Implement** — in `PosController::products`, extend the `additional()` payload:

```php
        $tax = BranchSetting::resolvedGroup('tax', $branch->id);

        return (new PosProductCollection($paginated))
            ->additional([
                'stock' => $stockMap,
                'tax' => [
                    'enabled' => (bool) ($tax['enabled'] ?? false),
                    'rate_bps' => (int) ($tax['rate_bps'] ?? 0),
                    'prices_include_tax' => (bool) ($tax['prices_include_tax'] ?? false),
                ],
            ]);
```

Add `use App\Modules\Settings\Models\BranchSetting;` at the top.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail exec -T laravel.test php artisan test --filter=products_response_includes_branch_tax_config`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/POS/Http/Controllers/Api/V1/PosController.php tests/Feature/POS
git commit -m "feat(pos): expose branch tax config in the products response"
```

---

### Task 6: POS live preview + settings toggle (frontend + E2E)

**Files:**
- Create: `resources/js/components/Admin/Pos/computeTax.ts`
- Modify: the POS store/composable that holds cart totals (find with `grep -rln "subtotalCents\|taxCents" resources/js/components/Admin/Pos resources/js/stores resources/js/composables`)
- Modify: `resources/js/components/Admin/Pos/PosCartPanel.vue` (the IVA row, ~line 108-120)
- Modify: the Settings tax tab component (find with `grep -rln "rate_bps\|IVA\|impuesto" resources/js/pages/Admin resources/js/components/Admin/Settings`) — add the "Los precios incluyen IVA" toggle bound to `prices_include_tax`
- Test: `tests/e2e/admin/pos/pos-tax.spec.ts`

**Interfaces:**
- Consumes: the `tax` object from the POS products response (Task 5).
- Produces: `computeTax(itemsCents, discountCents, tax): { subtotalCents, taxCents, totalCents }`.

- [ ] **Step 1: Write `computeTax.ts` (TS mirror of TaxCalculator)**

```ts
export interface TaxConfig {
    enabled: boolean
    rate_bps: number
    prices_include_tax: boolean
}

export interface TaxTotals {
    subtotalCents: number
    taxCents: number
    totalCents: number
}

/**
 * Display-only mirror of the server TaxCalculator. The server recomputes at
 * checkout, so this never persists a total. Integer cents; rounding derives the
 * tax from the base so the parts always reconcile.
 */
export function computeTax(itemsCents: number, discountCents: number, tax: TaxConfig): TaxTotals {
    const discount = Math.min(Math.max(0, discountCents), Math.max(0, itemsCents))

    if (!tax.enabled || tax.rate_bps <= 0) {
        return { subtotalCents: itemsCents, taxCents: 0, totalCents: Math.max(0, itemsCents - discount) }
    }

    const base = itemsCents - discount

    if (tax.prices_include_tax) {
        const divisor = 10_000 + tax.rate_bps
        const net = Math.floor((base * 10_000 + Math.floor(divisor / 2)) / divisor)
        return { subtotalCents: net, taxCents: base - net, totalCents: base }
    }

    const taxCents = Math.floor((base * tax.rate_bps) / 10_000)
    return { subtotalCents: itemsCents, taxCents, totalCents: base + taxCents }
}
```

- [ ] **Step 2: Wire the POS store + PosCartPanel**

Read the POS store/composable found above. Store the `tax` object when the products response arrives. Replace the cart's `taxCents`/`totalCents` derivation to call `computeTax(subtotalCents, 0, tax)`. In `PosCartPanel.vue`, remove the "$0.00 intentionally" comment and bind the IVA row to the computed `taxCents`; label it `IVA` and append the rate — `IVA ({{ (tax.rate_bps / 100) }}%)` when exclusive, `IVA incluido ({{ (tax.rate_bps / 100) }}%)` when inclusive; hide the row with `v-if="tax.enabled"`.

- [ ] **Step 3: Add the settings toggle**

In the Settings tax tab component, add a labelled toggle "Los precios incluyen IVA" bound to the `prices_include_tax` field of the tax form, submitted through the existing settings save path. Give it `data-testid="tax-prices-include"` for the e2e.

- [ ] **Step 4: Write the E2E**

`tests/e2e/admin/pos/pos-tax.spec.ts` — mirror the setup of `tests/e2e/admin/pos/pos.spec.ts` (import `BASE_URL` from `../../support/env`, create user/tenant/branch, seed a product). Two tests:

```ts
// 1) Exclusive: set 13% via the settings API, open POS, add one item priced 100.00,
//    assert the IVA row shows 13.00 and Total shows 113.00, checkout, assert the
//    receipt shows an IVA line of 13.00.
// 2) Inclusive: set prices_include_tax=true + 13%, add the same 113.00-priced item,
//    assert Total stays 113.00 and the IVA row shows 13.00 (broken out).
```

Set the tax config in each test's setup via `PUT /api/v1/settings/tax` (authenticated), not the UI, to keep the POS assertions focused. Build the cart, read the IVA/Total nodes, and complete the sale exactly as `pos.spec.ts` does.

- [ ] **Step 5: Build and run the E2E**

Run:
```bash
./vendor/bin/sail npm run build
./vendor/bin/sail exec -T -e PLAYWRIGHT_BASE_URL=http://localhost laravel.test npx playwright test tests/e2e/admin/pos/pos-tax.spec.ts
```
Expected: both tests pass on chromium-desktop and chromium-mobile.

- [ ] **Step 6: Commit**

```bash
git add resources/js/components/Admin/Pos/computeTax.ts resources/js/components/Admin/Pos/PosCartPanel.vue tests/e2e/admin/pos/pos-tax.spec.ts
git add -A resources/js   # store + settings toggle
git commit -m "feat(pos): live IVA preview in the cart and prices-include-tax toggle"
```

---

## Notes for the executor

- Run PHPUnit inside the container against the `eternova_testing` DB (phpunit.xml handles this); do not point tests at the dev DB.
- E2E runs against the compiled bundle — always `npm run build` after touching Vue before running Playwright.
- If an existing POS checkout test asserted `tax_cents === 0`, that assertion encoded the placeholder, not a requirement: update it to the correct computed value in Task 4, not by disabling tax.
