# Wompi Recurring Backend Alignment (M1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the subscription backend to Wompi SV's gateway-managed recurrence — tenant subscribes → we create an `EnlacePagoRecurrente` → webhook activates → cancel deactivates the link — and retire the now-superseded self-charge crons.

**Architecture:** Wompi owns the recurrence (Approach A). We store the `idEnlace` as `gateway_subscription_id` + the affiliation URL, keep the subscription `trialing` until the first approved-charge webhook flips it to `active`, and call Wompi's deactivate endpoint on cancel. The recurring-charge + dunning-retry crons are removed (Wompi does both).

**Tech Stack:** Laravel 12 / PHP 8.4, Pest 4 (use `#[DataProvider]`, never `@dataProvider`), MySQL via Sail, FakeGateway as the default `BILLING_DRIVER` so all tests run without Wompi keys.

## Global Constraints

- `tenant_id` is ULID `string(26)` + FK to `tenants` — NEVER `foreignId`.
- `Subscription`/`Invoice` are NOT `BelongsToTenant`; filter by `tenant_id` explicitly.
- `status` stays a string column (no Eloquent enum cast); transition only via
  `$sub->state()->applyTransition(...)`.
- No emojis anywhere in code. Commits in English, conventional format, NO AI co-author trailer.
- Money in integer `_cents`. Wompi `monto` is dollars = `amount_cents / 100`.
- The app's exception handler renders a bare `abort(403)`/`abort(422)` as 500 — throw
  `AccessDeniedHttpException` (403) / `ValidationException` (422); `abort(404)` is fine.
- Run tests with `./vendor/bin/sail artisan test --filter='Billing'`. First test does
  `migrate:fresh` (~200s). Use `#[DataProvider]` for data providers.
- Validated Wompi SV endpoints (see `docs/billing/wompi-sv-integration.md`):
  create `POST /EnlacePagoRecurrente`, deactivate `POST /EnlacePagoRecurrente/{idEnlace}`,
  consult `GET /EnlacePagoRecurrente/{idEnlace}`; webhook header `wompi_hash`.

---

### Task 1: Data-model — affiliation URL columns

**Files:**
- Create: `database/migrations/2026_06_20_000001_add_affiliation_url_to_subscriptions.php`
- Modify: `app/Modules/Billing/Models/Subscription.php` (fillable + casts)
- Test: `tests/Feature/Billing/SubscriptionTest.php` (a column-exists assertion is optional; covered indirectly by Task 3)

**Interfaces:**
- Produces: `subscriptions.affiliation_url` (string nullable), `subscriptions.affiliation_qr_url` (string nullable), both fillable on `Subscription`.

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
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Wompi EnlacePagoRecurrente: gateway_subscription_id already holds the idEnlace;
            // these hold the hosted affiliation URL (urlEnlace) + QR for the tenant UI.
            $table->string('affiliation_url')->nullable()->after('gateway_subscription_id');
            $table->string('affiliation_qr_url')->nullable()->after('affiliation_url');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['affiliation_url', 'affiliation_qr_url']);
        });
    }
};
```

- [ ] **Step 2: Add the two columns to `Subscription::$fillable`** (after `'gateway_subscription_id'`): `'affiliation_url'`, `'affiliation_qr_url'`. No cast needed (plain strings).

- [ ] **Step 3: Run the billing suite to confirm the migration is valid**

Run: `./vendor/bin/sail artisan test --filter='SubscriptionTest'`
Expected: PASS (no regression; columns now exist).

- [ ] **Step 4: Commit**

```bash
git add database/migrations app/Modules/Billing/Models/Subscription.php
git commit -m "feat(billing): add affiliation_url columns for Wompi recurring links"
```

---

### Task 2: Gateway — cancel + consult recurring link

**Files:**
- Modify: `app/Modules/Billing/Gateways/Contracts/PaymentGatewayInterface.php`
- Modify: `app/Modules/Billing/Gateways/FakeGateway.php`
- Modify: `app/Modules/Billing/Gateways/WompiGateway.php`
- Test: `tests/Feature/Billing/WompiGatewayTest.php`, `tests/Feature/Billing/GatewayChargeTest.php`

**Interfaces:**
- Produces:
  - `PaymentGatewayInterface::cancelRecurringPaymentLink(string $linkId): bool`
  - `PaymentGatewayInterface::getRecurringLink(string $linkId): ?array`
  - `FakeGateway`: public `array $cancelledLinks = []`, public `bool $forceCancelFailure = false`.

- [ ] **Step 1: Add the two methods to the interface** (after `createRecurringPaymentLink`):

```php
/** Deactivate a recurring link so the gateway stops charging. Returns false on failure. */
public function cancelRecurringPaymentLink(string $linkId): bool;

