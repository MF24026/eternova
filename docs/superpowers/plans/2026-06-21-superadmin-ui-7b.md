# 7b — SuperAdmin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Build the platform operator console (SuperAdmin) UI that consumes the existing 7a API:
a billing metrics dashboard, a tenants list, and a tenant detail page with the operator actions
(extend trial / suspend / reactivate, each with a reason).

**Architecture:** Admin SPA (vue-router + axios). New pages under `resources/js/pages/SuperAdmin/`
using the existing `SuperAdminLayout` (already wired in `LayoutSwitcher`). Routes `/super-admin/*`
with `meta.layout = 'super-admin'` and a new `requiresSuperAdmin` guard (auth store already exposes
`isSuperAdmin`, from the `/me` `is_super_admin` flag). Gated server-side by the `super_admin`
middleware (403s the rest).

**Tech Stack:** Vue 3 `<script setup lang="ts">`, vue-router, axios (`@/services/api`), Pinia
(`useAuthStore`), Tailwind 4 + Ethereal Boutique tokens, Lucide icons, Playwright E2E, PHPUnit.

## Global Constraints
- NO emojis (code or UI). Lucide icons only. Spanish UI copy, English code.
- Money is integer centavos; format via `useFormatCurrency` (never hardcode `$`).
- Dark mode (class-based), Tier tokens, No-Line rule.
- SuperAdmin is platform-level (NOT tenant-scoped): these endpoints are NOT under `/account`, they
  use `is_super_admin`. Never send tenant_id.
- Gating: pages require `is_super_admin`; the API 403s others. The SPA guard redirects non-super
  users away.
- Dual-layer: PHPUnit (the 7a API already has feature tests — only add a guard/resource test if a
  backend touch is needed) + Playwright E2E for the UI flow. qa-engineer visual pass before PR.
- Commits English conventional, NO AI co-author. PR `--base develop`.

## Scope
In: **Metrics dashboard**, **Tenants list**, **Tenant detail + 3 actions**. The `SuperAdminLayout`
nav currently lists Tenants / Suscripciones / Soporte / Metricas; **Suscripciones and Soporte have
no backend** — trim the nav to Tenants + Metricas in this milestone (re-add when those APIs exist).

## API surface (7a, existing)
- `GET /super-admin/billing/metrics` → `{ data: { mrr_cents, arpu_cents, total_tenants,
  counts_by_status: {status:count}, plan_distribution: {planName:count}, churn_rate: float } }`
- `GET /super-admin/tenants` → `{ data: [{ id, name, slug, status, subscription }] }`
- `GET /super-admin/tenants/{id}` → `{ data: { id, name, slug, status, email, subscription,
  audit_log: [{id, event_type, payload, occurred_at}] } }`
- `POST /super-admin/tenants/{id}/extend-trial` `{ days, reason }`
- `POST /super-admin/tenants/{id}/suspend` `{ reason }`
- `POST /super-admin/tenants/{id}/reactivate` `{ reason }`
  (`subscription` summary shape: confirm fields from `currentSubscriptionSummary` during Task 1.)

Super-admin demo user: `admin@eternova.app` / `ChangeMe123!` (SuperAdminUserSeeder).

---

### Task 1: Types + SuperAdminService
**Files:** Create `resources/js/types/domain/SuperAdmin.ts`, `resources/js/services/SuperAdminService.ts`.
**Interfaces:** Produces `SuperAdminService.metrics() / tenants() / tenant(id) / extendTrial(id,days,reason)
/ suspend(id,reason) / reactivate(id,reason)` + the `BillingMetrics`, `SuperAdminTenantRow`,
`SuperAdminTenantDetail` types.

- [ ] Step 1: Read `currentSubscriptionSummary()` in `SuperAdminTenantController` to capture the exact
  `subscription` summary shape; mirror it in the types.
- [ ] Step 2: Create `SuperAdmin.ts` with `BillingMetrics` (mrr_cents, arpu_cents, total_tenants,
  counts_by_status, plan_distribution, churn_rate), `SuperAdminTenantRow`, `SuperAdminTenantDetail`
  (with `audit_log: { id, event_type, payload, occurred_at }[]`).
- [ ] Step 3: Create `SuperAdminService.ts` (mirror `PlansService`/`BillingService` axios pattern)
  with the 6 methods above (GET metrics/tenants/tenant; POST the 3 actions).
- [ ] Step 4: Lint passes. Commit `feat(super-admin): types + service for the operator console`.

### Task 2: Router routes + super-admin guard + nav trim
**Files:** Modify `resources/js/router/index.ts`, `resources/js/router/guards.ts`,
`resources/js/components/layout/SuperAdminLayout.vue`.

