# Sprint 7 — Cotizaciones (PDF)

> Plan de Sprint 7. El tenant arma cotizaciones con líneas de ítems, el sistema calcula
> subtotal/descuento/impuesto/total, y genera un **PDF con la marca del tenant** (logo, colores,
> moneda, país) listo para enviar al cliente. Máquina de estados borrador → enviada →
> aceptada/rechazada/vencida, y conversión **cotización aceptada → pedido** (paralela a
> reserva→pedido de S5).
>
> **PDF desacoplado por driver** — mismo patrón que el OCR de Sprint 6: un
> `QuotationPdfRenderer` (interface) con `DomPdfRenderer` como default (PHP puro, sin headless
> Chrome, liviano en Docker), enchufable a `BrowsershotRenderer` (pixel-perfect Tailwind) más
> adelante si la fidelidad lo amerita. El motor se elige en `config/pdf.php`.
>
> Última actualización: 2026-06-12.

---

## Objetivo

Que el tenant cree cotizaciones profesionales con sus productos/servicios, las exporte como
PDF con su propia marca, las envíe al cliente, y cuando se aceptan, las convierta en un pedido
real sin recapturar nada. Más el ciclo de vida completo (vencimiento automático, historial de
estados).

---

## Estado actual (auditoría)

| Pieza | Estado |
|---|---|
| `Quotation` / `QuotationItem` models | Existen (prototipo), apuntan a tablas legacy rotas |
| Migración `quotations` (2026_04_17_000010) | **Legacy ROTA**: sin `tenant_id` (modelo usa BelongsToTenant), money no `_cents` (`subtotal`/`tax`/`total`), FK a `customers`/`products` sin tenant scope |
| `quotation_items` | Legacy: sin `tenant_id`, `unit_price`/`total` no `_cents` |
| Rutas `routes/api/v1/quotations.php` | **Vacías** (solo comentario placeholder) |
| `QuotationsPage.vue` | No existe aún (o placeholder) — verificar en E6 |
| Librería PDF | **NINGUNA instalada** (composer no tiene dompdf/browsershot) — tarea de E4 |
| Secuencia per-tenant | Patrón ya cristalizado: `order_sequences` / `reservation_sequences` |
| Máquina de estados + status history | Patrón ya cristalizado: Orders / Reservations (`TRANSITIONS` + `transitionTo()` + tabla append-only) |
| Conversión a Order | `ReservationService` ya convierte reserva→pedido vía `OrderService` — clonar el patrón |

**Conclusión:** retrofit del schema (como Orders/Reservations/Expenses) + construir el pipeline
PDF (pieza novel) + la conversión a pedido (patrón conocido) + UI builder de líneas.

---

## Cambios de schema (ERD)

### 1. Retrofit `quotations`
```
id                  bigint PK
tenant_id           char(26) FK tenants cascade   + BelongsToTenant
branch_id           char(26) FK branches nullOnDelete  nullable
customer_id         bigint FK customers nullOnDelete   nullable
quotation_number    string(40)                    -- COT-{year}-{NNNN}, unique per tenant
issue_date          date                          -- fecha de emisión (era 'date')
valid_until         date nullable                 -- vigencia; nula = sin vencimiento
subtotal_cents      unsignedInteger default 0     -- suma de líneas (antes de desc/impuesto)
discount_cents      unsignedInteger default 0     -- descuento global aplicado
tax_rate_bps        unsignedSmallInteger default 0 -- tasa de impuesto en basis points (1300 = 13%)
tax_cents           unsignedInteger default 0     -- impuesto calculado
total_cents         unsignedInteger default 0     -- subtotal - discount + tax
status              enum(draft, sent, accepted, rejected, expired) default draft
notes               text nullable                 -- nota visible al cliente
terms               text nullable                 -- términos y condiciones (visible en PDF)
converted_order_id  char(26) FK orders nullOnDelete nullable  -- set al aceptar+convertir
assigned_to         bigint FK users nullOnDelete nullable
created_by          bigint FK users nullOnDelete nullable
timestamps + softDeletes
UNIQUE(tenant_id, quotation_number)
INDEX(tenant_id, status)        -- lista filtrada por estado
INDEX(tenant_id, issue_date)    -- ordenamiento/rango
INDEX(customer_id)              -- historial por cliente
```

