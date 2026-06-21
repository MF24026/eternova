# M2 — Tenant Billing UI (Phase 6b) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the tenant Owner a UI to subscribe to a plan (Wompi recurring), open the hosted affiliation link/QR, see status + invoices, and cancel — wiring the M1 backend to a real screen so the flow is human-usable end to end.

**Architecture:** Admin SPA (vue-router v4 + axios to `/api/v1`, NOT Inertia). New `BillingPage.vue` consumes a new `BillingService` against the existing `/api/v1/account/billing/*` endpoints (overview, subscribe, cancel, invoices, invoice download). Owner-only: backend enforces the `billing.manage` gate (403 for non-owners); the nav entry + page are also gated on `User.role === 'owner'` in the SPA. Activation is webhook-driven (M1) — the page reflects `active` once the webhook lands; the user gets a "ya me afilié / refrescar estado" affordance.

**Tech Stack:** Vue 3 `<script setup lang="ts">`, vue-router, axios (`@/services/api`), Pinia (`useAuthStore`), Tailwind 4 + Ethereal Boutique tokens, Lucide icons, Playwright E2E, PHPUnit (Pest).

## Global Constraints

- NO emojis anywhere (code or UI). Lucide icons only. — verbatim project rule.
- Spanish UI copy, English code/identifiers.
- Money is integer centavos in the DB/API (`*_cents`); format for display via the existing money helper/locale (do NOT hardcode `$`). Cross-ref `laravel-saas-i18n-latam`.
- Dark mode must work (class-based); use `var(--surface-*)` / Tier tokens, No-Line rule (separate with background tiers, not 1px borders).
- Admin is SPA: guards via `onBeforeRouteLeave`, NOT `@inertiajs` router events (breaks Vite build). Cross-ref memory [[admin_spa_not_inertia]].
- Subscription/Invoice are NOT BelongsToTenant — backend already filters by tenant_id; the UI never sends tenant_id.
- Owner-only: the page's data calls 403 for non-owners (backend `billing.manage`); the SPA hides the nav entry + shows an "owner-only" empty state for non-owners.
- Dual-layer testing is non-negotiable: PHPUnit (resource shape) + Playwright E2E (the UI flow). qa-engineer visual pass before the PR.
- Commits English conventional (`feat|fix(scope): msg`), NO AI co-author. PR `--base develop`.

---

### Task 1: Expose affiliation URL/QR on the subscription overview

So the page can resume a pending affiliation (subscribed but card not yet affiliated) and render the QR. Today `SubscriptionResource` omits these, so the overview can't show the link after a reload.

**Files:**
- Modify: `app/Modules/Billing/Http/Resources/SubscriptionResource.php`
- Test: `tests/Feature/Billing/AccountBillingOverviewTest.php` (extend if exists, else create)

**Interfaces:**
- Produces: `subscription.affiliation_url: string|null`, `subscription.affiliation_qr_url: string|null` in the `GET /api/v1/account/billing` payload, consumed by `BillingService.overview()` (Task 2).

- [ ] **Step 1: Write the failing test** — assert the overview payload exposes `affiliation_url` for a subscription that has one.

```php
public function test_overview_exposes_affiliation_url_for_pending_affiliation(): void
{
    $tenant = Tenant::factory()->create();
    $owner = $this->ownerFor($tenant); // existing helper / ActingAsTenantMember pattern
    $plan = Plan::factory()->create();
    Subscription::factory()->forTenant($tenant)->withPlan($plan)->create([
        'status' => 'trialing',
        'gateway_subscription_id' => 'idEnlace-123',
        'affiliation_url' => 'https://s.wompi.sv/ABC',
        'affiliation_qr_url' => 'https://wompistorage/qr.jpg',
    ]);

    $this->actingAs($owner)->getJson('/api/v1/account/billing')
        ->assertOk()
        ->assertJsonPath('data.subscription.affiliation_url', 'https://s.wompi.sv/ABC')
        ->assertJsonPath('data.subscription.affiliation_qr_url', 'https://wompistorage/qr.jpg');
}
```

- [ ] **Step 2: Run it, confirm it fails** (`assertJsonPath ... affiliation_url` missing).

Run: `./vendor/bin/sail artisan test --filter=AccountBillingOverviewTest`
Expected: FAIL (path not present / null).

