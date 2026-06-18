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
| 3 | Webhook receiver — HMAC verify, idempotency, queue dispatch, handlers | — | TODO |
| 4 | Crons — recurring charges, dunning retry, suspend, soft/hard delete, reconcile, trial reminders | — | TODO |
| 5 | Notifications — 7 templates, invoice PDF, listeners | — | TODO |
| 6 | Tenant UI — `/account/billing/*` (Owner-only), plan selector, payment method iframe, invoices | — | TODO (needs Wompi public key for iframe) |
| 7 | SuperAdmin console — MRR/churn metrics, manual actions with mandatory reason + audit | — | TODO |
| 8 | Ops — RUNBOOK, maintenance mode, billing log channel (1825d), security tests, alerting, SECURITY.md | — | TODO |

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