### 2. Retrofit `quotation_items`
```
id              bigint PK
tenant_id       char(26) FK tenants cascade   + BelongsToTenant
quotation_id    bigint FK quotations cascadeOnDelete
product_id      bigint FK products nullOnDelete nullable  -- línea libre o ligada a producto
description     string                        -- snapshot del nombre (no depende del producto)
quantity        unsignedInteger
unit_price_cents unsignedInteger              -- precio unitario congelado al momento
line_total_cents unsignedInteger              -- quantity * unit_price_cents
sort_order      unsignedSmallInteger default 0 -- orden de las líneas en el PDF
timestamps
INDEX(quotation_id, sort_order)
```

### 3. `quotation_sequences` (clon de `reservation_sequences`)
```
id              bigint PK
tenant_id       char(26) FK tenants cascade
year            unsignedSmallInteger
last_sequence   unsignedInteger default 0
timestamps
UNIQUE(tenant_id, year)
```
Número generado dentro de transacción con `lockForUpdate()`: `COT-{year}-{padded_seq}`.

### 4. `quotation_status_history` (clon de `reservation_status_history`)
```
id              bigint PK
tenant_id       char(26) FK tenants cascade
quotation_id    unsignedBigInteger FK quotations cascadeOnDelete
from_status     string nullable    -- null = fila de creación inicial
to_status       string
user_id         bigint FK users nullOnDelete nullable   -- null = sistema (job de vencimiento)
note            text nullable
created_at      timestamp nullable -- append-only, sin updated_at (const UPDATED_AT = null)
INDEX(quotation_id, created_at)
INDEX(tenant_id, created_at)
```

### 5. Config de impuesto por-tenant (sobre el modelo `Tenant`)
Como reservation config (S5) — la tabla `settings` es global hasta Sprint 7-bis/Settings module.
```
quotation_tax_rate_bps   unsignedSmallInteger default 0   -- IVA por defecto del tenant (13% SV, 19% CO)
quotation_valid_days     unsignedSmallInteger default 15  -- vigencia por defecto (valid_until = issue + N días)
quotation_terms          text nullable                    -- términos por defecto que pre-llenan el campo
```
El form de cotización pre-llena `tax_rate_bps`, `valid_until` y `terms` desde estos defaults,
pero el usuario puede sobreescribir por cotización (personalizable, consistente con el resto del SaaS).

---

## Arquitectura PDF (desacoplada por driver — espejo del OCR)

```
QuotationPdfRenderer  (interface)
  └─ render(Quotation $q): string   // devuelve los bytes del PDF
DomPdfRenderer  (default, PHP puro)
  └─ Blade `resources/views/pdf/quotation.blade.php` → dompdf → bytes
Receipt... (n/a)
config/pdf.php → renderer = env('PDF_RENDERER', 'dompdf')
(futuro) BrowsershotRenderer → headless Chrome, pixel-perfect Tailwind, gateado por plan/fidelidad
```

- **Default DomPDF** (`barryvdh/laravel-dompdf`): PHP puro, sin headless Chrome ni node en la
  imagen → liviano, determinista, suficiente para un documento tipo factura/cotización. CSS
  limitado (sin grid/flex moderno) → el template del PDF se escribe con tablas/inline-styles, NO
  reusa las clases Tailwind de la app.
- **Branding del tenant en el PDF:** logo, nombre, colores primario/secundario, moneda, país,
  teléfono, dirección — todo desde el modelo Tenant / brand_extra. El template lee estos valores,
  nunca hardcodea "Eternova". Logo: si el tenant tiene uno en storage, se incrusta; si no,
  fallback al nombre en tipografía serif.
- **Snapshot de datos:** el PDF se renderiza desde los datos congelados de la cotización
  (description + unit_price_cents por línea), NO desde el producto actual — si el precio del
  producto cambia después, el PDF histórico no se altera.
- **Generación on-demand:** `GET /quotations/{id}/pdf` renderiza y devuelve el PDF como descarga
  (`application/pdf`). No se cachea en disco en esta primera versión (barato de regenerar). Si el
  profiling lo pide después, se agrega un `pdf_path` cacheado.
