# Sprint 1 — Modelo Entidad-Relación (ERD)

> **Propósito.** Schema canónico de los módulos Catalog e Inventory antes de tocar migrations. Decisiones de tipos, índices, FKs, casts JSON, soft deletes — todo justificado para que cualquier dev (humano o agente) implemente sin renegociar el contrato.
>
> **Scope:** sólo Sprint 1 (Catalog + Inventory). Orders, POS, Reservations, Expenses, Quotations entran en Sprints 3-7 con sus propios ERDs.
>
> Última actualización: 2026-06-05.

---

## 1. Diagrama

```mermaid
erDiagram
    TENANT ||--o{ CATEGORY                : "tiene"
    TENANT ||--o{ PRODUCT                 : "tiene"
    TENANT ||--o{ TAG                     : "tiene"
    TENANT ||--o{ BRANCH_INVENTORY        : "stock por sucursal"
    TENANT ||--o{ INVENTORY_MOVEMENT      : "audita"

    BRANCH ||--o{ BRANCH_INVENTORY        : "almacena"
    BRANCH ||--o{ INVENTORY_MOVEMENT      : "registra"

    CATEGORY ||--o{ CATEGORY              : "padre/hijo"
    CATEGORY }o--o{ PRODUCT               : "categoriza (M2M)"

    PRODUCT  ||--o{ PRODUCT_VARIANT       : "variantes"
    PRODUCT  ||--o{ PRODUCT_OPTION        : "opciones"
    PRODUCT  }o--o{ TAG                   : "etiquetado (M2M)"

    PRODUCT_OPTION ||--o{ PRODUCT_OPTION_VALUE : "valores"

    PRODUCT_VARIANT ||--o{ BRANCH_INVENTORY    : "stock por variant"
    PRODUCT_VARIANT ||--o{ INVENTORY_MOVEMENT  : "movimientos"

    USER ||--o{ INVENTORY_MOVEMENT : "registrado por"

    CATEGORY {
        bigint id PK
        string tenant_id FK "ULID ref tenants"
        string name
        string slug "unique per tenant"
        text description "nullable"
        string image_url "nullable"
        bigint parent_id FK "nullable, ref categories"
        int sort_order "default 0"
        bool is_active "default true"
        timestamp deleted_at "soft delete"
        timestamps
    }

    PRODUCT {
        bigint id PK
        string tenant_id FK
        string name
        string slug "unique per tenant"
        text description "nullable"
        string sku_root "nullable, base SKU prefix for variants"
        int base_price_cents "fallback when variant has no price"
        int cost_price_cents "nullable"
        string default_image_url "nullable"
        json gallery "array of image URLs"
        bool is_active "default true"
        bool is_featured "default false"
        decimal tax_rate "nullable, e.g. 0.13 = 13%"
        timestamp deleted_at "soft delete"
        timestamps
    }

    PRODUCT_VARIANT {
        bigint id PK
        bigint product_id FK
        string sku "unique per product"
        string barcode "nullable"
        int price_cents "nullable, falls back to product.base_price_cents"
        int cost_price_cents "nullable"
        int weight_grams "nullable, for shipping"
        json options "Color: Rojo, Tamano: Grande"
        string image_url "nullable, overrides default"
        int position "default 0"
        timestamp deleted_at "soft delete"
        timestamps
    }

    PRODUCT_OPTION {
        bigint id PK
        bigint product_id FK
        string name "Color, Tamano, ..."
        int position "default 0"
        timestamps
    }

    PRODUCT_OPTION_VALUE {
        bigint id PK
        bigint option_id FK
        string value "Rojo, Verde, Grande, ..."
        int position "default 0"
        timestamps
    }

    CATEGORY_PRODUCT {
        bigint id PK
        string tenant_id FK
        bigint category_id FK
        bigint product_id FK
        int sort_order "default 0"
        timestamps
    }

    TAG {
        bigint id PK
        string tenant_id FK
        string name
        string slug "unique per tenant"
        timestamps
    }

    PRODUCT_TAG {
        bigint product_id FK
        bigint tag_id FK
        timestamps
    }

    BRANCH_INVENTORY {
        bigint id PK
        string tenant_id FK
        string branch_id FK "ULID ref branches"
        bigint product_variant_id FK
        int quantity "default 0"
        int reserved "default 0 (held by pending orders)"
        int available "GENERATED quantity - reserved"
        timestamps
    }

    INVENTORY_MOVEMENT {
        bigint id PK
        string tenant_id FK
        string branch_id FK
        bigint product_variant_id FK
        string type "entry|exit|adjustment|transfer"
        int quantity "signed: positive entry, negative exit"
        string reference_type "nullable: Order, Reservation, ManualAdjustment, Transfer"
        string reference_id "nullable: ULID or bigint of the reference"
        text notes "nullable"
        bigint user_id FK "nullable, who triggered"
        timestamp created_at
    }
```

