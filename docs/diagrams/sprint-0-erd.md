# Sprint 0 — Modelo Entidad-Relación (ERD)

> **Propósito.** Mapa completo de las tablas que se crean en Sprint 0 antes de tocar migrations. Decisiones de tipos, índices, FKs y casts JSON quedan justificadas acá para que cualquier dev (humano o agente) pueda ejecutar las migrations sin renegociar el contrato.
>
> **Scope:** sólo Sprint 0. Catalog, Inventory, Orders, Reservations, Expenses, Quotations entran en Sprints 1-7 con sus propios ERDs.
>
> Última actualización: 2026-06-04.

---

## 1. Diagrama

```mermaid
erDiagram
    TENANT ||--o{ BRANCH                 : "tiene"
    TENANT ||--o{ TENANT_USER            : "incluye"
    TENANT ||--o{ SUBSCRIPTION           : "contrata"
    TENANT ||--o{ INVOICE                : "facturado a"
    TENANT ||--o{ TENANT_DOMAIN          : "configura"
    USER   ||--o{ TENANT_USER            : "miembro de"
    USER   ||--o{ PERSONAL_ACCESS_TOKEN  : "emite"
    PLAN   ||--o{ SUBSCRIPTION           : "asignado a"
    SUBSCRIPTION ||--o{ INVOICE          : "genera"
    INVOICE      ||--o{ PAYMENT          : "recibe"

    TENANT {
        string id PK "ULID"
        string slug UK "rosa-eterna"
        string name "Floreria Rosa Eterna (admin)"
        string email "billing contact"
        string status "active|suspended|cancelled"
        string business_name "nombre publico visible"
        string logo_url "nullable"
        string primary_color "hex nullable"
        string secondary_color "hex nullable"
        string favicon_url "nullable"
        json brand_extra "tagline, social_links, dark_logo, custom_css"
        string currency "ISO 3 letras, default USD"
        string country_code "ISO 2 letras, default SV"
        string language "es|en, default es"
        string timezone "default America/El_Salvador"
        json locale_extra "date_format, phone_format, tax_defaults"
        timestamp trial_ends_at
        timestamp deleted_at "soft delete"
        timestamps
    }

    BRANCH {
        string id PK "ULID"
        string tenant_id FK
        string name "Sucursal Centro"
        string slug "centro"
        text address
        string phone
        bool is_main
        bool is_active
        timestamps
    }

    USER {
        bigint id PK
        string name
        string email UK
        string password "hashed"
        string avatar_url "nullable"
        bool is_super_admin "default false"
        timestamp email_verified_at
        timestamps
    }

    TENANT_USER {
        bigint id PK
        string tenant_id FK
        bigint user_id FK
        string role "owner|admin|staff|customer"
        timestamp joined_at
    }

    PLAN {
        bigint id PK
        string slug UK "basico|pro|enterprise"
        string name
        text description
        int price_monthly_cents
        int price_yearly_cents
        string currency "USD|COP|SVC"
        json features "feature flags array"
        json limits "max_branches, max_products, etc"
        bool is_active
        int sort_order
    }

    SUBSCRIPTION {
        bigint id PK
        string tenant_id FK
        bigint plan_id FK
        string status "trialing|active|past_due|canceled"
        timestamp trial_ends_at
        timestamp current_period_start
        timestamp current_period_end
        bool cancel_at_period_end
        timestamp canceled_at
        timestamps
    }

    INVOICE {
        bigint id PK
        string tenant_id FK
        bigint subscription_id FK
        string number UK "INV-2026-0001"
        string status "draft|open|paid|void|uncollectible"
        int subtotal_cents
        int tax_cents
        int total_cents
        string currency
        timestamp due_at
        timestamp paid_at
        string wompi_transaction_id "nullable"
        string pdf_url "nullable"
        timestamps
    }

    PAYMENT {
        bigint id PK
        bigint invoice_id FK
        int amount_cents
        string currency
        string method "card|transfer|other"
        string status "pending|succeeded|failed"
        string gateway "wompi|manual"
        string gateway_reference "nullable"
        timestamp paid_at
        json raw_response "respuesta cruda del gateway"
        timestamps
    }

    WEBHOOK_LOG {
        bigint id PK
        string gateway "wompi"
        string event_type "transaction.updated, etc"
        json payload
        string signature
        timestamp processed_at "nullable"
        text error "nullable"
        timestamps
    }

    PERSONAL_ACCESS_TOKEN {
        bigint id PK
        string tokenable_type "App\\Models\\User"
        bigint tokenable_id
        string name "mobile-app|partner-api|..."
        string token UK "hashed sha256"
        json abilities "scopes"
        timestamp last_used_at
        timestamp expires_at "nullable"
        timestamps
    }

    RESERVED_SUBDOMAIN {
        bigint id PK
        string subdomain UK "lowercase"
        string category "system|brand|trademark|profanity|regulated|security"
        timestamps
    }

    TENANT_DOMAIN {
        bigint id PK
        string tenant_id FK
        string domain UK "rosaeterna.com"
        string status "pending|verified|failed"
        string verification_token
        timestamp verified_at "nullable"
        string ssl_status "pending|active|expired"
        timestamp ssl_expires_at "nullable"
        timestamps
    }
```

