# Design — Wompi SV recurring billing alignment + live tenant subscribe flow

Date: 2026-06-20
Status: approved (brainstorm), pending spec review
Related: `docs/billing/wompi-sv-integration.md`, `docs/billing/PLAN.md`, PRs #177-188

## Goal

Adapt the subscription billing flow to Wompi SV's **gateway-managed recurrence**
(`EnlacePagoRecurrente`, validated live 2026-06-20) and deliver a tenant UI to subscribe, so we
can run a real end-to-end charge with the test card via a tunnel ("see it live").

Single owner of the recurrence is **Wompi** (Approach A): we create the recurring link, the tenant
affiliates their card on Wompi's hosted page, Wompi charges automatically each month, and we react
to webhooks. We do NOT charge a stored token on our own schedule.

## Validated Wompi SV facts (the integration is built on these)

OAuth2 client_credentials (`POST https://id.wompi.sv/connect/token`, App ID + API Secret → Bearer),
base `https://api.wompi.sv`. Endpoints relevant here:

- **Create recurring link** — `POST /EnlacePagoRecurrente`
  `{diaDePago:int, nombre, idAplicativo:<App ID>, monto:USD, descripcionProducto}` →
  `{idEnlace, urlEnlace, urlEnlaceLargo, estaProductivo, urlQrCodeEnlace}`. (Validated live.)
