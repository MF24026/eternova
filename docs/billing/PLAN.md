# Billing (Wompi) — 8-phase build plan

Status board for the billing/payments infrastructure. Built paranoid-by-default against a
`FakeGateway` first; real Wompi sandbox keys are only needed for the Phase 2 sandbox smoke
and the Phase 6 payment iframe.

> Reference: `.claude/skills/laravel-saas-billing-infrastructure`. This doc records the
> **project-specific divergences** from that skill and the per-phase status. Read both.

## Project-specific divergences from the skill

The skill's schema/code assume a generic SaaS. Eternova differs in ways that MUST be
honored in every billing migration and query:

1. **`tenant_id` is a ULID `string(26)`**, not `foreignId`. The `tenants` table has a ULID
   primary key. Every billing table uses
   `$table->string('tenant_id', 26); $table->foreign('tenant_id')->references('id')->on('tenants')`.
   The skill's `foreignId('tenant_id')->constrained()` would create a bigint FK and break.
2. **`Subscription` is a SaaS-platform model with NO `BelongsToTenant` global scope** —
   billing is cross-tenant by nature. The skill's `withoutGlobalScopes()` is therefore
   unnecessary here; `SubscriptionRepository` queries normally.
3. **`status` stays a plain string column**, cast to the `SubscriptionStatus` PHP enum only
   where convenient — NOT an Eloquent enum cast on the model. The existing `SubscriptionTest`
   asserts `assertSame('active', $sub->status)`, so a model-level enum cast would break it.
