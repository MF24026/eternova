# Fiscal & card-audit compliance

In a card-network or tax audit we can be asked to produce the identity document
behind a payment. There are **two distinct documents**, owned by two different
parties — don't conflate them.

## 1. Cardholder document (Wompi-side, panel config)

The person who affiliates their card to a recurring link (the tenant owner) has a
DUI. This document belongs to the **card transaction** and is captured by Wompi's
hosted affiliation page — not by us (the PAN never touches our domain; PCI SAQ-A).

**Action (one-time, in the Wompi business panel):** enable **"Documento de
identidad"** as a required field in the negocio configuration so Wompi collects
and stores the cardholder's DUI on every affiliation. This is what satisfies a
card-network audit request for the cardholder's identity.

We never store the cardholder PAN or token alongside this; Wompi holds it.

## 2. Tenant fiscal id (our side, stored + validated + on the invoice)

Each tenant (the business being billed) has its own fiscal document — **DUI/NIT**
in El Salvador, NIT in Colombia, RFC in Mexico, etc. This is the document that
goes on **our invoices** and answers a tax audit of the SaaS billing.

- **Where it's set:** Settings → Impuestos (`tax` group: `id_label` + `id_number`).
- **Validation:** `App\Support\TaxId` — a per-country `TaxIdStrategy` resolved by
  `TaxIdStrategyFactory::for($tenant->country_code)`. SV validates the DUI check
  digit (`########-#`) and the 14-digit NIT structure; other countries use a
  lenient generic validator labelled from the country catalog. Wired into
  `UpdateSettingsRequest` via the `ValidTaxId` rule, so a malformed number is
  rejected with a 422.
- **On the invoice:** `InvoicePdfService` resolves the tenant's `tax` default and
  renders `{label}: {number}` under "Facturado a" on every invoice PDF.

### Adding a country's check-digit validator

Implement `TaxIdStrategy` (see `SalvadoranTaxIdStrategy`) and register it in
`TaxIdStrategyFactory::for()`. Until then the generic strategy keeps the field
permissive — a wrong rejection of a legitimate id is worse than a lenient store.