- **Tests:** se renderiza el PDF de verdad con DomPDF (rápido, sin red) y se asserta que (a)
  devuelve bytes no vacíos con cabecera `%PDF`, (b) el contenido incluye el número de cotización
  y el nombre del tenant. NO se compara pixel-a-pixel.

---

## Máquina de estados

```
draft     → sent | accepted | rejected | expired   (se puede aceptar directo sin "enviar")
sent      → accepted | rejected | expired
accepted  → (terminal — al aceptar opcionalmente se convierte en pedido)
rejected  → (terminal)
expired   → (terminal — set por el job de vencimiento)
```
- Toda transición pasa por `QuotationService::transitionTo()` → escribe en `quotation_status_history`.
- `markSent()`, `accept()`, `reject()` son wrappers semánticos sobre `transitionTo()`.
- **Vencimiento automático:** `ExpireQuotationsJob` (scheduled, diario) marca `expired` toda
  cotización con `valid_until < hoy` que siga en `draft`/`sent`. Registra la transición con
  `user_id = null` (sistema).
- **Conversión a pedido:** `accept(convertToOrder: true)` → dentro de una transacción, transiciona
  a `accepted` y llama a `OrderService` para crear un pedido con las líneas de la cotización,
  guardando `converted_order_id`. Clona el patrón `ReservationService` → `OrderService`.

---

## Flujo de usuario

```
1. Nueva cotización: elegir cliente (opcional) → agregar líneas (producto del catálogo o línea
   libre) con cantidad y precio → el sistema calcula subtotal/impuesto/total en vivo → guardar
   como borrador.
2. Descargar/previsualizar PDF con la marca del tenant.
3. Enviar al cliente (status → sent). (Email transaccional queda para integrar después; por ahora
   marca el estado + el usuario comparte el PDF por su canal.)
4. El cliente responde: marcar aceptada o rechazada.
5. Al aceptar: opción "convertir en pedido" → crea el Order con las líneas, linkea converted_order_id.
6. Las cotizaciones vencidas (valid_until pasado) se marcan 'expired' automáticamente.
```

---

## Desglose en epics (tickets GitHub)

| Epic | Capa | Entregable |
|---|---|---|
| **S7-E1** | Backend | Retrofit schema: drop legacy + recrear `quotations` (tenant_id, `_cents`, tax_rate_bps, status, converted_order_id, softDeletes), `quotation_items` (tenant_id, `_cents`, sort_order), `quotation_sequences`, `quotation_status_history`. Config de impuesto/vigencia/terms sobre `Tenant`. Models (final, BelongsToTenant, casts, relations, `UPDATED_AT=null` en history) + factories. PHPUnit (schema, scope multi-tenant, relations) |
| **S7-E2** | Backend | `QuotationService`: secuencia `COT-{year}-{NNNN}` (lockForUpdate), cálculo de totales (subtotal desde líneas → discount → tax desde bps → total), máquina de estados (`TRANSITIONS` + `transitionTo()` + status history + initial history), `markSent`/`accept`/`reject`. Repository + interface + binding. PHPUnit (secuencia concurrente, totales, transiciones válidas/inválidas, historial) |
| **S7-E3** | Backend | Quotations CRUD API: index (+filtros status/customer/fecha/search, paginado, `status_counts`), store (con líneas), show (con líneas+customer), update (reemplaza líneas + recalcula), transiciones (`POST /{id}/send|accept|reject`), destroy. Policy (TenantScopedPolicy), Resources (raw `_cents`), Form Requests, rutas (estáticas antes de wildcard). PHPUnit + Playwright (gates de autorización) |
| **S7-E4** | DevOps/Backend | **Pipeline PDF**: `composer require barryvdh/laravel-dompdf`, `QuotationPdfRenderer` interface + `DomPdfRenderer`, `config/pdf.php` (PDF_RENDERER, default dompdf), binding. Blade `resources/views/pdf/quotation.blade.php` con branding del tenant (logo, colores, moneda, país). Endpoint `GET /{id}/pdf` (descarga). PHPUnit (PDF no vacío, `%PDF`, contiene número + nombre tenant). Verificar que DomPDF corre en la imagen Docker actual sin deps nuevas |
| **S7-E5** | Backend | Conversión **cotización aceptada → pedido** (`accept(convertToOrder:true)` vía `OrderService`, transacción, `converted_order_id`) + `ExpireQuotationsJob` (scheduled diario, marca expired, transición de sistema) + registro en scheduler. PHPUnit (conversión crea pedido con líneas correctas, no doble-convierte; expiry marca solo vencidas en draft/sent) |
| **S7-E6** | Frontend | Lista de cotizaciones + filtros (estado/cliente/fecha/búsqueda) + badges de estado + total por período. `QuotationService.ts`, types (`Quotation`, `QuotationItem`), `constants/quotations.ts` (labels/colores de estado). `QuotationsPage.vue`. Playwright |
| **S7-E7** | Frontend | **Builder de cotización** (slideover/página): editor de líneas (agregar/quitar/reordenar, product-picker del catálogo o línea libre, qty/precio), totales en vivo (subtotal/descuento/impuesto/total), picker de cliente, fecha de vigencia, notas/términos (pre-llenados desde defaults del tenant). Playwright |
| **S7-E8** | Frontend + Data/QA | Preview/descarga de PDF (botón → abre el PDF) + acciones de transición (enviar/aceptar/rechazar) + botón "convertir en pedido" + seeder (cotizaciones demo en todos los estados, con líneas) + pase `qa-engineer` (desktop+mobile+dark, regresiones) + e2e full-flow (crear → líneas → PDF → enviar → aceptar → convertir) |

