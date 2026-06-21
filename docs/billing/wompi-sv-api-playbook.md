# Wompi SV — API Integration Playbook (reusable)

A from-scratch, project-agnostic guide to integrating **Wompi El Salvador** as a payment API.
Distilled from a real integration where almost every assumption from the Colombian Wompi docs
was wrong. If you only read one thing: **Wompi SV is not Wompi Colombia** — different auth,
Spanish endpoints/fields, a different recurring model, and an undocumented webhook payload.

Official docs: https://docs.wompi.sv · full export: https://docs.wompi.sv/llms-full.txt

---

## 0. Mental model (read first)

- **Card data never touches your server.** The customer enters the card on Wompi's hosted page
  (recurring affiliation link, or hosted checkout). You stay in **PCI SAQ-A**. You store opaque
  ids/tokens + display metadata only — never PAN/CVV.
- **Recurring subscriptions are gateway-managed.** You create a recurring *link*; the customer
  affiliates once on Wompi's page; **Wompi runs the schedule + retries** and notifies you by
  webhook. You do NOT charge a stored token on your own cron.
- **There is no separate sandbox host.** Production is the only environment; you test by putting
  your *application* into "non-productive" mode, which simulates the purchase/affiliation without
  real money (`esReal=false`).
- **Money is decimal USD** in requests (`monto: 9.00`), not cents. Convert at the boundary.

---

## 1. Credentials & auth — OAuth2 client_credentials

The merchant ("negocio") panel gives you, per application:
- **App ID** → `client_id`
- **API Secret** → `client_secret` **and** the webhook HMAC key. (Regeneratable — rotate it if it
  ever leaks.)

Get a Bearer token, cache it (~`expires_in - 60s`), and never log it:

```
POST https://id.wompi.sv/connect/token        (application/x-www-form-urlencoded)
  grant_type=client_credentials
  audience=wompi_api
  client_id=<App ID>
  client_secret=<API Secret>
→ { "access_token":"…", "expires_in":3600, "token_type":"Bearer", "scope":"wompi_api" }
```

Then send `Authorization: Bearer <access_token>` to `https://api.wompi.sv`.

---

## 2. Tokenize a card (if you need server-side tokens)

```
POST https://api.wompi.sv/Tokenizacion
  { "numeroTarjeta":"…", "cvv":"…", "mesVencimiento":1, "anioVencimiento":2029 }   // exp are INTEGERS
→ { "token":"<opaque>", "tarjetaEnmascarada":"5200 0000 XXXX 2235 " }
```
- Token field is **`token`** (not `tokenTarjeta`). The token does NOT expire.
- No brand field; derive last4 from `tarjetaEnmascarada` (strip non-digits, take last 4).
- For subscriptions you usually DON'T need this — the recurring link captures the card for you.

---

## 3. Recurring subscriptions (the main path) — `EnlacePagoRecurrente`

### 3a. Create the recurring link
```
POST https://api.wompi.sv/EnlacePagoRecurrente
  { "diaDePago":21, "nombre":"Plan Pro - Acme", "idAplicativo":"<App ID>",
    "monto":9.00, "descripcionProducto":"Suscripcion mensual" }
→ { "idEnlace":"<uuid>", "urlEnlace":"https://s.wompi.sv/…",
    "urlEnlaceLargo":"https://cargosautomaticos.wompi.sv/EnlaceSuscripcion?…",
    "estaProductivo":false, "urlQrCodeEnlace":"https://…jpg" }
```
- Store **`idEnlace`** as your subscription's gateway id.
- Send the customer **`urlEnlace`** (or the QR) — that's where they affiliate their card. This
  REPLACES any self-hosted card form/iframe.
- `diaDePago` is the day of month Wompi charges. Note: Wompi may set the first charge to the next
  day (observed `diaDePago` come back as input+1) — confirm the first-charge timing for your case.

### 3b. The customer affiliates
On `urlEnlace`: "¿Tienes datos guardados en Wompi?" → **No** (first time) → enters the card →
confirms saving the subscription → **Wompi OTP** (code to the email/WhatsApp on the form) → done.
You get back a **subscription id** (e.g. `7b5c355f-…`) that is **different from the `idEnlace`** —
the idEnlace is the link/product, the subscription id is the affiliated customer. Track this.

### 3c. Cancel / consult
```
POST  /EnlacePagoRecurrente/{idEnlace}                  // deactivate (cancel) the link
GET   /EnlacePagoRecurrente/{idEnlace}                  // link info: { …, "estaActivo":bool }
GET   /EnlacePagoRecurrente                             // list all links
GET   /EnlacePagoRecurrente/{idEnlace}/suscripciones    // affiliates:
      // [{ "id", "estado":"Activa", "pagosRealizados":0, "monto", "idSuscriptor":<email>, "diaPago" }]
```
Use the consult endpoints for reconciliation (compare `estado`/`pagosRealizados` vs your DB).

---

## 4. One-time / interactive charge — `TransaccionCompra/3DS`

For attended payments (NOT unattended recurring):
```
POST https://api.wompi.sv/TransaccionCompra/3DS
  { "monto":1.00, "emailCliente", "nombreCliente", "idExterno":"<unique>",
    "cantidadCuotas":1, "tarjetaCreditoDebido":{ "numeroTarjeta","cvv","mesVencimiento","anioVencimiento" } }
```
- This is the **3DS interactive** flow: it ALSO requires full billing (`email, ciudad, país,
  nombre, apellido, region, teléfono, dirección, redirect, codigo postal`) and a redirect — so it
  is NOT usable for unattended recurring charges. Use `EnlacePagoRecurrente` for subscriptions.
