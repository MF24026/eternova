# Billing RUNBOOK

Operational guide for the Wompi billing subsystem. Read with `docs/billing/PLAN.md` (phase map)
and the `laravel-saas-billing-infrastructure` skill.

## Maintenance mode

Pauses the billing crons so we never charge / suspend / delete mid-migration or during a
gateway incident.

```bash
php artisan billing:maintenance enable
php artisan billing:maintenance status --format=json   # {"maintenance":true}
php artisan billing:maintenance disable
```

While enabled, every `billing:*` cron logs a skip and exits cleanly. Webhooks still arrive and
are recorded; their async processing resumes when you disable maintenance (the queue retries).

**Use it for:** DB migrations touching billing tables, Wompi provider maintenance windows,
suspected billing incidents, or a webhook-secret rotation.

## The daily crons (scheduled in `routes/console.php`)

| Time (UTC) | Command | Effect |
|---|---|---|
| 02:00 | `billing:process-recurring-charges` | charge due active subs; decline → dunning |
| 03:00 | `billing:retry-dunning` | retry past-due on the day 3/7/14 ladder |
| 04:00 | `billing:suspend-overdue` | suspend exhausted/overdue past-due subs |
| 05:00 | `billing:soft-delete-cancelled` | canceled(period ended)/expired/suspended(grace) → soft-deleted |
| 06:00 | `billing:hard-delete-old` | soft-deleted past retention → hard-deleted + PII purge |
| 07:00 | `billing:reconcile-subscriptions` | flag drift vs gateway → `reconciliation_discrepancies` |
| 08:00 | `billing:send-trial-reminders` | dispatch `TrialEndingSoon` (idempotent) |

All commands are idempotent and respect maintenance mode. Run any one ad hoc with
`php artisan <command>`.

## Going live with Wompi SV (from FakeGateway)

This is **Wompi SV (El Salvador)** — full spec in `docs/billing/wompi-sv-integration.md`.

1. Set in `.env`: `BILLING_DRIVER=wompi`, `WOMPI_BASE_URL` (confirm the SV API host),
   `WOMPI_PUBLIC_KEY`, `WOMPI_PRIVATE_KEY`, `WOMPI_EVENTS_SECRET` (= the API Secret, used as the
   `wompi_hash` HMAC key). Never commit these.
2. Point the Wompi dashboard webhook at `POST /api/v1/billing/webhooks/wompi`.
3. There is **no separate sandbox** — toggle the app to **non-productive mode** and run the test
   card `5200000000002235` (CVV any, exp 01/2029) through a real `/TransaccionCompra` charge
   (`esReal=false`, no real money). Confirm the subscription activates + an invoice is issued.
4. Confirm the two ASSUMPTIONS flagged in `WompiGateway`: the stored-token charge field in
   `/TransaccionCompra` (assumed `tokenTarjeta`) and the tokenize response metadata field names —
   capture a real non-productive response and adjust if needed. Webhook signature (`wompi_hash`,
   HMAC-SHA256 raw body) and the dedupe field (`idExterno`) are already aligned.
5. Capture a real webhook payload and confirm `TransactionUpdatedHandler`'s field mapping — SV
   only sends **transaction success/failure** webhooks (no refund/chargeback; the `reconcile`
   cron is the refund-detection path).

## Common operations

- **Stuck subscription**: inspect with `Subscription::find($id)->state()->name()` in tinker. Only
  move it via `->state()->applyTransition(SubscriptionStatus::X)` — never write the column directly
  (the State pattern validates + audits, and `billing_audit_log` is your forensic trail).
- **Webhook secret rotation**: tolerate old + new secret for a 24h window, then drop the old.
  Enable maintenance during the swap if you want to be cautious.
- **Failed reconciliation**: rows land in `reconciliation_discrepancies` (resolved=false). Review,
  then resolve manually after confirming the gateway truth.
- **Chargeback**: recorded in `billing_audit_log` (event `chargeback.created`), NOT auto-suspended.
  Review and, if fraud, suspend from the SuperAdmin console (Phase 7).
- **Refund**: gateway-initiated refunds are audited via the webhook. Operator-initiated refunds
  (Phase 7) require a `$reason` recorded in the audit log.

## Logs

Billing has a dedicated channel: `storage/logs/billing/billing.log` (perms 0600, 1825-day /
5-year retention for tax/AML compliance). `WompiGateway` logs here. It never logs the gateway
response body or card token (PCI).

## Idempotency & money safety

Every charge/refund runs through `IdempotentOperation` (DB UNIQUE on key+operation+tenant_id).
A double cron run or a client retry can never double-charge. Recurring charges are keyed per
billing period; dunning retries per attempt.
