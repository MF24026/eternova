# Sprint 5 — Reservations

> Plan de Sprint 5. Reservas personalizadas para eventos (bodas, arreglos custom, regalos
> corporativos): captura, adelantos parciales, ciclo de vida con estados, y **conversión a
> Order al entregar**. Construye sobre el módulo Orders del Sprint 4.
>
> Última actualización: 2026-06-11.

---

## Objetivo

Que el tenant capture y gestione reservas de eventos: descripción + ocasión + fecha de entrega
+ monto estimado, cobre un **adelanto parcial** (30% default, configurable), avance el workflow
`inquiry → confirmed → in_progress → ready → delivered`, registre pagos hasta saldar, y al
entregar **convierta la reserva en un Order** (descontando lo ya pagado).

---

## Estado actual (auditoría)

| Pieza | Estado |
|---|---|
| `Reservation` / `ReservationPayment` models | Existen (prototipo), pero apuntan a tablas legacy rotas |
| Migración `reservations` (2026_04_17_000008) | **Legacy ROTA**: sin `tenant_id` (el modelo usa `BelongsToTenant`), sin `branch_id`, money en `total_amount`/`deposit_*` (no `_cents`), enum status OK, sin conversión a Order |
| `reservation_payments` (misma migración) | Legacy: sin `tenant_id`, `amount` no `_cents`, `payment_method` sin `other`, sin `recorded_by` |
| Rutas `routes/api/v1/reservations.php` | **Vacías** |
| `ReservationsPage.vue` | Placeholder (calendario mock con data hardcodeada) |
| Tabla `settings` | **Global** (sin tenant_id) — el módulo Settings no está listo. El % de adelanto se guarda en el tenant. |

**Conclusión:** igual que Orders en Sprint 3, hay que hacer **retrofit del schema** y construir el
workflow/UI encima. El módulo Orders (state machine, status_history, sequences, conversión)
es el patrón a calcar.

---

## Cambios de schema (ERD)

### 1. Retrofit `reservations` (drop legacy + recreate)

```
id              bigint PK
tenant_id       ulid FK tenants cascade        -- FIX (faltaba)  + BelongsToTenant
branch_id       ulid FK branches nullOnDelete  nullable          -- multi-sucursal
customer_id     bigint FK customers nullOnDelete nullable
reservation_number  string(40)                 -- RSV-{year}-{seq} unique per tenant
description     text
occasion        string nullable                -- boda, cumpleaños, corporativo...
event_date      date nullable                  -- fecha del evento / entrega
total_cents     unsignedInt default 0          -- monto estimado/acordado
deposit_required_cents  unsignedInt default 0  -- = round(total * pct/100) al confirmar
deposit_paid_cents      unsignedInt default 0  -- suma de payments (derivado, cacheado)
status          enum(inquiry,confirmed,in_progress,ready,delivered,cancelled) default inquiry
special_instructions  text nullable
admin_notes     text nullable
converted_order_id    ulid FK orders nullOnDelete nullable  -- set al convertir
assigned_to     bigint FK users nullOnDelete nullable        -- staff responsable (consistencia con Orders)
created_by      bigint FK users nullOnDelete nullable
timestamps + softDeletes
INDEX(tenant_id, status), INDEX(tenant_id, event_date), UNIQUE(tenant_id, reservation_number)
```

### 2. Retrofit `reservation_payments`

```
id              bigint PK
tenant_id       ulid FK tenants cascade          -- FIX
reservation_id  bigint FK reservations cascade
amount_cents    unsignedInt
payment_method  enum(cash,card,transfer,other)
reference       string nullable
recorded_by     bigint FK users nullOnDelete nullable
paid_at         timestamp
timestamps
INDEX(reservation_id, paid_at)
```

### 3. `reservation_sequences` (mirror order_sequences)

Per-tenant per-year counter → `RSV-{year}-{NNNN}`, lock `FOR UPDATE`.

### 4. `reservation_status_history` (mirror order_status_history)

Append-only timeline: `tenant_id, reservation_id, from_status (nullable), to_status, user_id, note, created_at`.

### 5. Config personalizable por tenant (en `tenants`)

- `reservation_deposit_pct` `unsignedTinyInteger default 30` — % de adelanto por defecto,
  **editable por el tenant en la app** (no solo default). `ReservationService` lo lee con fallback 30.
- `reservation_occasions` `json nullable` — **lista de ocasiones personalizable** por tenant
  (boda, corporativo, cumpleaños, quinceañera, …). Si null, se usa una lista default seedeada.
  El campo `reservation.occasion` es texto libre validado contra esta lista (o libre si el tenant
  no la restringe). Cuando el módulo Settings madure (Sprint 7), esta config migra ahí.

### 6. Montos/abonos flexibles (en `reservations`)

`deposit_required_cents` **se setea por defecto** desde `total_cents * pct/100` al confirmar, pero
es **overridable por reserva** — el staff puede fijar el adelanto requerido caso por caso al capturar
(reservas de bodas vs un arreglo chico pueden tener reglas distintas).

---

## Máquina de estados

```
inquiry      → confirmed | cancelled
confirmed    → in_progress | cancelled
in_progress  → ready | cancelled
ready        → delivered | cancelled
delivered    → (terminal)        -- la conversión a Order ocurre aquí
cancelled    → (terminal)
```