> **Render note.** GitHub renders Mermaid natively. Pasted block also works on https://mermaid.live.

---

## 2. Decisiones de diseño

### 2.1 ¿Por qué bigint para todo Catalog/Inventory (no ULID)?

| Tabla | PK | Razón |
|---|---|---|
| `categories` | bigint | URL pública del storefront usa `slug`, no `id`. Bigint da mejor performance en JOINs y menor tamaño de índice |
| `products` | bigint | Mismo razonamiento. Volumetría esperada: 100-1000 productos por tenant — bigint es suficiente y más rápido |
| `product_variants` | bigint | Alta volumetría operativa (productos × variants); bigint optimiza JOINs en cada Order |
| `product_options`, `product_option_values` | bigint | Lookup tables internas, nunca expuestas en URLs |
| `tags`, `category_product`, `product_tag` | bigint | Pivots y lookups, bigint estándar |
| `branch_inventory`, `inventory_movements` | bigint | Tablas operativas de alta volumetría — bigint estándar para audit ledger |

**Reservamos ULID** para `tenants` y `branches` (entidades de nivel superior referenciadas como FK desde todas las business tables). Esto mantiene la consistencia con Sprint 0 sin penalty en performance.

### 2.2 ¿Por qué `_cents INT` en lugar de `DECIMAL`?

Mismo razonamiento del Sprint 0 ERD §2.2: precision sin redondeos de float, conversión a display en frontend (composable `useFormatCurrency`). Mantiene consistencia con `plans.price_*_cents`, `invoices.*_cents`, `subscriptions`.

### 2.3 Modelo Shopify-adaptado vs WooCommerce

**Decisión del brainstorming 2026-05-15:** modelo tipo Shopify, NO WooCommerce. Razones:

- **Variants como entidad primaria** (no como meta-data del producto). Permite SKU/precio/stock independiente por combinación de opciones
- **Options + option_values normalizados**, no hardcoded en product fields. Permite agregar opciones dinámicamente
- **Categories M2M** (un producto puede estar en múltiples categorías), no jerarquía única
- **Tags M2M** separados de categories — diferente semántica (filtros tipo "rebajas", "nuevo", "regalo")

WooCommerce mezcla product attributes en una tabla `wp_postmeta` polimórfica, hace queries pesadas y dificulta gating multi-tenant. Shopify-style es más caro de implementar inicialmente pero escala mejor.

### 2.4 `branch_inventory.available` como GENERATED column

MySQL 8 soporta generated columns. `available = quantity - reserved` se calcula automáticamente en cada lectura.

**Ventaja:** imposible que `available` quede inconsistente con `quantity - reserved`. Cero código de aplicación que pueda olvidarlo.

**Trade-off:** ALTER TABLE para modificar la fórmula requiere downtime breve. Aceptable porque la fórmula nunca cambia.

### 2.5 `inventory_movements.quantity` signed (positivo/negativo)

La columna acepta tanto entries (+10) como exits (-3). El campo `type` discriminates la operación.

**Alternativa rechazada:** columna `quantity` siempre positiva + flag `direction`. Es más verbose y requiere CASE en cada query analítica. Signed es estándar para ledger systems.

### 2.6 Soft deletes selectivos

| Tabla | Soft delete | Razón |
|---|---|---|
| `categories` | Sí | Pedidos pasados pueden referenciar — preservar historia |
| `products` | Sí | Mismo motivo + analytics retrospectivos |
| `product_variants` | Sí | Mismo motivo |
| `tags` | No | Lookup table, hard delete OK |
| `product_options`, `product_option_values` | No | Cascade delete con product (variants no se quedan huérfanas porque al borrar el product, sus variants también) |
| `category_product`, `product_tag` | No | Pivots — cascade |
| `branch_inventory` | No | Estado actual, no historia |
| `inventory_movements` | No | **Ledger inmutable** — nunca borrar, sólo append. Compensaciones son nuevos rows con `type=adjustment` |

