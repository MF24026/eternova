# Sprint 3 — POS interno (Punto de Venta)

> Plan de Sprint 3. El POS es la terminal de venta presencial del staff. A diferencia del storefront (Sprint 2, que NO crea orders en DB), el **POS SÍ crea Order + descuenta inventario atómicamente**.
>
> Última actualización: 2026-06-09.

---

## Objetivo

Terminal de venta interna: split-view con grid de productos + panel de carrito, búsqueda rápida (nombre/SKU/barcode), selector de cliente (walk-in o registrado), métodos de pago (efectivo/tarjeta/transferencia), checkout que crea Order + descuenta stock del branch actual, recibo imprimible.

---

## Instrucciones del usuario (no negociables en este sprint)

| Instrucción | Cómo se aplica |
|---|---|
| **División de responsabilidades** | Backend: Controller → Service → Repository → Model. Frontend: pages / components / composables / stores / services. Sin lógica de negocio en controllers ni en componentes Vue |
| **localStorage / persistencia** | La "venta en curso" del POS persiste en localStorage (key por tenant+branch+user) — si el staff recarga o se corta la luz, el carrito no se pierde. Se limpia al cobrar |
| **Swipe-to-close** | Todos los slideovers (selector de cliente, detalle de venta) con swipe-to-close via useSlideover |
| **Bottom sheet en mobile** | En mobile, el panel de carrito y los selectores se presentan como bottom-sheet (no slideover lateral) |
| **Multidispositivo** | Desktop: split-view 2:1 (productos | carrito). Tablet: split ajustado. Mobile: productos full + carrito como bottom-sheet con badge de total. Responsive real, mobile-first |

---

## Diseño de referencia

`storage/app/design-reference/carol-creaciones/admin.jsx` función `AdminPOS` (línea ~228) — el diseño pixel-perfect del POS:
- Split-view: izquierda card con search + tabs de categoría + grid `.product-tile`; derecha card `surface-low` con "Venta en curso" (label-gilt + serif #número), lista de items con qty steppers, subtotal/IVA/total (total serif primary grande), métodos de pago (3 botones con primary-container activo), botón `.btn-primary` "Cobrar $X"
- Usa las clases signature ya en app.css (igual que el storefront re-skin de #63)

---

## Schema — retrofit orders + order_items (legacy del prototipo)

Las tablas `orders`/`order_items` existen pero son legacy (2026_04_17_*): les falta `branch_id`, usan columnas viejas en vez de `_cents`, y `order_items` apunta a `product_id` en vez de `product_variant_id` (modelo Shopify). Igual que #31 hizo drop-legacy del catálogo, hay que alinearlas.

**`orders` (retrofit):**
- id ULID (operacional pero referenciado en recibos públicos → ULID)
- tenant_id ULID FK, branch_id ULID FK (NUEVO — multi-sucursal)
- customer_id bigint FK nullable (walk-in = null)
- order_number string unique per tenant (`CC-2026-0001`)
- status enum: pending|preparing|ready|dispatched|delivered|cancelled (default pending; POS sales arrancan en `preparing` o `ready` según flujo)
- source enum: pos|catalog|reservation (POS = `pos`)
- subtotal_cents, tax_cents, discount_cents, total_cents (int)
- payment_method enum: cash|card|transfer|other
- payment_status enum: pending|partial|paid (POS cobrado = `paid`)
- notes text nullable, user_id FK (quién vendió), timestamps
- Índices: UNIQUE(tenant_id, order_number), INDEX(tenant_id, branch_id, status), INDEX(tenant_id, created_at)

**`order_items` (retrofit):**
- id bigint, order_id ULID FK cascade
- product_variant_id bigint FK (modelo Shopify, NO product_id)
- quantity int, unit_price_cents int, total_cents int
- product_snapshot json (name + variant options al momento de la compra — inmutable aunque el producto cambie después)
- timestamps

**`customers`** ya existe (tenant_id, name, phone, whatsapp, email) — solo agregar lo que falte para el selector (search por nombre/teléfono). Validación de teléfono por país del tenant (skill i18n-latam).

---

## OrderService — atomicidad crítica

`createFromPos(branch, items, payment, customer?)`:
- Wrap en `DB::transaction()`
- Genera order_number único per tenant
- Crea Order + OrderItems (con product_snapshot)
- **Descuenta inventario**: por cada item, `InventoryService::recordExit(branch, variant, qty, user, referenceType: 'Order', referenceId: order.id)` — reutiliza el servicio atómico de #35 con lockForUpdate
- Si algún variant no tiene stock suficiente → `DomainException`, rollback completo (no se crea la order)
- payment_status = paid, status = preparing
- Retorna la Order con items para el recibo

---

## Épicas

| # | Épica | Owner | Depende |
|---|---|---|---|
| S3-E1 | Schema retrofit orders+order_items + OrderService atómico (crea order + descuenta inventario) | software-architect + backend-developer | — |
| S3-E2 | Customers CRUD + búsqueda (selector POS, validación teléfono i18n) | backend-developer | — |
| S3-E3 | POS backend: POSController + checkout endpoint + receipt data | backend-developer | S3-E1, S3-E2 |
| S3-E4 | POS frontend split-view (diseño AdminPOS, localStorage venta en curso) | frontend-developer | S3-E3 |
| S3-E5 | Métodos de pago + cobrar + recibo/ticket imprimible (HTML print) | frontend-developer | S3-E4 |
| S3-E6 | Multidispositivo: bottom-sheet mobile + swipe-close + responsive | frontend-developer + ui-ux | S3-E4 |
| S3-E7 | Tests dual-layer + QA visual del POS (desktop/tablet/mobile) | backend + qa-engineer | todas |

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Oversell: 2 cajas venden el último item simultáneo | InventoryService.recordExit ya usa lockForUpdate (de #35); test de concurrencia |
| Venta en curso se pierde al recargar | localStorage por (tenant, branch, user); restaurar al montar el POS |
| order_number colisiona bajo concurrencia | Generación dentro de la transacción con lock o secuencia per-tenant; test |
| Retrofit rompe data legacy | Greenfield (sin data productiva); drop-legacy como #31 |
| product cambia precio después de la venta | product_snapshot inmutable en order_items |

---

## Próximos pasos

1. Issues S3-E1 a S3-E7 en GitHub
2. Arrancar S3-E1 (schema + OrderService) — base de todo
3. S3-E2 (customers) en paralelo con S3-E1 (no se solapan)
4. Luego S3-E3 (POS backend), S3-E4/E5/E6 (frontend), S3-E7 (tests + QA)