> **Nota render.** GitHub renderiza Mermaid nativamente. Si lo abrís en otra herramienta, copiar el bloque ` ```mermaid ... ``` ` en https://mermaid.live.

---

## 2. Decisiones de diseño (con razonamiento)

### 2.1 ¿Por qué ULID en `tenants` y `branches` pero `bigint` en el resto?

| Tabla | PK | Razón |
|---|---|---|
| `tenants` | ULID (string) | Aparece en URLs públicas (`{slug}.eternova.app`) pero el `id` se usa internamente como FK. ULID es sortable temporalmente, distribuible (sin coordinación entre instancias) y no expone conteo de tenants al exterior. |
| `branches` | ULID (string) | Misma lógica que tenants — entidad de negocio referenciada en endpoints. |
| `users` | bigint auto-increment | Tabla global, no expone IDs en URLs públicas. Performance de joins importa más que distribución. |
| `plans` | bigint | Tabla chica fija (3-5 rows). Innecesario ULID. |
| `subscriptions`, `invoices`, `payments` | bigint | Alta volumetría operativa. Bigint mejor para FK joins y índices. |
| `tenant_users`, `tenant_domains`, `reserved_subdomains`, `webhook_log`, `personal_access_tokens` | bigint | Tablas auxiliares, no expuestas como recursos REST públicos. |

**Consecuencia importante:** las FK que apuntan a `tenants` son `string(26)` (ULID), las que apuntan a `users`/`plans` son `bigint unsigned`.

### 2.2 ¿Por qué `_cents` (INT) en lugar de `DECIMAL` para precios?

Almacenar siempre en centavos como INT evita los problemas conocidos de precisión de floating point y los edge cases de redondeo de DECIMAL. La conversión a display string vive en el frontend (composable `useFormatCurrency`).

Trade-off: hay que recordar siempre dividir por 100 al mostrar. Lo absorbe el `MoneyResource` o `PriceResource` del backend.

### 2.3 ¿Por qué híbrido (columnas + JSON) para branding y locale?

**Decisión 2026-06-04.** En `tenants`:

**Columnas explícitas** (queryables, indexables, validables):
- Brand núcleo: `business_name`, `logo_url`, `primary_color`, `secondary_color`, `favicon_url`
- Locale núcleo: `currency`, `country_code`, `language`, `timezone`

**JSON `brand_extra` y `locale_extra`** para campos secundarios o futuros:
- Brand extra: `tagline`, `social_links`, `dark_logo_url`, `custom_css_vars`, `webfont_url`
- Locale extra: `date_format`, `phone_format`, `tax_rates_default`, `decimal_separator`

**Por qué.** Los campos núcleo se consumen en cada request (renderizar storefront, calcular moneda en facturas, validar teléfono). Necesitan ser indexables y validables. Los campos secundarios son flexibles, raros de filtrar, y agregar uno nuevo no debería requerir migration.