/** Fetch a recurring link's current state (for reconciliation), or null. @return array<string,mixed>|null */
public function getRecurringLink(string $linkId): ?array;
```

- [ ] **Step 2: Write the FakeGateway test** in `GatewayChargeTest.php`:

```php
public function test_cancel_recurring_link_records_and_can_be_forced_to_fail(): void
{
    $this->assertTrue($this->gateway->cancelRecurringPaymentLink('fake_link_1'));
    $this->assertSame(['fake_link_1'], $this->gateway->cancelledLinks);

    $this->gateway->forceCancelFailure = true;
    $this->assertFalse($this->gateway->cancelRecurringPaymentLink('fake_link_2'));
}
```

- [ ] **Step 3: Implement in FakeGateway** (add the properties + methods):

```php
public bool $forceCancelFailure = false;
/** @var list<string> */
public array $cancelledLinks = [];

public function cancelRecurringPaymentLink(string $linkId): bool
{
    if ($this->forceCancelFailure) {
        return false;
    }
    $this->cancelledLinks[] = $linkId;
    return true;
}

public function getRecurringLink(string $linkId): ?array
{
    return ['idEnlace' => $linkId, 'estaProductivo' => false];
}
```

- [ ] **Step 4: Write the WompiGateway test** in `WompiGatewayTest.php` (uses the `withAuth` helper):

```php
public function test_cancel_recurring_link_posts_to_the_deactivate_endpoint(): void
{
    Http::fake($this->withAuth(['*/EnlacePagoRecurrente/*' => Http::response([], 200)]));

    $this->assertTrue($this->gateway()->cancelRecurringPaymentLink('enlace-1'));

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/EnlacePagoRecurrente/enlace-1') && $r->method() === 'POST');
}
```

- [ ] **Step 5: Implement in WompiGateway** (after `createRecurringPaymentLink`):

```php
public function cancelRecurringPaymentLink(string $linkId): bool
{
    try {
        return $this->authorized()->post("{$this->baseUrl}/EnlacePagoRecurrente/{$linkId}")->successful();
    } catch (\Throwable $e) {
        $this->logException('recurring_cancel_exception', $e, \Illuminate\Support\Str::uuid()->toString());
        return false;
    }
}

public function getRecurringLink(string $linkId): ?array
{
    try {
        $response = $this->authorized()->get("{$this->baseUrl}/EnlacePagoRecurrente/{$linkId}");
        return $response->successful() ? (array) $response->json() : null;
    } catch (\Throwable $e) {
        $this->logException('recurring_get_exception', $e, \Illuminate\Support\Str::uuid()->toString());
        return null;
    }
}
```

- [ ] **Step 6: Run the gateway tests**

Run: `./vendor/bin/sail artisan test tests/Feature/Billing/WompiGatewayTest.php tests/Feature/Billing/GatewayChargeTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Billing/Gateways tests/Feature/Billing/WompiGatewayTest.php tests/Feature/Billing/GatewayChargeTest.php
git commit -m "feat(billing): cancel + consult Wompi recurring links"
```

---

### Task 3: SubscribeService + endpoint

**Files:**
- Create: `app/Modules/Billing/Services/SubscribeService.php`
- Create: `app/Modules/Billing/Http/Controllers/Api/V1/SubscribeController.php`
- Create: `app/Modules/Billing/Http/Requests/SubscribeRequest.php`
- Modify: `routes/api/v1/billing.php` (add the route to the existing owner-only group)
- Test: `tests/Feature/Billing/BillingSubscribeTest.php`

**Interfaces:**
- Consumes: `PaymentGatewayInterface::createRecurringPaymentLink(RecurringPlanData)`, `TenantBillingService::current(string $tenantId)`.
- Produces: `SubscribeService::subscribe(Tenant $tenant, Plan $plan): Subscription` (stores `gateway_subscription_id`, `affiliation_url`, `affiliation_qr_url`, `plan_id`, `amount_cents`, `currency`; throws `RuntimeException` if the gateway link fails). Route `POST /api/v1/account/billing/subscribe` (name `api.v1.account.billing.subscribe`).

- [ ] **Step 1: Write `SubscribeRequest`** (owner-only, validates plan):

```php
<?php
declare(strict_types=1);
namespace App\Modules\Billing\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
final class SubscribeRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('billing.manage') ?? false; }
    public function rules(): array
    {
        return ['plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where('is_active', true)]];
    }
}
```

- [ ] **Step 2: Write `SubscribeService`**:

```php
<?php
declare(strict_types=1);
namespace App\Modules\Billing\Services;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use RuntimeException;