- [ ] **Step 3: Add the two fields to `SubscriptionResource::toArray`** (after `cancel_at_period_end`):

```php
// Pending-affiliation resume: the hosted Wompi link + QR the owner must visit to affiliate.
'affiliation_url' => $this->affiliation_url,
'affiliation_qr_url' => $this->affiliation_qr_url,
```

- [ ] **Step 4: Run the test, confirm it passes.**

Run: `./vendor/bin/sail artisan test --filter=AccountBillingOverviewTest`
Expected: PASS.

- [ ] **Step 5: Commit** — `feat(billing): expose affiliation url/qr on subscription overview`.

---

### Task 2: Frontend types + BillingService

**Files:**
- Create: `resources/js/types/domain/Billing.ts`
- Create: `resources/js/services/BillingService.ts`

**Interfaces:**
- Consumes: `GET /account/billing`, `POST /account/billing/subscribe {plan_id}`, `POST /account/billing/cancel`, `GET /account/billing/invoices`, `GET /account/billing/invoices/{id}/download`.
- Produces: `BillingService.overview() / subscribe(planId) / cancel() / invoices() / downloadInvoice(id)` and the `Subscription` / `Invoice` / `BillingOverview` / `SubscribeResult` types consumed by `BillingPage.vue` (Task 3).

- [ ] **Step 1: Create `Billing.ts`** (match the resource shapes from Task 1 + `InvoiceResource`):

```ts
export interface BillingPlanRef { id: number; name: string; slug: string }

export interface Subscription {
    id: number
    status: string
    status_label: string
    plan?: BillingPlanRef
    amount_cents: number
    currency: string
    billing_period: string | null
    trial_ends_at: string | null
    current_period_end: string | null
    next_billing_at: string | null
    cancel_at_period_end: boolean
    card_last4: string | null
    card_brand: string | null
    affiliation_url: string | null
    affiliation_qr_url: string | null
}

export interface Invoice {
    id: number
    number: string
    status: string
    subtotal_cents: number
    tax_cents: number
    total_cents: number
    currency: string
    issued_at: string | null
    paid_at: string | null
    has_pdf: boolean
}

export interface BillingOverview {
    subscription: Subscription | null
    recent_invoices: Invoice[]
}

export interface SubscribeResult {
    affiliation_url: string | null
    affiliation_qr_url: string | null
    status: string
}
```

- [ ] **Step 2: Create `BillingService.ts`** (mirror `PlansService` / `SettingsService` axios pattern; download returns a blob):

```ts
import api from './api'
import type { BillingOverview, Invoice, SubscribeResult } from '@/types/domain/Billing'

const BillingService = {
    async overview(): Promise<BillingOverview> {
        const res = await api.get<{ data: BillingOverview }>('/account/billing')
        return res.data.data
    },
    async subscribe(planId: number): Promise<SubscribeResult> {
        const res = await api.post<{ data: SubscribeResult }>('/account/billing/subscribe', { plan_id: planId })
        return res.data.data
    },
    async cancel(): Promise<void> {
        await api.post('/account/billing/cancel')
    },
    async invoices(): Promise<Invoice[]> {
        const res = await api.get<{ data: Invoice[] }>('/account/billing/invoices')
        return res.data.data
    },
    downloadInvoiceUrl(invoiceId: number): string {
        return `/api/v1/account/billing/invoices/${invoiceId}/download`
    },
}

export default BillingService
```

> Note: confirm `/account/billing/invoices` returns `{ data: [...] }` (InvoiceController@index). If it paginates (`{ data, meta }`), the type still holds since we read `.data.data`. The download is a browser navigation to the URL (auth cookie) — open in a new tab; if the endpoint requires the bearer token rather than cookie session, switch to a blob fetch (`api.get(url, { responseType: 'blob' })`) and trigger a download. Verify which during implementation.

- [ ] **Step 3: Typecheck** — `./vendor/bin/sail npm run lint` (or `vue-tsc`) passes for the new files.

- [ ] **Step 4: Commit** — `feat(billing): add BillingService + types for the tenant billing UI`.

---

### Task 3: BillingPage.vue

The screen. Sections: (a) current subscription status card; (b) if no active/pending sub, a plan selector with a "Suscribirme" CTA per plan; (c) when a pending affiliation exists, an affiliation panel (open link button + QR + "ya me afilié / refrescar estado"); (d) recent invoices list with download; (e) cancel button (Owner, useConfirm). Non-owner → owner-only empty state.