- **Deactivate (cancel)** — `POST /EnlacePagoRecurrente/{idEnlace}` ("desactivar un enlace
  recurrente"). No documented body.
- **Consult** — `GET /EnlacePagoRecurrente/{idEnlace}` (info), `GET /EnlacePagoRecurrente` (list),
  `GET /EnlacePagoRecurrente/{idEnlace}/suscripciones` (subscribers).
- **Webhook** — `wompi_hash` HMAC-SHA256(raw body, API Secret). **The recurring-charge webhook
  payload schema is NOT documented** → it must be captured live and the handler mapping finalized.
- **Retry/dunning details are NOT documented** — Wompi handles retries; we treat a failed-charge
  webhook as the only signal.

## Lifecycle (Approach A)

```
trialing (tenant) ──clic "Suscribirme"──► create EnlacePagoRecurrente
                                          store idEnlace + urlEnlace; status stays trialing
                                                          │
                              tenant opens urlEnlace, affiliates card on Wompi's hosted page
                                                          │
                                   Wompi charges on diaDePago ──► WEBHOOK (wompi_hash verified)
                                                          │
                        approved ──► trialing|expired → active + invoice + notification
                        declined ──► active → past_due (+ past_due_since)
```

"Link created, awaiting affiliation" is INFERRED (`trialing` + `gateway_subscription_id` set +
`affiliation_url` set) — no new subscription state (YAGNI). Activation is webhook-driven.

## Data model

`subscriptions` (additive migration):
- Reuse `gateway_subscription_id` = Wompi `idEnlace` (what webhooks reference).
- Add `affiliation_url` (string, nullable) = `urlEnlace` (so the UI can show/resume it).
- Add `affiliation_qr_url` (string, nullable) = `urlQrCodeEnlace` (optional display).

No new state enum value.

## Components

### Backend
1. **Gateway additions** (`PaymentGatewayInterface` + Fake + Wompi):
   - `cancelRecurringPaymentLink(string $linkId): bool` → `POST /EnlacePagoRecurrente/{linkId}`.
   - `getRecurringLink(string $linkId): ?array` → `GET /EnlacePagoRecurrente/{linkId}` (reconcile).
   - (`createRecurringPaymentLink` already shipped in #188.)
2. **`SubscribeService`** (Billing): builds `RecurringPlanData` (amount from plan, `diaDePago` =
   `min(now()->day, 28)`, name + description from plan/tenant) → `createRecurringPaymentLink()` →
   stores `plan_id`, `amount_cents`, `gateway_subscription_id`, `affiliation_url`,
   `affiliation_qr_url` on the tenant's current subscription. Returns the affiliation URL/QR.
3. **`POST /api/v1/account/billing/subscribe` {plan_id}** — Owner-only (`billing.manage` gate),
   returns the affiliation URL/QR for the UI.
4. **Webhook alignment** (`TransactionUpdatedHandler`): map by `gateway_subscription_id` (idEnlace).
   approved → `trialing|expired → active`, set `next_billing_at`/`last_paid_at`, issue invoice +
   notify. declined → `active → past_due` + `past_due_since`. **Remove the next-retry scheduling**
   (Wompi retries). Exact payload field mapping finalized after capturing a real webhook.
5. **Cancel alignment**: `POST /account/billing/cancel` also calls
   `cancelRecurringPaymentLink(idEnlace)` so Wompi stops charging (best-effort; logged + audited).

### Cron retirement / adjustment (`routes/console.php`, `BillingServiceProvider`)
- **Remove** `billing:process-recurring-charges` + `billing:retry-dunning` (commands, schedule,
  tests). `SubscriptionChargeService` becomes unused → remove. `DunningService.scheduleNextRetry`
  + the retry bookkeeping become unused → remove.
- **Keep, re-based** `billing:suspend-overdue`: trigger by **`past_due_since` age** (configurable
  window), not the now-defunct `retry_count`.
- **Keep unchanged**: `billing:reconcile-subscriptions` (can use `getRecurringLink`),
  `billing:soft-delete-cancelled`, `billing:hard-delete-old`, `billing:send-trial-reminders`.

### Tenant UI (Phase 6b, Vue SPA — vue-router + axios, NOT Inertia)
- `/account/billing`: current plan + status; if not subscribed, a **"Suscribirme"** CTA per plan.
- On subscribe: call the subscribe endpoint → receive the affiliation URL/QR → open `urlEnlace`
  (new tab / redirect) and show the QR as a fallback. A "ya me afilié / refrescar estado" hint;
  the real activation arrives via webhook (the page reflects `active` once the webhook lands).
- Invoices list + download (already backed by 6a). Cancel button → calls cancel endpoint.
- Owner-only; dark mode; no emojis; Lucide icons; Ethereal Boutique tokens.

## "See it live" plan (test card, tunnel)
1. `cloudflared`/`ngrok` tunnel → public HTTPS → set as the Wompi dashboard webhook URL
   (`https://<tunnel>/api/v1/billing/webhooks/wompi`).
2. `BILLING_DRIVER=wompi` (creds already in `.env`, gitignored).
3. Subscribe a demo tenant from the UI → real `urlEnlace`.
4. Affiliate test card `5200000000002235` (CVV any, exp 01/2029) on Wompi's page.
5. `diaDePago` set so Wompi charges soon → **capture the real webhook payload via the tunnel** →
   finalize `TransactionUpdatedHandler` mapping → subscription goes `active` + invoice issued.
6. Test cancel → `POST /EnlacePagoRecurrente/{idEnlace}` deactivates → Wompi stops charging.

## Error handling
- Subscribe: gateway failure → 502-style JSON, subscription unchanged, nothing stored; tenant can
  retry. Idempotency: re-subscribe before affiliation reuses/replaces the pending link.
- Webhook: unknown `idEnlace` → ignore (already handled); invalid HMAC → 401; processing failure →
  job retries (existing).
- Cancel: if the Wompi deactivate call fails, the local cancel still records intent + audits; a
  reconcile/retry surfaces the mismatch (don't leave the tenant unable to cancel locally).

## Testing (dual-layer, non-negotiable)
- **PHPUnit**: subscribe endpoint (creates link via FakeGateway, stores idEnlace+url, owner-only,
  cross-tenant safe); webhook recurring activation (trialing→active + invoice) with the captured
  shape; cancel deactivates the link (FakeGateway records it); `suspend-overdue` on `past_due_since`
  age; removal of the retired commands (they no longer exist / are unscheduled).
- **Playwright + qa-engineer**: the 6b subscribe flow (click Suscribirme → affiliation link/QR
  shown), invoices, cancel; desktop + mobile + dark mode visual QA.

## Open items
- **Recurring webhook payload schema** — undocumented; capture live via the tunnel, then finalize
  the handler mapping (this is part of the live milestone, like the tokenize/charge discovery).
- **Wompi retry cadence** — undocumented; we rely on the failed-charge webhook only.
- **API Secret rotation** before production (it was shared in a screenshot during setup).

## Decomposition (implementation order)
- **M1 — Backend alignment** (PHPUnit): gateway cancel/get methods, SubscribeService + endpoint,
  webhook handler alignment, cron retirement/rebase, data-model migration.
- **M2 — Tenant UI 6b** (Vue + Playwright + qa-engineer): subscribe flow + affiliation + cancel.
- **M3 — Live run**: tunnel + `BILLING_DRIVER=wompi` + real affiliation with the test card +
  capture the webhook + finalize mapping + verify active/invoice + test cancel.