final class SubscribeService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly TenantBillingService $billing,
    ) {}

    public function subscribe(Tenant $tenant, Plan $plan): Subscription
    {
        $amountCents = (int) $plan->price_monthly_cents;

        $link = $this->gateway->createRecurringPaymentLink(new RecurringPlanData(
            amountCents: $amountCents,
            dayOfMonth: min((int) now()->day, 28),
            name: "{$plan->name} - {$tenant->name}",
            description: "Suscripcion {$plan->name} de Eternova",
        ));

        if (! $link->isSuccess()) {
            throw new RuntimeException('No se pudo crear el enlace de pago recurrente.');
        }

        $subscription = $this->billing->current($tenant->id)
            ?? new Subscription(['tenant_id' => $tenant->id, 'status' => 'trialing',
                'current_period_start' => now(), 'current_period_end' => now()->addDays(30)]);

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'amount_cents' => $amountCents,
            'currency' => (string) ($plan->currency ?? 'USD'),
            'gateway_subscription_id' => $link->linkId,
            'affiliation_url' => $link->shortUrl,
            'affiliation_qr_url' => $link->qrUrl,
        ])->save();

        return $subscription;
    }
}
```

- [ ] **Step 3: Write `SubscribeController`**:

```php
<?php
declare(strict_types=1);
namespace App\Modules\Billing\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\SubscribeRequest;
use App\Modules\Billing\Services\SubscribeService;
use App\Modules\Plans\Models\Plan;
use Illuminate\Http\JsonResponse;

final class SubscribeController extends Controller
{
    public function __construct(private readonly SubscribeService $subscribe) {}

    public function __invoke(SubscribeRequest $request): JsonResponse
    {
        $tenant = current_tenant();
        abort_if($tenant === null, 404);
        $plan = Plan::findOrFail($request->integer('plan_id'));

        $subscription = $this->subscribe->subscribe($tenant, $plan);

        return response()->json(['data' => [
            'affiliation_url' => $subscription->affiliation_url,
            'affiliation_qr_url' => $subscription->affiliation_qr_url,
            'status' => $subscription->status,
        ]]);
    }
}
```

- [ ] **Step 4: Add the route** to the existing owner-only group in `routes/api/v1/billing.php` (inside the `prefix('/account/billing')` group):

```php
Route::post('/subscribe', \App\Modules\Billing\Http\Controllers\Api\V1\SubscribeController::class)->name('subscribe');
```

- [ ] **Step 5: Write `BillingSubscribeTest`** (uses `ActingAsTenantMember`):

```php
public function test_owner_subscribe_creates_link_and_stores_affiliation_url(): void
{
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['price_monthly_cents' => 2900]);
    Subscription::factory()->forTenant($tenant)->withPlan($plan)->create(['status' => 'trialing']);
    $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

    $this->actingAs($owner)
        ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/subscribe'), ['plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonStructure(['data' => ['affiliation_url', 'status']]);

    $sub = Subscription::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
    $this->assertNotNull($sub->gateway_subscription_id);
    $this->assertNotNull($sub->affiliation_url);
}