**Files:**
- Create: `resources/js/pages/Admin/BillingPage.vue`
- Reuse: `@/components/base/AppButton.vue`, `AppSpinner.vue`, `@/composables/useToast`, `@/composables/useConfirm`, `@/services/PlansService`, `@/services/BillingService`, `@/stores/auth`.

**Interfaces:**
- Consumes: `BillingService` + `PlansService.list()` + `useAuthStore().currentUser.role`.
- Produces: route target `admin.billing` (Task 4).

- [ ] **Step 1: Write the Playwright-facing structure with stable `data-testid`s** — the page must expose: `data-testid="billing-page"`, `billing-status-card`, `plan-card-{slug}`, `subscribe-btn-{slug}`, `affiliation-panel`, `affiliation-open-link`, `affiliation-refresh`, `invoice-row`, `cancel-subscription-btn`. (Playwright in Task 5 keys off these.)

- [ ] **Step 2: Implement the component.** Key logic:
  - `onMounted`: `document.title = 'Facturación — Eternova'`; load overview + plans in parallel; handle 403 → set `ownerOnly = true`.
  - `isOwner = computed(() => auth.currentUser?.role === 'owner')`.
  - Derived flags: `hasActive = sub?.status === 'active'`; `pendingAffiliation = !!sub?.affiliation_url && sub?.status !== 'active'`.
  - Status card: plan name, `status_label` (badge colored by status), next charge date (format via locale helper, not hardcoded), card last4/brand when present.
  - Plan selector (shown when no active sub): map `PlansService.list()`; each plan card shows name + price (format cents) + a `subscribe-btn`. On click → `subscribing = slug`; `BillingService.subscribe(plan.id)` → on success set the affiliation panel from the result (and re-fetch overview); on failure → toast error, leave state unchanged.
  - Affiliation panel: "Abrí el enlace para afiliar tu tarjeta" + `affiliation-open-link` (anchor `target="_blank"` to `affiliation_url`) + the QR `<img :src="affiliation_qr_url">` (when present) + `affiliation-refresh` button that re-fetches overview (so when the webhook has activated, the card flips to `active`). Copy explains activation is automatic after the charge.
  - Invoices list: `recent_invoices`; each row: number, issued date, total (formatted), status badge, a download link (`BillingService.downloadInvoiceUrl(id)`, `target="_blank"`) shown only when `has_pdf`.
  - Cancel: visible when `hasActive`; `useConfirm` modal ("Tu suscripción se cancelará al final del período actual; mantenés acceso hasta entonces.") → `BillingService.cancel()` → toast + re-fetch.
  - Non-owner: render an owner-only notice (Lucide `Lock`), no data calls beyond the 403 it already caught.
  - Loading: `AppSpinner` while `loading`. Empty/dark-mode safe via Tier tokens.
  - NO emojis; all icons Lucide (`CreditCard`, `FileText`, `QrCode`, `RefreshCw`, `ExternalLink`, `Lock`, `Loader2`).

- [ ] **Step 3: Lint/typecheck** — `./vendor/bin/sail npm run lint` passes.

- [ ] **Step 4: Build** — `./vendor/bin/sail npm run build` succeeds (e2e runs against the built bundle; cross-ref memory [[e2e_runs_against_built_bundle]]).

- [ ] **Step 5: Commit** — `feat(billing): tenant billing page (subscribe, affiliation, invoices, cancel)`.

---

### Task 4: Route + owner-gated nav entry

**Files:**
- Modify: `resources/js/router/index.ts` (add the route)
- Modify: `resources/js/components/layout/AdminLayout.vue` (add nav item, owner-gated)

- [ ] **Step 1: Add the route** after the `admin.settings` route (line ~179):

```ts
{
    path: '/admin/billing',
    name: 'admin.billing',
    component: () => import('@/pages/Admin/BillingPage.vue'),
    meta: { requiresAuth: true, layout: 'admin' },
},
```

- [ ] **Step 2: Add the nav item** in `AdminLayout.vue` `navItems` (after `settings`), with an owner-only visibility filter. If `navItems` is currently a plain array, derive a `visibleNavItems = computed(() => navItems.filter(i => !i.ownerOnly || auth.currentUser?.role === 'owner'))` and render that; mark the billing item `ownerOnly: true`:

