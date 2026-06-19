# Security Policy

## Reporting a vulnerability

Email **security@eternova.app** (placeholder until the SaaS brand is finalized). Please include
a description, reproduction steps, and impact. Do not open public issues for security reports.

We aim to acknowledge within 72 hours and to follow a 90-day coordinated-disclosure timeline.

## Payment data / PCI scope

Eternova never stores raw card data (PAN or CVV). Card capture uses the gateway's hosted
tokenization (Wompi iframe / tokens API); we persist only an opaque gateway **token** (encrypted
at rest, hidden from every serialization) plus display metadata (last4, brand, expiry). This
keeps us in **PCI SAQ-A** scope.

Defensive measures in the billing subsystem:

- Webhooks are HMAC-verified (timing-safe `hash_equals`) before any processing; unsigned or
  mis-signed payloads are rejected with 401.
- Webhook events are idempotent (DB UNIQUE on `provider`+`event_id`) — replays are not
  re-processed.
- Every money operation is idempotent (DB UNIQUE on `key`+`operation`+`tenant_id`) — no
  double-charges on retry.
- Card/token and gateway secrets are marked `#[\SensitiveParameter]` so they are redacted from
  stack traces; gateway exceptions never chain the original (no token leak via `previous`), and
  the gateway response body is never logged.
- Subscription and invoice access is owner-scoped and tenant-isolated; a tenant cannot read or
  download another tenant's billing data.
- Billing has a dedicated, restricted (0600), 5-year-retained log channel for forensics.

## Supported versions

The `main` branch receives security fixes. Pre-release branches (`develop`, feature branches)
are not covered.
