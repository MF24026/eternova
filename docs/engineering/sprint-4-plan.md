# Sprint 4 — Orders y Despachos

> Plan de Sprint 4. El ciclo de vida completo del pedido del tenant: listado por estado,
> workflow de transiciones, timeline de cambios, asignación a staff y tracking público
> compartible para el cliente.
>
> **Construye SOBRE lo que ya existe** del retrofit del POS (Sprint 3): tabla `orders`,
> `order_items`, `order_sequences`, `OrderService::createFromPos()` + `cancel()`,
> `nextOrderNumber()`. NO se rehace nada de eso.
>
> Última actualización: 2026-06-11.

---

## Objetivo

Que el staff gestione el ciclo de vida de cada pedido (venga del POS, del catálogo o de una
reserva) desde un tablero con tabs por estado, avanzando el workflow
`pending → preparing → ready → dispatched → delivered`, viendo el historial de cada cambio,
asignando responsables, y entregando al cliente un **link público read-only** para que siga
su pedido sin loguearse.

---

## Estado actual (lo que YA existe — no rehacer)

| Pieza | Estado |
|---|---|
| `orders` table | Existe. Enum status completo, `source`, `payment_status`, softDeletes, índices |
| `order_items` table | Existe. `product_variant_id`, `product_snapshot` json inmutable |
| `order_sequences` table | Existe. `nextOrderNumber()` con `FOR UPDATE` por tenant |
| `OrderService::createFromPos()` | Atómico (order + items + descuento de stock) |
| `OrderService::cancel()` | Existe. Setea `cancelled`, NO restock (decisión v1), guarda de `delivered` |
| `OrderRepository::paginate(filters)` | Existe la firma (branch/status/customer/date_from/date_to/per_page) |
| Rutas `routes/api/v1/orders.php` | **Vacías** — hay que poblarlas |

---

## Cambios de schema (ERD)

### 1. `orders` — columnas nuevas (migración aditiva, NO drop)

```
assigned_to     unsignedBigInteger nullable  FK users  nullOnDelete   -- staff responsable
tracking_token  string(32)         nullable  UNIQUE                    -- link público
```

- `tracking_token`: se genera en creación (POS y catálogo) con 32 chars random url-safe.
  Backfill en la migración para orders existentes. Índice `unique`.
- `assigned_to`: a quién se le asignó preparar/despachar. Nullable (sin asignar).

### 2. `order_status_history` — tabla nueva (append-only, inmutable)

```
id           bigint  PK
tenant_id    ulid    FK tenants  cascade           -- BelongsToTenant
order_id     ulid    FK orders   cascade
from_status  enum(...status)  nullable             -- null = creación inicial
to_status    enum(...status)
user_id      bigint  FK users    nullOnDelete  nullable   -- null = sistema/cliente
note         text    nullable
created_at   timestamp                              -- SIN updated_at (inmutable)

INDEX (order_id, created_at)
INDEX (tenant_id, created_at)
```

Cada transición (incluida la creación inicial y el cancel) escribe una fila acá.

---

## Máquina de estados (transiciones válidas)

```
pending    → preparing | cancelled
preparing  → ready      | cancelled
ready      → dispatched | delivered | cancelled    (delivered directo = retiro en tienda)
dispatched → delivered  | cancelled
delivered  → (terminal)
cancelled  → (terminal)
```

- `OrderService::transitionTo(Order, string $to, ?User, ?string $note)`: valida la
  transición contra la tabla anterior, lanza `DomainException` si es inválida, escribe en
  `order_status_history` dentro de la misma transacción.
- `cancel()` existente se refactoriza para delegar en `transitionTo($order, 'cancelled', ...)`
  (mantiene la guarda de `delivered`).
- `createFromPos()` escribe la fila inicial (`from = null, to = preparing`).

---

## Tracking público (read-only, sin auth)

- **URL:** `https://{slug}.eternova.app/track/{tracking_token}`
  (dev: `{slug}.eternova.localhost:8080/track/{token}`)
- Resuelve tenant por subdominio + busca order por `tracking_token` (no por id → no enumerable).
- **Endpoint API:** `GET /api/v1/track/{token}` — público, sin `auth:sanctum`, sí pasa por
  `EnsureTenant` (resuelve marca del tenant).