```ts
{ id: 'billing', label: 'Facturación', to: '/admin/billing', icon: CreditCard, ownerOnly: true },
```
(Import `CreditCard` from `lucide-vue-next` if not already; the `NavItem` type gains an optional `ownerOnly?: boolean`. Render `visibleNavItems` in the `v-for`.)

- [ ] **Step 3: Build** — `./vendor/bin/sail npm run build` succeeds.

- [ ] **Step 4: Commit** — `feat(billing): route + owner-gated nav entry for billing`.

---

### Task 5: Playwright E2E

**Files:**
- Create: `tests/e2e/billing.spec.ts`
- Cross-ref: `.claude/skills/saas-testing-dual-layer/SKILL.md` for the multi-tenant Playwright login/seed pattern; memory [[dev_multitenant_host_auth]] ({slug}.eternova.localhost:8080 host, demo creds).

**Interfaces:**
- Consumes: the seeded demo tenant + owner login; `BILLING_DRIVER=fake` for deterministic subscribe (FakeGateway returns a synthetic affiliation URL — confirm FakeGateway.createRecurringPaymentLink returns a non-null shortUrl; if not, add it).

- [ ] **Step 1: Pre-req — ensure FakeGateway returns a usable affiliation link** so the E2E is deterministic without hitting Wompi. Verify `FakeGateway::createRecurringPaymentLink` returns `RecurringPaymentLink::succeeded(...)` with a fake `shortUrl`/`qrUrl`; if it returns nulls, set fakes (e.g. `https://fake.wompi.test/affiliate/{id}`). Add/confirm a PHPUnit assertion for this in `tests/Feature/Billing/` if changed.

- [ ] **Step 2: Write the spec** — as the demo owner on `{slug}.eternova.localhost:8080`:
  1. Navigate to `/admin/billing`; expect `billing-page` visible.
  2. If not subscribed: expect a `plan-card-*`; click `subscribe-btn-*`; expect `affiliation-panel` + `affiliation-open-link` with a non-empty `href`.
  3. Click `affiliation-refresh`; page still renders (no crash), status reflects backend.
  4. Invoices section renders (empty or rows).
  5. (If a seeded active subscription is available) `cancel-subscription-btn` → confirm modal → success toast. Otherwise assert the cancel button is absent when no active sub.
  - Keep selectors on `data-testid`. Reset/seed DB state per the dual-layer skill (PHPUnit empties the e2e DB — reseed between; cross-ref [[e2e_runs_against_built_bundle]]).

- [ ] **Step 3: Build then run** — `./vendor/bin/sail npm run build && ./vendor/bin/sail npm run test:e2e -- billing.spec.ts`
Expected: PASS.

- [ ] **Step 4: Commit** — `test(billing): e2e for the tenant billing/subscribe flow`.

---

### Task 6: Visual QA (qa-engineer)

Not a code task — a gate before the PR. Per CLAUDE.md "QA visual obligatoria antes de cerrar PR".

- [ ] **Step 1: Invoke the `qa-engineer` agent** (Playwright MCP) to validate `/admin/billing`: screenshots desktop + mobile + dark mode; exercise subscribe → affiliation panel; check invoices + cancel modal; verify no regression in the adjacent `Ajustes` view and the admin nav. Confirm no emojis, Lucide icons, Ethereal tokens, No-Line rule, focus states.
- [ ] **Step 2: Address any Critical/Important findings** (fix + re-verify) before opening the PR.

---

## Done = PR

After Task 6 is clean: `gh pr create --base develop --fill` from the `feature/billing-tenant-ui` branch; title `feat(billing): tenant billing UI (subscribe, affiliation, invoices, cancel) [M2]`. Then squash-merge + sync develop per the standing cadence. develop→main stays pending the user's explicit OK.

## Self-review notes
- Spec coverage: subscribe CTA, affiliation link/QR + resume, invoices + download, cancel, owner-only, dark/mobile — all from spec #189 §"Tenant UI (Phase 6b)". ✓
- Open verification points flagged inline (invoice index envelope; download cookie-vs-bearer; FakeGateway fake URL) — resolve during implementation, don't guess. ✓
- No new subscription state; activation stays webhook-driven (M1). ✓