- `confirmed` típicamente requiere que el adelanto requerido esté cubierto (regla de negocio en
  el service; se puede forzar con override de admin).
- `ReservationService::transitionTo()` valida + escribe en `reservation_status_history`
  (mismo patrón que Orders).

---

## Lógica de negocio clave

- **Adelanto:** al `confirmed`, `deposit_required_cents = round(total_cents * tenant.pct / 100)`.
- **Pagos parciales:** `recordPayment(reservation, amount_cents, method, user)` → crea
  `ReservationPayment`, recalcula `deposit_paid_cents` (suma de payments), guarda contra sobrepago
  (`deposit_paid > total` rechazado salvo override). `balance_cents = total - deposit_paid`.
- **Conversión a Order (al entregar):** `convertToOrder(reservation)` — atómico:
  crea un `Order` con `source = 'reservation'`, customer/branch de la reserva, un único line item
  "snapshot" describiendo el arreglo (o sin items detallados — total como un concepto), `payment_status`
  derivado de lo pagado (`paid` si deposit_paid >= total, `partial` si parcial), linkea
  `reservation.converted_order_id`, transiciona la reserva a `delivered`. **Idempotente**: no
  convierte dos veces (guard por `converted_order_id`).

---

## Desglose en epics (tickets GitHub)

| Epic | Capa | Entregable |
|---|---|---|
| **S5-E1** | Backend | Retrofit schema: drop legacy + recreate `reservations`/`reservation_payments` (tenant_id, branch_id, `_cents`, number, converted_order_id, softDeletes) + `reservation_sequences` + `tenants.reservation_deposit_pct` + `tenants.reservation_occasions` (json). Fix models + factories. PHPUnit (schema, tenant-scope, relations) |
| **S5-E2** | Backend | State machine + `reservation_status_history`: `transitionTo()`, `allowedTransitions()`, fila inicial en creación. PHPUnit (transiciones válidas/inválidas, terminales, multi-tenant) |
| **S5-E3** | Backend | Adelantos/pagos **flexibles**: `recordPayment()`, `deposit_required` default desde `tenant.pct` pero **overridable por reserva**, `deposit_paid`/`balance`, guard de sobrepago. PHPUnit |
| **S5-E4** | Backend | Conversión a Order: `convertToOrder()` atómico (Order source=reservation, link, payment_status derivado, idempotente). PHPUnit |
| **S5-E5** | Backend | Reservations Admin API (`index+counts/show/store/transition/recordPayment/convert/cancel`) + **Reservation Settings API** (GET/PUT config del tenant: `deposit_pct` + lista de `occasions`). Resources, Form Requests, Policy, rutas. PHPUnit + Playwright (gates) |
| **S5-E6** | Frontend | Vista principal con **toggle Calendario / Lista / Tablero (kanban por estado, drag-to-transition)** + `ReservationService.ts` + types. Playwright |
| **S5-E7** | Frontend | **Formulario de captura** (total + adelanto override + ocasión desde lista del tenant) + página **detalle** (timeline, pagos+balance, transiciones, convertir-a-Order) + **UI de config** (editar % adelanto + gestionar ocasiones). Playwright |
| **S5-E8** | Data/QA | Seeder (reservas demo en todos los estados con pagos + ocasiones personalizadas) + pase `qa-engineer` (desktop/mobile/dark) + e2e full-flow (captura → adelanto → workflow → conversión) |

**Orden:** E1 → E2 → E3 → E4 → E5 (backend secuencial, todos tocan ReservationService/migraciones)
→ E6 → E7 (frontend) → E8 (cierre).

**Personalización por tenant (pedido explícito del usuario):** % de adelanto editable, ocasiones
configurables, y montos/abonos flexibles por reserva. Vista con 3 modos (calendario/lista/tablero).

---

## Decisiones tomadas / por confirmar

- **Deposit % en el tenant** (`reservation_deposit_pct`, default 30) — NO se depende del módulo
  Settings (que es global/no-tenant todavía, llega en Sprint 7). _(decidido)_
- **Detalle = página dedicada** `/admin/reservations/:id` (consistente con Orders). _(default)_
- **Vista principal: calendario + lista con toggle** _(confirmado 2026-06-11)_. Switch
  `[ Calendario | Lista ]`. Calendario por `event_date` (entregas por día del mes, lo natural para
  un negocio de eventos). Lista con tabs por estado + filtros (calca Orders S4). E6 construye ambas.
- **Tracking público de reservas** — fuera de alcance v1 (a diferencia de Orders). Se puede agregar
  después con un token, reusando el patrón de E4 de Orders.

---

## Reglas no negociables

| Regla | Cómo se aplica |
|---|---|
| Retrofit, no parche | Drop de la tabla legacy + recreate, como hizo Orders (S3) |
| Multi-tenant | `tenant_id` + `BelongsToTenant` en TODAS las tablas nuevas (era el bug del legacy) |
| Money en centavos | `_cents` en todo; nunca decimal |
| Dual-layer testing | PHPUnit (dominio/scopes/gates) + Playwright (toda UI) |
| No-Line / dark / sin emojis | Lucide icons, tiers de fondo, dark mode en todas las vistas nuevas |
| Patrón Orders | State machine, status_history, sequences, conversión — calcar el módulo Orders |