### 2.7 ¿Por qué `category_product` tiene `tenant_id`?

Por consistencia con la regla "todas las tablas de negocio tienen `tenant_id`". El pivot M2M no es excepción — facilita escaneos por tenant sin JOIN con productos o categories.

El `BelongsToTenant` trait aplica scope global incluso en pivots.

### 2.8 ¿Por qué `product_tag` NO tiene `tenant_id`?

Inconsistencia voluntaria (justificada): `product_tag` no se queryea independientemente. Siempre va via Product que YA tiene `tenant_id` filtrado por scope global.

Agregar `tenant_id` aquí sería **noise sin uso**. Si en el futuro surgen queries directas a `product_tag`, agregamos la columna con migration.

### 2.9 `inventory_movements.reference_type/reference_id` polimórfico

Diferentes flujos generan movimientos con distintos orígenes:
- Order completion → `(reference_type='Order', reference_id=<order_id>)`
- Reservation deposit → `(reference_type='Reservation', reference_id=<reservation_id>)`
- Manual stock adjustment → `(reference_type='ManualAdjustment', reference_id=null)`
- Transfer entre sucursales → 2 rows: exit en branch A + entry en branch B, ambas con `reference_type='Transfer'` y mismo `reference_id` (UUID generado al crear el transfer)

**No usamos polimorfismo Eloquent (`morphTo`)** porque el `reference_id` mezcla bigint (Order, Reservation) y string ULID (futuros). Strings funciona para ambos.

---

## 3. Índices obligatorios

| Tabla | Índice | Motivo |
|---|---|---|
| `categories` | `UNIQUE (tenant_id, slug)` | Slug único por tenant para URLs |
| `categories` | `INDEX (tenant_id, parent_id, sort_order)` | Listado jerárquico ordenado del admin |
| `categories` | `INDEX (tenant_id, is_active)` | Storefront público |
| `categories` | `INDEX (deleted_at)` | Soft delete filter |
| `products` | `UNIQUE (tenant_id, slug)` | URL pública `/products/{slug}` |
| `products` | `INDEX (tenant_id, is_active, is_featured)` | Catalogo destacados + búsqueda |
| `products` | `INDEX (tenant_id, created_at)` | "Nuevos productos" sort |
| `products` | `INDEX (deleted_at)` | Soft delete filter |
| `product_variants` | `UNIQUE (product_id, sku)` | SKU único por producto |
| `product_variants` | `INDEX (product_id, position)` | Listado en order de configuración |
| `product_variants` | `INDEX (barcode)` | Lookup en POS por scanner |
| `product_options` | `UNIQUE (product_id, name)` | "Color" sólo puede aparecer 1 vez por producto |
| `product_options` | `INDEX (product_id, position)` | Render ordenado del editor |
| `product_option_values` | `INDEX (option_id, position)` | Idem |
| `category_product` | `PRIMARY (category_id, product_id)` | Pivot natural |
| `category_product` | `INDEX (tenant_id)` | Audits por tenant |
| `category_product` | `INDEX (category_id, sort_order)` | Productos destacados por categoría |
| `tags` | `UNIQUE (tenant_id, slug)` | Slug por tenant |
| `tags` | `INDEX (tenant_id, name)` | Autocomplete |
| `product_tag` | `PRIMARY (product_id, tag_id)` | Pivot natural |
| `branch_inventory` | `UNIQUE (tenant_id, branch_id, product_variant_id)` | 1 row por (sucursal, variant) |
| `branch_inventory` | `INDEX (tenant_id, branch_id)` | Listado por sucursal |
| `branch_inventory` | `INDEX (product_variant_id)` | Suma de stock total cross-sucursal |
| `inventory_movements` | `INDEX (tenant_id, branch_id, created_at)` | Listado cronológico de movimientos |
| `inventory_movements` | `INDEX (product_variant_id, created_at)` | Historia por variant |
| `inventory_movements` | `INDEX (type, created_at)` | Reportes por tipo |
| `inventory_movements` | `INDEX (reference_type, reference_id)` | Buscar movimientos de un Order/Reservation |

