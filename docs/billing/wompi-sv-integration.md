# Wompi SV — integration spec (provider answers)

Source of truth for the Wompi **El Salvador** integration, from a direct technical Q&A with
the provider (2026-06) plus the public docs at https://docs.wompi.sv. This is **Wompi SV**, not
Wompi Colombia — the API uses Spanish endpoints/fields and different conventions. Keep this in
sync with `app/Modules/Billing/Gateways/WompiGateway.php`.

## Card capture / iframe (Q1)
- **Hosted fields** (individual card fields hosted by Wompi via iframe) — available. Use this for
  the Phase 6b payment-method UI so the PAN never touches our domain (PCI SAQ-A).
- **Hosted checkout page** (redirect + return) — also available.
- Drop-in JS widget — NOT available.

## Tokenized recurring charges (Q2, Q3)
- The card **token does not expire**. If the customer changes card, you must **re-tokenize**.
- **Max $1,000 USD per transaction.** No limit on number of recurring charges.
- **No preauthorization endpoint.** Card validation requires a real charge; there is no
  immediate-reverse endpoint (refunds go through the bank). Do NOT build a $1-auth-then-refund
  validation loop.

## Endpoints (Q9, docs)
- Tokenize card: `POST /TokenesTarjeta` → returns `tokenTarjeta` (the durable token).
- Create purchase: `POST /TransaccionCompra` (https://docs.wompi.sv/metodos-api/crear-transaccion-compra)
- Refund: `POST /Reembolsos`
- Auth: bearer token (the application's API key).

### `POST /TransaccionCompra` request body
| Field | Type | Notes |
|---|---|---|
| `monto` | decimal | **USD amount (dollars, NOT cents)** = `amount_cents / 100`, must be > 0, <= 1000 |
| `emailCliente` | string | required |
| `nombreCliente` | string | required |
| `tarjetaCreditoDebido` | object | raw card (`numeroTarjeta`, `cvv`, `mesVencimiento` int, `anioVencimiento` int) — OR a token |
| `idExterno` | string(50) | **our dedupe key** (unique per operation) — Wompi has no `Idempotency-Key` header |
| `cantidadCuotas` | int | installments (1 for us) |

> The exact field name for charging with a STORED token (vs raw card) in `/TransaccionCompra`
> is assumed to be `tokenTarjeta`; **confirm against the full doc / a sandbox call** before going
> live. The token itself comes from `/TokenesTarjeta`.

### `POST /TransaccionCompra` response body
| Field | Type | Notes |
|---|---|---|
| `idTransaccion` | string | the transaction id |
| `esAprobada` | **bool** | approval status — **there is NO status/estado string** |
| `esReal` | bool | false when the app is in non-productive (test) mode |
| `codigoAutorizacion` | string | on success |
| `mensaje` | string | error text on failure (free Spanish text, not coded) |
| `monto` | decimal | |
| `idExterno` | string | echoes our dedupe key |

Map: `esAprobada === true` → success (`idTransaccion`); otherwise a decline (we classify as
`CardDeclined` since SV gives free-text `mensaje`, not coded reasons).

## Webhooks (Q4, Q10)
- Wompi SV sends webhooks **only for transaction success/failure**. There is **NO refund webhook
  and NO chargeback webhook** — those are dashboard-only. Our `TransactionRefundedHandler` /
  `ChargebackHandler` therefore never fire from SV; refunds are caught by the `reconcile` cron.
- Retries: exponential **5s–30s, max 15** attempts.
- Signature (https://docs.wompi.sv/webhook/validar-webhook):
  - Header: **`wompi_hash`**
  - Algorithm: **HMAC-SHA256 of the raw body** (read the body verbatim, no added whitespace/newlines),
    key = the application's **API Secret**.
  - This matches our `WompiGateway::verifyWebhookSignature()` (HMAC-SHA256 raw body); only the
    header name differs from the Colombian assumption (was `X-Event-Checksum`).
- The webhook references the transaction via `IdTransaccion`. The full webhook envelope shape is
  not in the public doc — **capture a real non-productive webhook and confirm the payload field
  mapping in `TransactionUpdatedHandler` before go-live.**

## Cards / commercials (Q5, Q6)
- **Only Visa + Mastercard** (no Amex). Foreign cards need activation; can be blocked by BIN.
- Fee **3.50%** per approved transaction (https://wompi.sv/Tarifas). No fee on declines or refunds.
  Settlement **T+1**. No monthly/maintenance fee.

## Sandbox / testing (Q7)
- **No separate sandbox environment.** Only production exists; testing uses the application in
  **"modo no productivo"** (non-productive mode), which simulates the purchase-transaction
  endpoint without a real charge (`esReal=false`). Other endpoints operate in production.
- **Test card:** `5200000000002235`, CVV any, exp **01/2029**.
- Chargebacks cannot be simulated (only successful transactions are notified).

## Idempotency (Q9b)
- No standard `Idempotency-Key` header. Use the **`idExterno`** body field (unique per operation,
  max 50). Our `IdempotencyService` ledger is keyed independently (key+operation+tenant) and we
  also pass `idExterno` to Wompi for provider-side dedupe.

## `.env` to go live
```
BILLING_DRIVER=wompi
WOMPI_BASE_URL=<confirm SV API base host>   # docs are at docs.wompi.sv
WOMPI_PUBLIC_KEY=...      # hosted-fields/tokenization (frontend)
WOMPI_PRIVATE_KEY=...     # API bearer (server)
WOMPI_EVENTS_SECRET=...   # = the API Secret used as the wompi_hash HMAC key
```
Point the Wompi dashboard webhook at `POST /api/v1/billing/webhooks/wompi`. Toggle the app to
non-productive mode for QA, then run the test card through a real charge → confirm transitions +
invoice issuance.