**Para `plans.features` y `plans.limits` se mantiene JSON puro** — son arrays/maps complejos que mutan según evolución del producto, sin queries de filtrado interno.

### 2.4 ¿Por qué `webhook_log` sin FK explícita a `tenants`?

Los webhooks pueden llegar de tenants que ya fueron eliminados o de gateways que mandan ruido. Mantener la tabla de log "indestructible" facilita debugging post-mortem.

### 2.5 Soft deletes

| Tabla | Soft delete | Razón |
|---|---|---|
| `tenants` | Sí (`deleted_at`) | Nunca eliminar tenants reales — historial fiscal, soporte, recuperación. |
| `branches` | Sí | Histórico de pedidos referencia branch. |
| `users` | No | GDPR-style hard delete por pedido del usuario. |
| `subscriptions`, `invoices`, `payments` | No | Inmutables una vez creados. Cancelación = nuevo status, no delete. |
| Resto | No | Lookup tables. |

### 2.6 Roles dentro del tenant

`tenant_users.role` es ENUM con valores: `owner` | `admin` | `staff` | `customer`.

- **owner** — único que puede cancelar suscripción, eliminar tenant, agregar otros admins
- **admin** — puede todo menos billing/owner-management
- **staff** — POS, inventory, orders. Sin acceso a settings ni reportes financieros
- **customer** — cliente del storefront (no del admin)

Tabla `users` tiene además `is_super_admin` boolean para super-admin SaaS (panel de gestión de tenants), independiente del tenant.

---

## 3. Índices obligatorios

> Cada índice se justifica con la query que lo motiva. Sin justificación, no se crea índice.

| Tabla | Índice | Motivo |
|---|---|---|
| `tenants` | `UNIQUE (slug)` | Resolver tenant desde subdomain (`{slug}.eternova.app`) |
| `tenants` | `INDEX (status)` | Listado super-admin filtrado por status |
| `tenants` | `INDEX (country_code, currency)` | Reportes super-admin por país/moneda |
| `tenants` | `INDEX (deleted_at)` | Soft delete filter (default scope) |
| `branches` | `UNIQUE (tenant_id, slug)` | Slug único dentro de un tenant |
| `branches` | `INDEX (tenant_id, is_active)` | Listado de branches activas del tenant |
| `users` | `UNIQUE (email)` | Login |
| `users` | `INDEX (is_super_admin)` | Listado super-admin |
| `tenant_users` | `UNIQUE (tenant_id, user_id)` | Un user solo tiene un rol por tenant |
| `tenant_users` | `INDEX (user_id)` | "Lista de tenants a los que pertenece este user" (para tenant switcher) |
| `plans` | `UNIQUE (slug)` | Lookup por slug "basico"/"pro"/"enterprise" |
| `plans` | `INDEX (is_active, sort_order)` | Listado ordenado en pricing page |
| `subscriptions` | `INDEX (tenant_id, status)` | Suscripción activa del tenant |
| `subscriptions` | `INDEX (current_period_end)` | Job de renovación |
| `subscriptions` | `INDEX (trial_ends_at)` | Job de fin de trial |
| `invoices` | `UNIQUE (number)` | Único globalmente |
| `invoices` | `INDEX (tenant_id, status, due_at)` | Listado de facturas pendientes del tenant |
| `invoices` | `INDEX (subscription_id)` | Facturas de una suscripción |
| `payments` | `INDEX (invoice_id, status)` | Pagos exitosos de una invoice |
| `payments` | `INDEX (gateway, gateway_reference)` | Lookup desde webhook |
| `webhook_log` | `INDEX (gateway, event_type, created_at)` | Búsqueda por gateway y evento |
| `webhook_log` | `INDEX (processed_at)` | Jobs que retoman webhooks no procesados |
| `personal_access_tokens` | (default Sanctum) | — |
| `reserved_subdomains` | `UNIQUE (subdomain)` | Validación en signup |
| `reserved_subdomains` | `INDEX (category)` | Reportes / mantenimiento |
| `tenant_domains` | `UNIQUE (domain)` | Lookup desde host header |
| `tenant_domains` | `INDEX (tenant_id, status)` | Listado de custom domains del tenant |
| `tenant_domains` | `INDEX (ssl_expires_at)` | Job de renovación SSL |