- [ ] Step 1: In `guards.ts`, after the `requiresAuth` check, add: if
  `to.meta.requiresSuperAdmin === true && !auth.isSuperAdmin` → redirect to `{ name: 'admin.dashboard' }`
  (or login if unauthenticated).
- [ ] Step 2: Add routes (all `meta: { requiresAuth: true, requiresSuperAdmin: true, layout: 'super-admin' }`):
  - `/super-admin` (redirect to `/super-admin/metrics`), `name: 'super-admin'`
  - `/super-admin/metrics` → `SuperAdminMetricsPage`, `name: 'super-admin.metrics'`
  - `/super-admin/tenants` → `SuperAdminTenantsPage`, `name: 'super-admin.tenants'`
  - `/super-admin/tenants/:id` → `SuperAdminTenantDetailPage`, `name: 'super-admin.tenants.detail'`
- [ ] Step 3: Trim `SuperAdminLayout` `navItems` to Tenants + Metricas (remove Suscripciones/Soporte).
- [ ] Step 4: Build succeeds. Commit `feat(super-admin): routes + super-admin route guard + nav`.

### Task 3: SuperAdminMetricsPage
**Files:** Create `resources/js/pages/SuperAdmin/SuperAdminMetricsPage.vue`.
- [ ] Step 1: `data-testid="superadmin-metrics-page"`. KPI cards (reuse `KpiCard` if it fits): MRR
  (`formatCents`), ARPU (`formatCents`), total tenants, churn rate (%). A "Suscripciones por estado"
  block (counts_by_status) and "Distribución por plan" block (plan_distribution) as simple labeled
  rows/bars. Loading spinner; dark mode; no emojis.
- [ ] Step 2: Build + lint. Commit `feat(super-admin): billing metrics dashboard`.

### Task 4: SuperAdminTenantsPage
**Files:** Create `resources/js/pages/SuperAdmin/SuperAdminTenantsPage.vue`.
- [ ] Step 1: `data-testid="superadmin-tenants-page"`. A table/list of tenants: name, slug,
  `status` badge, current subscription plan + status. Each row `data-testid="tenant-row"` links to
  `/super-admin/tenants/{id}`. Empty + loading states.
- [ ] Step 2: Build + lint. Commit `feat(super-admin): tenants list`.

### Task 5: SuperAdminTenantDetailPage + actions
**Files:** Create `resources/js/pages/SuperAdmin/SuperAdminTenantDetailPage.vue`.
- [ ] Step 1: `data-testid="superadmin-tenant-detail"`. Show tenant name/slug/email/status +
  subscription summary + the audit log (event_type + occurred_at, most recent first).
- [ ] Step 2: Three action buttons (Owner... i.e. super-admin only, the API enforces it):
  `extend-trial-btn` (opens a small form: days + reason), `suspend-btn` (reason), `reactivate-btn`
  (reason). Use `useConfirm`/a slideover or inline form for the reason. On success → toast +
  re-fetch detail (status + audit update). `reason` is required (the API `OperatorReasonRequest`
  validates it; surface 422 errors).
- [ ] Step 3: Build + lint. Commit `feat(super-admin): tenant detail + operator actions`.

### Task 6: Playwright E2E
**Files:** Create `tests/e2e/super-admin/super-admin.spec.ts`.
- [ ] Step 1: Login as `admin@eternova.app` / `ChangeMe123!` (host: the base app host —
  super-admin is not tenant-scoped; confirm the login host during impl, likely `eternova.localhost`
  or a tenant host then navigate to `/super-admin`). Assert redirect/landing on `/super-admin/metrics`.
- [ ] Step 2: Metrics page shows KPI cards. Tenants page lists `tenant-row`(s). Open a tenant detail.
- [ ] Step 3: Perform one action (e.g. extend-trial with a reason) → success toast + audit row
  appears. Assert a non-super user (a tenant owner) is redirected away from `/super-admin/*`.
- [ ] Step 4: Build then run `--project=chromium-desktop` + `chromium-mobile`. Commit
  `test(super-admin): e2e for the operator console`.

### Task 7: Visual QA (qa-engineer / Playwright MCP)
- [ ] Validate `/super-admin/metrics`, `/tenants`, `/tenants/:id` — desktop + mobile + dark mode;
  exercise an action; no emojis, Lucide, tokens, focus states; no regression in the tenant AdminLayout.
- [ ] Fix Critical/Important findings before the PR.

## Done = PR
`feature/super-admin-ui` → `gh pr create --base develop`. Squash-merge + sync per the standing cadence.

## Open verification points (resolve during impl, don't guess)
- The `subscription` summary shape from `currentSubscriptionSummary` (Task 1).
- The login host for a super-admin in dev (super-admin has no tenant) + how the SPA boots the
  super-admin layout/route from that host (Task 2/6).
- Whether `KpiCard` fits the metrics cards or a bespoke card is cleaner (Task 3).