---

## 4. Orden de migrations

Las migrations corren en este orden para respetar dependencias de FK:

```
01. create_categories_table              (FK tenants, self-FK parent_id)
02. create_products_table                (FK tenants)
03. create_product_options_table         (FK products)
04. create_product_option_values_table   (FK product_options)
05. create_product_variants_table        (FK products)
06. create_category_product_table        (FK categories, products, tenants)
07. create_tags_table                    (FK tenants)
08. create_product_tag_table             (FK products, tags)
09. create_branch_inventory_table        (FK tenants, branches, product_variants)
10. create_inventory_movements_table     (FK tenants, branches, product_variants, users)
```

---

## 5. Seeders

### 5.1 CategoriesSeeder (por tenant)
Para cada tenant demo (rosa-eterna, tatiana), crea categorías realistas:

**Rosa Eterna (florería SV):**
- "Arreglos florales" (parent)
  - "Rosas eternas"
  - "Bouquets de novia"
  - "Centros de mesa"
- "Regalos"
  - "Peluches"
  - "Globos"
- "Ocasiones especiales"
  - "Cumpleaños"
  - "Aniversarios"
  - "Bodas"

**Tatiana (regalería CO):**
- "Regalos personalizados"
- "Joyería"
- "Decoración hogar"
- "Detalles corporativos"

### 5.2 ProductsSeeder
~10-20 productos por tenant con 2-3 variants cada uno (Color/Tamaño combos). SKUs realistas, precios en USD para SV y COP para CO. Imágenes mock (URLs placeholders).

### 5.3 TagsSeeder
Tags transversales: "nuevo", "destacado", "oferta", "agotándose"

### 5.4 BranchInventorySeeder
Stock inicial aleatorio (10-100 unidades) por (branch, variant) en cada tenant. Algunos productos en stock 0 para testear alertas. Algunos cerca del threshold para testear alertas low-stock.

### 5.5 InventoryMovementsSeeder
~50 movimientos históricos por tenant (mix de entries/exits/adjustments en los últimos 30 días) para que el dashboard muestre data real.

---

## 6. Tests de aislamiento foundational

Estos tests son la base de confianza del módulo. Si fallan, todo lo demás está mal.

1. `CategoryTenantIsolationTest::test_user_of_tenant_a_cannot_access_categories_of_tenant_b_via_api()` — request a `tenant-b.eternova.app/api/v1/categories/{tenant_b_cat_id}` con auth tenant A → 404
2. `ProductTenantIsolationTest::test_user_of_tenant_a_cannot_view_products_of_tenant_b()` — idem para products
3. `ProductVariantSkuUniquenessTest::test_two_products_can_have_same_sku_but_only_within_different_tenants()` — confirma que la unique constraint es scoped al product, no global
4. `BranchInventoryTenantIsolationTest::test_inventory_query_returns_only_current_tenant_data()` — Branch::factory()->forTenant($a) + ::factory()->forTenant($b), verificar scope global filtra
5. `InventoryMovementAtomicityTest::test_transfer_between_branches_creates_exactly_two_atomic_movements()` — transfer Rosa main → tenant_b main: rechazado (cross-tenant). Transfer dentro del mismo tenant: 2 movimientos atómicos con mismo reference_id

---

## 7. Decisiones diferidas a Sprint 1+ ADRs

| # | Tema | Cuándo decidir |
|---|---|---|
| ADR-006 | Estrategia de imagen storage (S3 vs local con disk abstraction) | S1-E4 (upload imágenes) |
| ADR-007 | Resize on-the-fly vs pre-generated thumbnails | S1-E4 |
| ADR-008 | Soporte para variantes con grids "matrix" estilo Shopify | Sprint 2+ (catalog público) |
| ADR-009 | Bulk import productos CSV/Shopify export | Post-MVP |

---

## 8. Próximos pasos

1. **Crear los 8 issues de Sprint 1 en GitHub** (S1-E1 a S1-E8)
2. **Arrancar S1-E1** (`software-architect` + `backend-developer`) — schema catálogo base sobre este ERD
3. **Después de S1-E1:** paralelizar S1-E2 (CRUD Categorías) + S1-E5 (schema inventory) sin solapamiento