public function test_non_owner_cannot_subscribe(): void
{
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

    $this->actingAs($staff)
        ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/subscribe'), ['plan_id' => $plan->id])
        ->assertStatus(403);
}
```

- [ ] **Step 6: Run + commit**

Run: `./vendor/bin/sail artisan test tests/Feature/Billing/BillingSubscribeTest.php`
Expected: PASS.

```bash
git add app/Modules/Billing routes/api/v1/billing.php tests/Feature/Billing/BillingSubscribeTest.php
git commit -m "feat(billing): tenant subscribe endpoint creates Wompi recurring link"
```

---

### Task 4: Webhook alignment — recurring activation by idEnlace

**Files:**
- Modify: `app/Modules/Billing/Handlers/TransactionUpdatedHandler.php`
- Test: `tests/Feature/Billing/WebhookProcessingTest.php`

**Interfaces:**
- Consumes: webhook payload keyed to `gateway_subscription_id` (the idEnlace). Until the real
  recurring webhook shape is captured (M3), keep the existing `data.transaction.reference` +
  `status` mapping AND also match `gateway_subscription_id` against a top-level `idEnlace` field.
- Produces: approved → `trialing|expired|past_due → active` (+ `next_billing_at`, `last_paid_at`); declined → `active → past_due` (+ `past_due_since`), NO next_retry scheduling.

- [ ] **Step 1: Update the subscription lookup** in `TransactionUpdatedHandler::handle()` to also accept a top-level `idEnlace`:

```php
$tx = $data['transaction'] ?? $data;
$reference = (string) ($tx['reference'] ?? ($data['idEnlace'] ?? ''));
$subscription = Subscription::query()->where('gateway_subscription_id', $reference)->first();
if ($subscription === null) { return; }
```

- [ ] **Step 2: Update `markRenewed`** to allow activation from trialing/expired/past_due (the state machine already permits these → Active) and drop nothing else. It already calls `applyTransition(Active)` when not active. Confirm `next_billing_at`/`last_paid_at` are set (existing code).

- [ ] **Step 3: Update `markChargeFailed`** to NOT schedule a retry (Wompi retries). Replace the body with:

```php
private function markChargeFailed(Subscription $subscription): void
{
    $state = $subscription->state();
    if (! $state->canTransitionTo(SubscriptionStatus::PastDue)) { return; }
    $state->applyTransition(SubscriptionStatus::PastDue);
    $subscription->forceFill(['past_due_since' => $subscription->past_due_since ?? now()])->save();
}
```

- [ ] **Step 4: Add a test** in `WebhookProcessingTest.php` — approved recurring charge activates a trialing subscription matched by `idEnlace`:

```php
public function test_approved_recurring_charge_activates_trialing_by_id_enlace(): void
{
    $sub = $this->subscription('trialing', 'enlace-9');
    $this->process(['event' => 'transaction.updated', 'idEnlace' => 'enlace-9',
        'data' => ['transaction' => ['id' => 'tx-r1', 'reference' => 'enlace-9', 'status' => 'APPROVED']]]);
    $this->assertSame('active', $sub->fresh()->status);
}
```

- [ ] **Step 5: Run + commit**

Run: `./vendor/bin/sail artisan test tests/Feature/Billing/WebhookProcessingTest.php`
Expected: PASS.

```bash
git add app/Modules/Billing/Handlers/TransactionUpdatedHandler.php tests/Feature/Billing/WebhookProcessingTest.php
git commit -m "feat(billing): activate subscription on recurring-charge webhook, drop self-retry"
```

---

### Task 5: Cancel deactivates the Wompi link

**Files:**
- Modify: `app/Modules/Billing/Http/Controllers/Api/V1/CancelSubscriptionController.php`
- Test: `tests/Feature/Billing/BillingAccountApiTest.php`

**Interfaces:**
- Consumes: `PaymentGatewayInterface::cancelRecurringPaymentLink(string $linkId)`.
- Produces: cancel sets `cancel_at_period_end` (existing) AND deactivates the Wompi link when `gateway_subscription_id` is present (best-effort; failure logged, local cancel still succeeds).

- [ ] **Step 1: Inject the gateway** into `CancelSubscriptionController` and, after the existing `cancel(... atPeriodEnd: true)`, deactivate the link:

```php
if ($subscription->gateway_subscription_id !== null) {
    // Best-effort: never block the local cancel on a gateway failure.
    $this->gateway->cancelRecurringPaymentLink((string) $subscription->gateway_subscription_id);
}
```

(Add `PaymentGatewayInterface $gateway` to the constructor.)

- [ ] **Step 2: Add a test** in `BillingAccountApiTest.php` — cancel deactivates the link via FakeGateway:

```php
public function test_cancel_deactivates_the_wompi_recurring_link(): void
{
    $tenant = $this->tenantWithSubscription();
    $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $sub->forceFill(['gateway_subscription_id' => 'enlace-x'])->save();
    $fake = app(\App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface::class);

    $this->actingAs($this->owner($tenant))
        ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/cancel'))->assertOk();

    $this->assertContains('enlace-x', $fake->cancelledLinks);
}
```

- [ ] **Step 3: Run + commit**

Run: `./vendor/bin/sail artisan test tests/Feature/Billing/BillingAccountApiTest.php`
Expected: PASS.

```bash
git add app/Modules/Billing/Http/Controllers/Api/V1/CancelSubscriptionController.php tests/Feature/Billing/BillingAccountApiTest.php
git commit -m "feat(billing): cancel deactivates the Wompi recurring link"
```

---

### Task 6: Retire the self-charge crons; rebase suspend-overdue

**Files:**
- Delete: `app/Modules/Billing/Console/Commands/ProcessRecurringChargesCommand.php`,
  `app/Modules/Billing/Console/Commands/RetryDunningCommand.php`,
  `app/Modules/Billing/Services/SubscriptionChargeService.php`
- Modify: `app/Modules/Billing/Providers/BillingServiceProvider.php` (drop the two commands from `$this->commands([...])`),
  `routes/console.php` (drop the two `Schedule::command(...)` lines),
  `app/Modules/Billing/Console/Commands/SuspendOverdueCommand.php` (rebase on `past_due_since`),
  `app/Modules/Billing/Services/DunningService.php` (remove `scheduleNextRetry`; keep `applyRenewal`/`suspend`/`maxRetries` only if still used — `maxRetries` no longer needed → remove),
  `tests/Feature/Billing/BillingCronsTest.php` (remove the recurring/retry tests; keep suspend/soft-delete/hard-delete/reconcile/trial-reminders)

**Interfaces:**
- Produces: `billing:process-recurring-charges` and `billing:retry-dunning` no longer exist. `SuspendOverdueCommand` suspends `past_due` subscriptions whose `past_due_since` is older than `config('billing.dunning_retry_days')` max window.

- [ ] **Step 1: Rebase `SuspendOverdueCommand::handle()`** to use `past_due_since` only:

```php
$overdueCutoff = now()->subDays((int) (max((array) config('billing.dunning_retry_days', [3, 7, 14])) ?: 14));
$suspended = 0;
foreach ($repository->inState(SubscriptionStatus::PastDue) as $subscription) {
    if ($subscription->past_due_since !== null && $subscription->past_due_since->lt($overdueCutoff)) {
        $dunning->suspend($subscription);
        $suspended++;
    }
}
$this->info("Suspended {$suspended} subscription(s).");
return self::SUCCESS;
```

(Remove the `$maxRetries`/`retry_count` logic and the `DunningService::maxRetries()` dependency.)

- [ ] **Step 2: Remove `scheduleNextRetry` from `DunningService`** and its now-unused `maxRetries()`. Keep `applyRenewal()`, `applyChargeFailure()` (now just transitions + `past_due_since`, no retry scheduling), and `suspend()`. Update `applyChargeFailure` to drop the `scheduleNextRetry` call.

- [ ] **Step 3: Delete** `ProcessRecurringChargesCommand.php`, `RetryDunningCommand.php`, `SubscriptionChargeService.php`. Remove them from `BillingServiceProvider::$this->commands([...])` and remove their two lines from `routes/console.php`.

- [ ] **Step 4: Prune `BillingCronsTest.php`** — delete `test_recurring_charge_*`, `test_dunning_retry_*`, and the `test_maintenance_mode_skips_recurring_charges` test (or repoint it at `billing:suspend-overdue`). Update `test_suspend_overdue_*` to set `past_due_since` (already does) and drop `retry_count` expectations. Keep soft-delete/hard-delete/reconcile/trial-reminder tests.

- [ ] **Step 5: Run the full billing suite**

Run: `./vendor/bin/sail artisan test --filter='Billing'`
Expected: PASS (the retired commands are gone; suspend-overdue green on `past_due_since`).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor(billing): retire self-charge crons; suspend-overdue on past_due_since (Wompi owns recurrence)"
```

---

## After M1

Open the PR for M1 (`fix/feature branch -> develop`), squash-merge, sync. Then:
- **M2** — Tenant UI 6b (Vue subscribe flow + affiliation link/QR + cancel) with Playwright + qa-engineer (its own plan).
- **M3** — Live run: cloudflared tunnel + `BILLING_DRIVER=wompi` + affiliate the test card + capture the real recurring webhook payload + finalize `TransactionUpdatedHandler` field mapping + verify `active`/invoice + test cancel.