- **Payload saneado** (NO exponer PII ni precios de más):
  - `order_number`, `status`, branch name, fecha estimada/creación
  - timeline: lista de `{ to_status, created_at }` (sin `user_id`, sin `note` interno)
  - marca del tenant (nombre, logo, colores) para brandear la página
  - NADA de: customer PII, totales, items con precio, notas internas
- Página Vue pública (sin sidebar admin), status stepper + timeline, branded por tenant.

---

## Desglose en epics (tickets GitHub)

| Epic | Capa | Entregable |
|---|---|---|
| **S4-E1** | Backend | Máquina de estados: `transitionTo()`, tabla + modelo `order_status_history`, refactor de `cancel()`, fila inicial en `createFromPos()`. PHPUnit: transiciones válidas/ inválidas, historial, aislamiento multi-tenant |
| **S4-E2** | Backend | Asignación a staff: columna `assigned_to`, `OrderService::assign()`, validación (usuario del mismo tenant). PHPUnit |
| **S4-E3** | Backend | Orders Admin API: `OrderController` (index con tabs+counts por estado + filtros, show con detalle completo), `transition`/`assign`/`cancel` endpoints, `OrderResource`/`OrderItemResource`/`OrderStatusHistoryResource`, `OrderPolicy` (tenant + rol), rutas. PHPUnit + Playwright (auth/gates) |
| **S4-E4** | Backend | Tracking público: `tracking_token` (migración + backfill), `PublicOrderTrackingController`, `OrderTrackingResource` saneado, ruta pública. PHPUnit (token válido/ inválido, no fuga de PII, cross-tenant) |
| **S4-E5** | Frontend | Página listado de orders: tabs por estado con counts, filtros (branch/fecha/búsqueda), tabla → navega a detalle. `OrderService.ts`, types, store. Playwright |
| **S4-E6** | Frontend | Vista detalle de order: items + totales + cliente + timeline + controles de transición (avanzar/cancelar) + selector de asignación. Playwright |
| **S4-E7** | Frontend | Página tracking público: read-only, branded por tenant, status stepper + timeline. Playwright |
| **S4-E8** | Data/QA | Seeders (orders demo en todos los estados con historial) + pase visual `qa-engineer` (desktop/mobile/dark) + e2e full-flow |

**Orden de ejecución:** E1 → E2 → E3 → E4 (backend, secuencial por dependencias) en paralelo
posible E1/E2; luego E5 → E6 → E7 (frontend, dependen de la API); E8 al cierre.

---

## Reglas no negociables de este sprint

| Regla | Cómo se aplica |
|---|---|
| División de responsabilidades | Controller → Service → Repository → Model. Sin lógica en controllers ni en componentes Vue |
| Multi-tenant | `order_status_history` con `tenant_id` + `BelongsToTenant`. Tracking público NUNCA cruza tenants |
| Dual-layer testing | PHPUnit (dominio/scopes/gates) + Playwright (toda UI con form/slideover/transición) |
| No-Line Rule | Tabs y timeline separados por shifts de fondo, NO bordes 1px |
| Dark mode | Todas las vistas nuevas (incluida la pública de tracking) |
| Sin emojis | Lucide Icons (status stepper con iconos Lucide) |
| Tracking sin auth | El endpoint público NO pasa por `auth:sanctum`; sí por `EnsureTenant` |

---

## Decisiones de UX (confirmadas con el usuario, 2026-06-11)

- **Detalle de order → página dedicada** `/admin/orders/{id}` (NO slideover). Layout split:
  izquierda items + totales + cliente; derecha timeline + acciones (avanzar estado / asignar /
  cancelar). El contenido es denso y el timeline necesita aire.
- **Tracking público → stepper + timeline solamente.** Status stepper visual
  (`pending → … → delivered`) + lista de fechas de cada cambio + número de pedido + marca del
  tenant. **SIN** items, **SIN** precios, **SIN** PII del cliente. Solo informativo.

---

## Diseño de referencia

`storage/app/design-reference/carol-creaciones/admin.jsx` — buscar el patrón de listados con
tabs (mismo lenguaje visual que Inventory/POS): card contenedor, `.tabs` con counts,
filas zebra (`odd:bg-surface-low`), `.label-gilt` + serif para encabezados, status pills con
`primary-container`. El timeline usa el patrón de stepper vertical con nodos.