---

## 4. Orden de migrations

Las migrations se ejecutan en este orden para respetar dependencias de FK:

```
01. create_users_table              (existe en Laravel default — adaptar)
02. create_cache_table              (Laravel default)
03. create_jobs_table               (Laravel default)
04. create_personal_access_tokens   (Sanctum default)
05. create_tenants_table
06. create_branches_table           (FK tenants)
07. create_tenant_users_table       (FK tenants + users)
08. create_reserved_subdomains
09. create_tenant_domains_table     (FK tenants)
10. create_plans_table
11. create_subscriptions_table      (FK tenants + plans)
12. create_invoices_table           (FK tenants + subscriptions)
13. create_payments_table           (FK invoices)
14. create_webhook_log_table
15. add_is_super_admin_to_users     (adaptación al users default)
```

---

## 5. Seeders

### 5.1 ReservedSubdomainsSeeder
~120 entradas categorizadas:
- **system:** admin, api, app, www, auth, login, signup, billing, docs, status, support, help, blog, cdn, static, assets, public, private, dev, staging, prod, test, demo, debug
- **brand:** eternova, eter, terno-no-va, "carol-creaciones" (legacy guard)
- **trademark:** shopify, stripe, paypal, mercadopago, wompi, amazon, google, microsoft, apple, facebook, instagram, twitter, whatsapp, tiktok, youtube
- **profanity:** lista corta de palabras prohibidas
- **regulated:** bank, banco, lawyer, abogado, doctor, medico, pharmacy, farmacia, gobierno, government
- **security-sensitive:** ssl, oauth, token, password, secret, root, sudo

### 5.2 PlansSeeder
3 planes Básico/Pro/Enterprise con `features` y `limits` json definidos.

### 5.3 DemoTenantsSeeder
- **Tenant 1:** "Floristería Rosa Eterna" (slug `rosa-eterna`, plan Pro, trial activo, 1 branch principal, 1 owner + 3 staff users)
- **Tenant 2:** "Regalos Tatiana" (slug `tatiana`, plan Básico, trial activo, 1 branch principal, 1 owner + 2 staff)

### 5.4 SuperAdminUserSeeder
1 usuario con `is_super_admin = true` para acceso a `/super-admin`.

---

## 6. Tests de aislamiento mínimos (foundation)

Estos tests son la **base de confianza** del sistema multi-tenant. Si fallan, todo lo demás está mal.

1. `TenantScopeTest::test_belongs_to_tenant_trait_filters_queries_automatically()` — query `Branch::all()` desde tenant A devuelve sólo branches del A.
2. `TenantScopeTest::test_creating_model_without_tenant_throws_exception_outside_tenant_context()` — instanciar `Branch::create([...])` sin tenant resuelto debe fallar.
3. `TenantUserPolicyTest::test_user_of_tenant_a_cannot_be_added_as_admin_of_tenant_b_unless_explicitly_invited()`
4. `SubscriptionPolicyTest::test_only_owner_role_can_cancel_subscription()`
5. `SubscriptionPolicyTest::test_admin_role_cannot_cancel_subscription_even_within_own_tenant()`

---

## 7. Próximos pasos

1. **Crear los 11 issues de Sprint 0 en GitHub** (uno por épica S0-E1 a S0-E11)
2. **Branch `feature/sprint-0-tenancy-schema`** desde develop → implementa migrations 05-09 + models + factories + seeders + tests de aislamiento
3. **Branch `feature/sprint-0-plans-billing-schema`** en paralelo → migrations 10-14
4. **Branch `feature/sprint-0-auth-schema`** → migraciones 01-04 + 15

Cada branch sigue DoR/DoD definidos en `docs/engineering/engineering-process.md` §6.