- Response is **`esAprobada` (bool)** + `idTransaccion` + `mensaje` (free-text error). There is
  **no status string** — branch on the boolean.
- **Idempotency:** there is no `Idempotency-Key` header. Send a unique **`idExterno`** (max 50) in
  the body.
- **Max $1,000 USD per transaction.** Visa/Mastercard only (no Amex; foreign cards need activation).

---

## 5. Webhooks

### 5a. Configuring the URL (the gotcha that wastes an afternoon)
Wompi **validates the configured webhook URL by sending it a request and expecting a 200.** If
your endpoint is POST-only and your framework returns 405/500 to the probe (e.g. a GET), Wompi
rejects it as "URL no válida". **Expose a GET on the same path that returns 200.** Keep POST for
the real, signature-verified processing. The URL must be public HTTPS (use a tunnel like
`cloudflared tunnel --url http://localhost:8080` for local testing).

### 5b. Verifying the signature
Every webhook carries header **`wompi_hash`** = `HMAC-SHA256(raw_request_body, API_Secret)`.
Read the body **verbatim** (no reformatting), compute the HMAC with your API Secret as the key,
and compare with a timing-safe equality (`hash_equals`). Reject mismatches with 401.

### 5c. The payload (UNDOCUMENTED — capture it live)
Wompi SV's webhook fires **only for transaction success/failure** — there is **no refund webhook
and no chargeback webhook** (those are dashboard-only; use the consult/reconcile endpoints for
refunds). Retries are exponential 5s–30s, **max 15**. The recurring-charge webhook payload schema
is **not in the docs** — capture a real one (non-productive, via your tunnel) and map your handler
to it. Identify the subscription by the `idEnlace` (and/or the affiliate subscription id).

### 5d. Receiver shape (recommended)
Answer fast (<2s): verify HMAC → dedupe by a stable event id → persist the raw event → queue the
real work → return 204. Process asynchronously and idempotently.

---

## 6. Testing (non-productive mode)
- Put the application in **"modo no productivo"** in the panel. Only the purchase/affiliation
  endpoints simulate; the rest hit production.
- **Test card:** `5200000000002235`, CVV any, expiry `01/2029`. `esReal=false` on simulated txns.
- Chargebacks cannot be simulated.

---

## 7. Field-name cheat sheet (SV, exact casing)

| Concept | Wompi SV field | Note |
|---|---|---|
| Amount | `monto` | **decimal USD**, not cents |
| Customer email / name | `emailCliente` / `nombreCliente` | |
| Dedupe key | `idExterno` | max 50; no Idempotency-Key header |
| Card number / cvv | `numeroTarjeta` / `cvv` | |
| Expiry | `mesVencimiento` / `anioVencimiento` | **integers** |
| Approval | `esAprobada` | **bool**, no status string |
| Transaction id | `idTransaccion` | |
| Recurring day | `diaDePago` | day of month |
| App/merchant id | `idAplicativo` | = your App ID |
| Recurring link id | `idEnlace` | store as gateway id |
| Affiliation URL / QR | `urlEnlace` / `urlQrCodeEnlace` | send to customer |
| Link active flag | `estaActivo` | for reconcile |
| Webhook signature header | `wompi_hash` | HMAC-SHA256(body, API Secret) |

---

## 8. Architecture recommendations (transferable)
- **Wrap the gateway behind an interface** (`charge`, `createRecurringPaymentLink`,
  `cancelRecurringPaymentLink`, `getRecurringLink`, `verifyWebhookSignature`). Provide a
  **FakeGateway** so your whole app + tests run with no credentials; select via a `BILLING_DRIVER`
  env (default `fake`). This is what lets you build/test everything before keys arrive.
- **Idempotency on every money op** at the DB level (UNIQUE on key+operation+tenant).
- **`#[\SensitiveParameter]`** on secrets/card params; never log the response body or chain the
  original exception (its trace can hold a token). Dedicated, restricted, long-retention log channel.
- **The webhook driver MUST be the real gateway** (not the fake) for the receiver to verify real
  signatures — the fake verifies with a fake secret and would 401 a real Wompi webhook.
- **State machine for the subscription lifecycle**, advanced ONLY by webhooks (gateway owns the
  recurrence). Issue the invoice when the approved-charge webhook arrives — that is the only place
  a charge succeeds.

---

## 9. The "it bit us" list (every wrong assumption)
1. It's **SV, not CO** — different host, Spanish endpoints/fields, OAuth not key-pair.
2. Auth is **OAuth2 client_credentials** (App ID + API Secret), not a public/private key pair.
3. `monto` is **dollars**, not cents.
4. Approval is **`esAprobada` (bool)**, there is no status string.
5. Tokenize is **`/Tokenizacion`** (not `/TokenesTarjeta`); token field is **`token`**.
6. Recurring is **gateway-managed** (`EnlacePagoRecurrente` + hosted affiliation), not a token you
   charge on your own cron. Retire any self-charge/dunning crons.
7. Webhook signature header is **`wompi_hash`** (not `X-Event-Checksum`).
8. Wompi **validates the webhook URL with a probe expecting 200** → expose a GET that returns 200.
9. The recurring **webhook payload is undocumented** → capture it live.
10. **No refund/chargeback webhooks** → reconcile via the consult endpoints.
11. `idEnlace` (the link) ≠ the **affiliate subscription id** — track both.
12. **No sandbox host** — use "non-productive" mode + the test card `5200000000002235`.