**Orden:** E1 → E2 → E3 (backend core, secuencial) ; **E4 (PDF, novel/riesgoso → `devops-integration-engineer`)** ; E5 (conversión/vencimiento) ; E6 → E7 → E8 (frontend → cierre).

---

## Decisiones tomadas / propuestas

- **PDF: DomPDF por default, desacoplado por `QuotationPdfRenderer`** (espejo exacto del patrón
  OCR de S6). Browsershot/headless-Chrome queda como driver enchufable futuro si la fidelidad lo
  pide — NO se mete chromium+node en la imagen ahora. _(propuesta — recomendación del tech lead;
  confirmar con el usuario antes de E4)_
- **Conversión a pedido al aceptar** (paralela a reserva→pedido de S5), opcional vía flag en el
  accept — no toda cotización aceptada se vuelve pedido automáticamente. _(decidido)_
- **Config de impuesto/vigencia/términos por-tenant en el modelo Tenant** (como reservation
  config), pre-llena pero el usuario sobreescribe por cotización. Migra a Settings module después. _(decidido)_
- **Snapshot de líneas** (description + unit_price_cents congelados) — el PDF histórico no cambia
  si el producto cambia de precio. _(decidido)_
- **PDF on-demand sin cache en disco** en v1; se agrega `pdf_path` cacheado solo si el profiling lo
  pide. _(decidido)_
- **Email de envío** queda fuera de scope de S7 (solo se marca el estado `sent`); la integración con
  el módulo de email transaccional es follow-up. _(decidido)_

---

## Reglas no negociables

| Regla | Cómo se aplica |
|---|---|
| Retrofit, no parche | Drop legacy + recreate (como Orders S3 / Reservations S5 / Expenses S6) |
| Multi-tenant | `tenant_id` + `BelongsToTenant` en TODAS las tablas nuevas (era el bug del legacy) |
| Money en centavos | `*_cents`; impuesto vía `tax_rate_bps` (basis points), nunca decimal/float |
| Transiciones por servicio | Todo cambio de estado vía `transitionTo()` → status history append-only |
| Secuencia sin gaps | `lockForUpdate()` en `quotation_sequences` dentro de transacción |
| PDF desacoplado | `QuotationPdfRenderer` — DomPDF default, Browsershot enchufable |
| Branding del tenant | El PDF lee logo/colores/moneda/país del Tenant — nunca hardcodea "Eternova" |
| Snapshot histórico | El PDF se rinde desde datos congelados de la cotización, no del producto vivo |
| Dual-layer testing | PHPUnit + Playwright (toda UI con form/builder/transición/descarga) |
| No-Line / dark / sin emojis | Lucide icons, tiers de fondo, dark mode en todas las vistas nuevas |