4. **State spellings `trialing` and `canceled`** are kept from the original 4-state ERD stub
   (not the skill's `trial`/`cancelled`) so existing rows, factory, service and observer
   need no data migration. The five new states use skill spellings.
5. **Test runner is Pest 4** — data-provider tests use the `#[DataProvider]` attribute, not
   the `@dataProvider` doc-block (Pest does not honor the annotation).
6. The legacy **`SubscriptionService` + `SubscriptionObserver`** stay: the Observer still
   guards the one-active-per-tenant invariant. New domain code transitions via
   `$sub->state()->applyTransition(...)`; the Service remains for orchestration callers.

## Phase status

| # | Phase | Branch | Status |
|---|-------|--------|--------|
| 1 | Foundation — 9-state machine, domain events, append-only audit log, repository | merged #177 | DONE |
| 2 | Gateway abstraction — interface, CircuitBreaker, FakeGateway, ErrorTranslator, IdempotencyService, WompiGateway, PCI smoke | `feature/billing-gateway` | DONE |
| 3 | Webhook receiver — HMAC verify, idempotency, queue dispatch, handlers | `feature/billing-webhooks` | DONE |
| 4 | Crons — recurring charges, dunning retry, suspend, soft/hard delete, reconcile, trial reminders | `feature/billing-crons` | DONE |
| 5 | Notifications + invoice PDF, listeners | `feature/billing-notifications` | DONE |
| 6a | Tenant billing API — `/account/billing/*` (Owner-only): overview, invoices, download, cancel, change-plan | `feature/billing-account-api` | DONE |
| 6b | Tenant billing **UI** (Vue/SPA) + Playwright E2E + qa-engineer; payment-method iframe | — | TODO (iframe needs Wompi public key) |
| 7a | SuperAdmin billing API — `super_admin` gate, MRR/churn metrics, tenant views, audited operator actions | `feature/superadmin-billing-api` | DONE |
| 7b | SuperAdmin **UI** (Vue at admin.eternova.app) + Playwright E2E + qa-engineer | — | TODO |
| 8 | Ops — RUNBOOK, maintenance CLI, billing log channel (1825d), SECURITY.md, security-suite consolidation | `feature/billing-ops` | DONE |

Each phase is its own branch off `develop` → build → dual-layer test → PR to `develop`.
Phases 1-5 and 7 are fully buildable + testable with `FakeGateway`; real Wompi keys are
only required for the Phase 2 sandbox smoke and the Phase 6 payment iframe.

## Phase 1 — delivered

- `SubscriptionStatus` enum (9 states) — `app/Modules/Billing/Enums`.
- State pattern — `app/Modules/Billing/Domain/States` (abstract `SubscriptionState` +
  9 concrete states). Capability flags (`canCharge`, `isAccessible`, `isReadOnly`, ...) +
  per-state `allowedTransitions()`. `applyTransition()` validates, persists, emits
  `SubscriptionStateChanged`.
- `SubscriptionStateChanged` domain event + `RecordSubscriptionStateChange` listener →
  append-only `billing_audit_log`.
- `BillingAuditLog` model (throws on update/delete) + migration.
- `SubscriptionRepository` — cross-tenant cron queries (`dueForRecurringCharge`, `inState`,
  `dueForDunningRetry`).
- `subscriptions` table evolved: `status` widened to string, lifecycle + gateway columns
  added (`next_billing_at`, `retry_count`, `card_token` encrypted + hidden, ...), softDeletes.
- `BillingServiceProvider` registered in `bootstrap/providers.php`.
- Tests (PHPUnit-only — pure domain, no UI): `SubscriptionStateMachineTest`,
  `BillingAuditLogTest`, `SubscriptionRepositoryTest`. Existing `SubscriptionTest` unchanged.

## Phase 2 — delivered

- `PaymentGatewayInterface` (`Gateways/Contracts`) — the seam. App code speaks DTOs +
  `GatewayError`; only implementors know the wire format.
- DTOs (`Gateways/Data`): `CardData`, `ChargeData`, `ChargeResult`, `RefundResult`,
  `TokenResult`, `TransactionResult`. `CardData`/`ChargeData`/`TokenResult` mask sensitive
  fields via `__debugInfo()`; card/token params carry `#[\SensitiveParameter]`.
- `FakeGateway` (default driver) with force flags + recorded charges/refunds — the whole
  stack runs without real keys.
- `WompiGateway` — real impl. PCI discipline enforced: never logs `$response->body()`,
  throws `GatewayException` with no `previous`, masks credentials, HMAC webhook verify.
- `WompiErrorTranslator` — provider codes → `GatewayError` (unknown ⇒ retryable).
- `CircuitBreaker` (`Support`) — cache-backed, separate key per call type.
- `IdempotencyService` + `IdempotentOperation` model + `idempotent_operations` table
  (DB-level UNIQUE on key+operation+tenant_id = exactly-once).
- `config/billing.php` — driver select (`BILLING_DRIVER`, default `fake`), Wompi keys,
  circuit + lifecycle windows. Binding wired in `BillingServiceProvider::register()`.
- Tests: `GatewayChargeTest`, `IdempotencyServiceTest`, `CircuitBreakerTest`,
  `WompiErrorTranslatorTest`, `BillingPciSmokeTest` (SensitiveParameter reflection +
  trace redaction + no-body-in-log).
- **Pending real keys (Erick):** set `BILLING_DRIVER=wompi` + `WOMPI_*` and run a sandbox
  smoke (one test card per error code) before Phase 6 ships the payment iframe.

## Phase 3 — delivered

- `POST /api/v1/billing/webhooks/wompi` — public (server-to-server), HMAC-gated.
  `WompiWebhookController` returns explicit responses (NOT `abort()`: this app's handler
  renders a bare HttpException as 500). Verifies signature, dedupes by event id, queues, 204.
- `webhook_log` table extended (`event_id`, `status`, `received_at` + UNIQUE(gateway,
  event_id)) — reused the existing unused table instead of a redundant `webhook_events`.
  `WebhookLog` model gained `$table='webhook_log'` (it pluralized wrongly; was never used).
- `ProcessWompiWebhookEvent` job (async, backoff [60,300,900,3600], retryUntil 24h) routes
  to handlers; unknown event type acknowledged + ignored.
- Handlers: `TransactionUpdatedHandler` (APPROVED→renew via state machine, DECLINED/ERROR→
  past_due + schedule retry, VOIDED→cancel — all idempotent, replays don't double-advance),
  `TransactionRefundedHandler` + `ChargebackHandler` (audit-only; chargeback never
  auto-suspends — operator decision in Phase 7).
- Tests: `WompiWebhookReceiverTest` (HMAC/idempotency/queue), `WebhookProcessingTest`
  (each event type's domain effect). PHPUnit only — webhook is machine-to-machine, no UI.
- **Wompi note:** real checksum algorithm + dedupe key finalized at sandbox time;
  FakeGateway uses HMAC which the tests exercise.

## Phase 4 — delivered

- 7 idempotent cron commands (`Console/Commands`), registered in `BillingServiceProvider`
  (module commands aren't auto-discovered) and scheduled in `routes/console.php` (02:00-08:00,
  `withoutOverlapping`). All skip when `BillingMaintenance::isEnabled()`.
  - `billing:process-recurring-charges` — charge active subs due; success→renew, decline→dunning.
  - `billing:retry-dunning` — retry past-due on the day-3/7/14 ladder.
  - `billing:suspend-overdue` — suspend when retries exhausted or past the window.
  - `billing:soft-delete-cancelled` — canceled (period ended) + expired/suspended (past grace) → SoftDeleted.
  - `billing:hard-delete-old` — SoftDeleted past retention → HardDeleted + PII purge.
  - `billing:reconcile-subscriptions` — drift vs gateway → `reconciliation_discrepancies` (manual review).
  - `billing:send-trial-reminders` — `TrialEndingSoon` event, idempotent via `trial_reminder_sent_at`.
- `SubscriptionChargeService` (ChargeData from stored token, behind IdempotencyService) +
  `DunningService` (renew / charge-failure / scheduleNextRetry / suspend, all via the state machine).
- `BillingMaintenance` flag (cache-backed; full CLI/UI in Phase 8).
- Domain events `SubscriptionRenewed` / `SubscriptionChargeFailed` / `SubscriptionSuspended` /
  `TrialEndingSoon` (Phase 5 listeners consume them).
- Migration: `trial_reminder_sent_at` on subscriptions + `reconciliation_discrepancies` table.
- Tests: `BillingCronsTest` (every command, success + skip paths, via FakeGateway force flags).
  PHPUnit only — crons have no UI.

## Phase 5 — delivered

- `InvoiceService.createPaidForRenewal` (number `INV-YYYYMMDD-{tenant}-{seq}`, totals
  consistent) + `InvoicePdfService` (DomPDF, `resources/views/pdf/invoice.blade.php`,
  table-based, Eternova-branded; stored to `invoices/{tenant}/{number}.pdf`).
- 4 notifications wired to the Phase-4 domain events (SaaS->tenant owner, Spanish copy,
  `mail` + `database`): `TrialEndingNotification`, `InvoiceReadyNotification` (PDF attached),
  `ChargeFailedNotification`, `SubscriptionSuspendedNotification`.
- `BillingNotificationService` routes to the owner User (so the in-app channel works), falling
  back to an on-demand mail to the tenant billing email.
- Listeners (registered in provider): `SendTrialEndingNotification`,
  `SendChargeFailedNotification`, `SendSuspendedNotification`, `CreateInvoiceOnRenewal`
  (issues invoice + PDF + InvoiceReadyNotification on `SubscriptionRenewed`).
- Tests: `BillingNotificationsTest` (invoice/PDF + each event->notification + owner/on-demand
  fallback). `BillingCronsTest` setUp now fakes Storage + Notification (renewal issues invoices).
- **Deferred to Phase 6/7** (need their triggering UI/operator actions): Cancelled + Resumed +
  standalone ChargeSucceeded notifications. Billing emails are SaaS-branded (not tenant brand).

## Phase 6a — delivered (billing account API)

- `billing.manage` gate — **Owner-only** (or super_admin). admin/staff/customer get 403. No
  branch axis. Defined in `BillingServiceProvider`.
- Endpoints under `/api/v1/account/billing/*` (auth:sanctum + tenant):
  - `GET /` overview (current subscription + plan + recent invoices)
  - `GET /invoices` list, `GET /invoices/{id}/download` (PDF stream)
  - `POST /cancel` (at period end), `POST /plan` (change plan, new price next period)
- `TenantBillingService` filters every query by `tenant_id` explicitly (Subscription/Invoice
  are NOT BelongsToTenant) — the isolation boundary. Invoice download resolves by tenant, so a
  foreign id 404s (no route-model binding leak).
- `SubscriptionResource` / `InvoiceResource` (token never exposed), `ChangePlanRequest`.
- Tests: `BillingAccountApiTest` — gate (owner vs admin/staff/customer/other-tenant),
  cross-tenant invoice isolation, cancel + change-plan + download. PHPUnit.

## Phase 6b — TODO (the UI)

Vue SPA pages under `/account/billing/*` (vue-router + axios — NOT Inertia), the payment-method
iframe (needs `WOMPI_PUBLIC_KEY`), Playwright E2E, and qa-engineer visual QA. Cancel/resume UI
actions will emit the deferred Cancelled/Resumed notifications.

## Phase 8 — delivered (ops)

- `billing:maintenance {enable|disable|status} [--format=json]` — toggles the cache-backed flag
  the crons already consult. Registered in `BillingServiceProvider`.
- Dedicated `billing` log channel (`config/logging.php`): own file `logs/billing/billing.log`,
  perms 0600, 1825-day (5-year) retention. `WompiGateway` now logs there.
- `docs/billing/RUNBOOK.md` — maintenance, the 7 crons, Wompi go-live steps, common ops
  (stuck sub, secret rotation, reconciliation, chargeback, refund), logs, idempotency.
- `SECURITY.md` (root) — vuln reporting, PCI SAQ-A scope, the defensive posture summary.
- Tests: `BillingOpsTest` (maintenance CLI enable/disable/status/invalid + log channel config).
  The cross-cutting security invariants stay asserted in their phase suites (webhook HMAC, PCI
  smoke, idempotency, cross-tenant) — referenced, not duplicated.

## Status: phases 1-5, 6a, 8 DONE. Remaining: **6b** (tenant Vue UI + Playwright + qa-engineer,
needs Wompi public key) and **7** (SuperAdmin console — also UI). The whole billing BACKEND +
tenant API + ops are complete and merged to `develop`.

## Phase 7a — delivered (SuperAdmin billing API)

- `super_admin` middleware (`EnsureSuperAdmin`, alias in bootstrap) — hard gate on the
  `is_super_admin` boolean (separate trust axis from tenant RBAC); logs every super-admin access
  (and denied attempt) to the billing channel. Throws AccessDeniedHttpException (this app renders
  a bare `abort(403)` as 500).
- `BillingMetricsService` — cross-tenant MRR (yearly normalized /12), ARPU, counts by state,
  plan distribution, churn rate, total tenants.
- `SuperAdminBillingActionService` — `extendTrial` / `suspend` / `reactivate`. Each re-checks
  super-admin (defense in depth), requires a non-empty reason, and writes an append-only audit
  row stamped with `operator_id` + reason (state changes also audit via the state machine). Added
  `active -> suspended` to the state machine for operator fraud/abuse suspends.
- Endpoints `/api/v1/super-admin/*` (auth:sanctum + super_admin, NO tenant scope; mutations
  throttled 20/min): `GET /billing/metrics`, `GET /tenants`, `GET /tenants/{tenant}`,
  `POST /tenants/{tenant}/{extend-trial|suspend|reactivate}`.
- Tests: `SuperAdminBillingTest` — gate (401/403/200), metrics, audited actions + reason
  requirement + non-super lockout. PHPUnit.
- Served at `admin.eternova.app` (already a reserved `system` subdomain). The Vue console +
  Playwright + qa-engineer are Phase 7b.
