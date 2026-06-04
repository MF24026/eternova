# Arquitectura — Eternova

> **Documento técnico.** Cómo está construida Eternova: stack, capas, contratos, flujos de datos, seguridad.
>
> Para el plan de sprints ver `docs/engineering/engineering-process.md`. Para roadmap ver `docs/roadmap.md`. Para agentes ver `docs/agents.md`.
>
> Última actualización: 2026-06-04 (migración de Inertia → REST API + SPA).

---

## 1. Visión general

Eternova es un **SaaS multi-tenant** construido como **API REST + SPA cliente**. La separación es estricta desde el día 1:

- **Backend Laravel 12** expone una API REST versionada (`/api/v1/*`) con autenticación dual Sanctum (cookies + tokens).
- **Frontend Vue 3 SPA** consume la API vía Axios, navegando con Vue Router 4 y orquestando estado con Pinia.
- Backend y frontend **se sirven desde el mismo dominio por tenant** (`{tenant-slug}.eternova.app`) para que Sanctum SPA con cookies funcione sin CORS complejo.

Esta arquitectura nos cuesta más código por feature, pero nos da:
- **API reutilizable** para futura app móvil nativa, integraciones B2B y partners.
- **Separación clara de responsabilidades** (backend = lógica de negocio + datos; frontend = experiencia + estado UI).
- **Escalabilidad horizontal** real (backend stateless + cache compartido).
- **Disciplina profesional**: contratos versionados y documentados.

---

## 2. Stack tecnológico

### 2.1 Backend
- **Laravel 12** (PHP 8.4) — framework principal
- **MySQL 8.0** — base de datos single, multi-tenant con `tenant_id`
- **Redis** — cache + queue + session driver
- **Laravel Sanctum** — auth dual (cookies SPA + personal access tokens)
- **Scribe** (`knuckleswtf/scribe`) — auto-generación de OpenAPI + HTML docs
- **Intervention Image** — manipulación de imágenes (resize, formats)
- **Tesseract OCR** (via PHP wrapper) — extracción de texto de facturas
- **DomPDF / Browsershot** — generación de PDFs (cotizaciones)
- **Laravel Sail** (Docker) — entorno de desarrollo

### 2.2 Frontend
- **Vue 3** (Composition API + `<script setup>`)
- **TypeScript** (strict mode)
- **Vue Router 4** — routing SPA con guards
- **Pinia** — state management
- **Axios** — HTTP client con interceptors
- **Tailwind CSS 4** — design system Ethereal Boutique
- **Lucide Icons** — iconografía consistente
- **VueUse** — composables utility
- **Vite** — bundler + dev server con HMR

### 2.3 Build y deploy
- **Vite** con múltiples entry points (admin + storefront + super-admin)
- **Vite SSR** o pre-rendering para el storefront público (ADR pendiente, ver §11)
- **Docker** vía Laravel Sail para dev. En prod: servidor PHP + Nginx + Redis + MySQL gestionados.
- **GitHub Actions** para CI (tests + lint + build).

### 2.4 Lo que NO usamos (decisiones cerradas)
- ~~Inertia.js~~ — descartado el 2026-06-04 por decisión de arquitectura REST. Antes se planeaba usar.
- ~~Laravel Breeze con Blade~~ — incompatible con SPA pura.
- ~~Vuex~~ — reemplazado por Pinia.

---

## 3. Capas y responsabilidades

### 3.1 Backend (Laravel)

```
HTTP Request (JSON)
        │
        ▼
┌──────────────────────────────────────────────┐
│  Middleware Stack                            │
│  - EnsureTenant (resuelve tenant_id)         │
│  - Sanctum auth (cookies o token)            │
│  - Policy gates                              │
└──────────────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────────────┐
│  Form Request (validación)                   │
└──────────────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────────────┐
│  Controller (delgado)                        │
│  - Recibe Request validado                   │
│  - Invoca Service con DTO/array              │
│  - Devuelve Resource transformer             │
└──────────────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────────────┐
│  Service (lógica de negocio)                 │
│  - Orquesta múltiples repositories           │
│  - Reglas de negocio                         │
│  - Dispara events                            │
│  - NO conoce HTTP (no usa Request/Response)  │
└──────────────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────────────┐
│  Repository (acceso a datos)                 │
│  - Interface + EloquentImplementation        │
│  - Sólo CRUD + queries                       │
│  - NO lógica de negocio                      │
└──────────────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────────────┐
│  Model (Eloquent)                            │
│  - BelongsToTenant trait                     │
│  - Relaciones                                │
│  - Casts                                     │
└──────────────────────────────────────────────┘
        │
        ▼
        DB (MySQL)
```

**Reglas estrictas:**
- Controllers **NUNCA** acceden directo al Repository o al Model.
- Services **NUNCA** reciben Request objects (sólo DTOs/arrays validados).
- Repositories **NUNCA** contienen lógica de negocio.
- Models **NUNCA** se exponen directamente al API: pasan por Resource transformers.

### 3.2 Estructura de módulos backend

Cada módulo de negocio sigue la misma estructura:

```
app/Modules/{Module}/
  Http/
    Controllers/
      Api/
        V1/
          {Resource}Controller.php   # REST controller (index, show, store, update, destroy)
    Middleware/
      {Module}-specific middleware (si aplica)
    Requests/
      Store{Resource}Request.php
      Update{Resource}Request.php
    Resources/
      {Resource}Resource.php          # Transforma Model → JSON
      {Resource}Collection.php        # Paginación + meta
  Services/
    {Resource}Service.php             # Lógica de negocio
  Repositories/
    {Resource}RepositoryInterface.php
    Eloquent{Resource}Repository.php
  Models/
    {Resource}.php                    # Eloquent model con BelongsToTenant
  Policies/
    {Resource}Policy.php              # Autorización con validación tenant_id
  Events/
    {Resource}Created.php
    {Resource}Updated.php
  Listeners/
    {Listener}.php
  Observers/
    {Resource}Observer.php             # Side effects
  Providers/
    {Module}ServiceProvider.php       # Bindings de interfaces
  Routes/
    api.php                           # Rutas REST del módulo
  Database/
    Factories/
    Seeders/
    Migrations/                       # (o en database/migrations/)
```

### 3.3 Frontend (Vue 3 SPA)

```
resources/js/
  main.ts                            # Entry point
  app.vue                            # Root component con <router-view/>
  router/
    index.ts                         # Configuración Vue Router
    guards.ts                        # Auth guards, tenant guards
    routes/
      admin.ts                       # Rutas /admin/*
      storefront.ts                  # Rutas / (público)
      super-admin.ts                 # Rutas /super-admin/*
      auth.ts                        # Rutas /login, /signup
  pages/
    Admin/
      DashboardPage.vue              # SMART: usa stores, dispara fetches
      Catalog/
        ProductsListPage.vue
        ProductFormPage.vue
      Inventory/
      POS/
      Orders/
      ...
    Storefront/
      HomePage.vue
      ProductDetailPage.vue
      CartPage.vue
    SuperAdmin/
      TenantsListPage.vue
    Auth/
      LoginPage.vue
      SignupPage.vue
    Onboarding/
      PlanSelectionPage.vue
      TenantSetupPage.vue
  components/
    base/                            # ATOMS — sin lógica de negocio
      Button.vue
      Input.vue
      Card.vue
      Badge.vue
      Modal.vue
      Slideover.vue
      Table.vue
      Pagination.vue
      Dropdown.vue
      Spinner.vue
      Avatar.vue
    composite/                       # MOLECULES — combinan atoms
      ProductCard.vue
      ProductVariantSelector.vue
      CartLineItem.vue
      OrderStatusBadge.vue
      PriceDisplay.vue
      KpiCard.vue
    layout/
      AdminLayout.vue
      StorefrontLayout.vue
      SuperAdminLayout.vue
      MarketingLayout.vue
      OnboardingLayout.vue
      AdminSidebar.vue
      AdminTopbar.vue
      StorefrontNavbar.vue
  composables/                       # LÓGICA REUTILIZABLE
    useAuth.ts                       # Login, logout, current user
    useTenant.ts                     # Tenant actual desde subdomain
    useFormatCurrency.ts             # Formato moneda según tenant.locale
    useFormatDate.ts
    useFormatPhone.ts                # Validación por país
    useDebouncedSearch.ts
    useThemeMode.ts                  # Dark mode toggle
    useSlideover.ts                  # Open/close + swipe-to-close
    useToast.ts                      # Notificaciones flotantes
    usePaginated.ts                  # Listados paginados genéricos
    usePermissions.ts                # role + plan gating
  stores/                            # PINIA
    auth.ts                          # currentUser, isAuthenticated, login(), logout()
    tenant.ts                        # tenant actual, branding, locale, plan
    cart.ts                          # carrito storefront persistido en localStorage
    products.ts                      # cache de productos (por tenant)
    orders.ts
    ui.ts                            # sidebar open, dark mode, modales globales
  services/                          # API CLIENTS — encapsulan llamadas HTTP
    api.ts                           # Axios instance con interceptors
    AuthService.ts
    ProductsService.ts
    OrdersService.ts
    InventoryService.ts
    CategoriesService.ts
    POSService.ts
    ReservationsService.ts
    ExpensesService.ts
    QuotationsService.ts
    SettingsService.ts
    BillingService.ts
    TenantsService.ts                # super-admin
  types/                             # TYPESCRIPT TYPES
    api.ts                           # Response wrappers (Paginated<T>, Resource<T>)
    domain/
      User.ts
      Tenant.ts
      Product.ts
      Order.ts
      ...
  utils/
    money.ts                         # cents → display
    slug.ts
    constants.ts
    errors.ts                        # Error parsing de Axios
```

**Reglas estrictas frontend:**
- **Pages** son "smart": pueden acceder a stores y services. Componen vistas completas.
- **Components** son "dumb": sólo reciben props y emiten events. Nunca importan stores ni services directamente.
- **Composables** encapsulan lógica reactiva reutilizable (sin tocar DOM).
- **Stores Pinia** son la única fuente de verdad para estado compartido.
- **Services** son la única forma de hablar con el backend. Components y Pages nunca llaman a `axios` directo.
- **Types** se sincronizan a mano (futuro: generar desde OpenAPI con `openapi-typescript`).

### 3.4 Flujo típico end-to-end

Ejemplo: usuario admin crea un producto desde el panel.

```
1. PAGE         Admin/Catalog/ProductFormPage.vue
                  ↓ usuario submitea form
2. STORE        productsStore.createProduct(formData)
                  ↓
3. SERVICE      ProductsService.create(formData)
                  ↓ axios.post('/api/v1/products', formData)
4. AXIOS        Interceptor inyecta CSRF + tenant header
                  ↓ HTTP POST
5. MIDDLEWARE   EnsureTenant resuelve tenant desde subdomain
                  ↓
6. MIDDLEWARE   Sanctum valida cookie/token + obtiene user
                  ↓
7. POLICY       ProductPolicy::create($user, $tenant) → check role + plan
                  ↓
8. CONTROLLER   ProductController::store(StoreProductRequest)
                  ↓ FormRequest valida payload
                  ↓
9. SERVICE      ProductService::create($validated, $user, $tenant)
                  ↓ orquesta repository + observer + event
10. REPOSITORY  EloquentProductRepository::create($data)
                  ↓
11. MODEL       Product::create() con tenant_id auto desde BelongsToTenant
                  ↓
12. EVENT       ProductCreated::dispatch($product)
                  ↓
13. CONTROLLER  return new ProductResource($product) — JSON 201
                  ↓
14. SERVICE     ProductsService recibe JSON, devuelve a store
                  ↓
15. STORE       productsStore.items.push(newProduct)
                  ↓
16. PAGE        router.push({ name: 'admin.products.show', params: { id } })
```

---

## 4. Multi-tenancy

### 4.1 Estrategia

- **Single database** con `tenant_id` (ULID) en todas las tablas de negocio.
- **Trait `BelongsToTenant`** aplica scope global a Eloquent models. Cualquier `Product::all()` automáticamente filtra por el tenant actual.
- **Middleware `EnsureTenant`** resuelve el tenant al inicio del request y lo bindea al container (`app('current.tenant')`).

### 4.2 Resolución de tenant

Tres modos configurables (`config('tenancy.resolver')`):

| Modo | Ejemplo | Cuándo |
|---|---|---|
| `subdomain` (default) | `rosa-eterna.eternova.app` | Producción y staging |
| `domain` | `rosaeterna.com` (CNAME a Eternova) | Tenants con plan Enterprise |
| `path` | `eternova.app/t/rosa-eterna/...` | Fallback / dev local sin DNS |

Ver skill global `saas-tenant-subdomain-strategy` para detalle DNS + SSL + slug validation + reserved subdomains.

### 4.3 Tablas SaaS (sin tenant_id)

Estos módulos viven en la plataforma, no dentro de un tenant:

- `tenants`
- `branches` (tienen `tenant_id`, son sub-entidades del tenant — son de negocio)
- `plans`, `plan_features`
- `subscriptions`, `invoices`, `payments`, `webhooks_log`
- `users` (los usuarios son globales — el rol dentro del tenant vive en `tenant_users`)
- `tenant_users` (pivot: tenant_id, user_id, role)
- `reserved_subdomains`
- `tenant_domains` (custom domains)

### 4.4 Sucursales

Cada tenant puede tener múltiples sucursales (`branches`). Tablas afectadas: `branch_inventory`, `orders` (origen), `inventory_movements`, `expenses`, `pos_sessions`. Una transferencia entre sucursales es un par de movimientos atómicos (exit en A + entry en B) con misma `reference_id`.

---

## 5. API REST — Convenciones

### 5.1 Versionado

Todas las rutas viven bajo `/api/v1/`. Cuando aparezca una v2 incompatible, se versionará a `/api/v2/` y v1 quedará marcada como deprecated con sunset date.

### 5.2 Convenciones de URL

REST estándar con resource naming en plural:

| Método | URL | Acción |
|---|---|---|
| GET | `/api/v1/products` | listado paginado |
| GET | `/api/v1/products/{id}` | detalle |
| POST | `/api/v1/products` | crear |
| PATCH | `/api/v1/products/{id}` | actualizar parcial |
| PUT | `/api/v1/products/{id}` | reemplazar completo (raro) |
| DELETE | `/api/v1/products/{id}` | soft delete |
| POST | `/api/v1/products/{id}/restore` | restaurar soft-deleted |

Sub-recursos cuando hay relación clara:
- `/api/v1/products/{id}/variants`
- `/api/v1/orders/{id}/items`

Acciones no-CRUD como sub-rutas verbo:
- `POST /api/v1/orders/{id}/mark-ready`
- `POST /api/v1/subscriptions/{id}/cancel`

### 5.3 Response envelope

Todas las respuestas siguen el mismo formato:

```jsonc
// GET /api/v1/products/123
{
  "data": {
    "id": "01HX2K...",
    "name": "Rosa Eterna Carmesí",
    "price": "29.99",
    // ...
  },
  "meta": {
    "tenant_id": "01HW9...",
    "request_id": "req_a1b2c3"
  }
}
```

```jsonc
// GET /api/v1/products  (paginado)
{
  "data": [ /* ... */ ],
  "links": {
    "first": "...?page=1",
    "last":  "...?page=12",
    "prev":  null,
    "next":  "...?page=2"
  },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 240,
    "tenant_id": "01HW9..."
  }
}
```

### 5.4 Errores

Errores siguen RFC 7807 simplificado:

```jsonc
// 422 Unprocessable Entity
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name field is required."],
    "price": ["The price must be at least 0.01."]
  }
}

// 403 Forbidden
{
  "message": "This action is unauthorized.",
  "error_code": "tenant.policy.denied"
}

// 402 Payment Required (plan gate)
{
  "message": "Esta funcionalidad requiere el plan Pro.",
  "error_code": "plan.feature_locked",
  "feature": "multi_branch_inventory",
  "current_plan": "basico",
  "required_plan": "pro"
}
```

### 5.5 Headers

Headers obligatorios del cliente:

| Header | Propósito |
|---|---|
| `Accept: application/json` | Forzar JSON (sin esto Laravel devuelve HTML) |
| `X-XSRF-TOKEN` | CSRF de Sanctum SPA (automático con Axios `withCredentials`) |
| `Authorization: Bearer {token}` | Si usa token-based auth en vez de cookie |

Headers que devolvemos:

| Header | Propósito |
|---|---|
| `X-Request-Id` | UUID para tracing |
| `X-RateLimit-*` | Rate limiting info |

### 5.6 Paginación

Cursor-based para listados con scroll infinito (storefront). Page-based clásico para listados administrativos (admin panel).

```
GET /api/v1/products?page=2&per_page=20
GET /api/v1/products?cursor=eyJpZCI6MTAwfQ&per_page=20
```

### 5.7 Filtros y búsqueda

Filtros simples como query params. Filtros complejos (rangos, OR) como objetos JSON URL-encoded:

```
GET /api/v1/products?category_id=01HX&is_active=1&search=rosa
GET /api/v1/orders?status[]=pending&status[]=preparing&date_from=2026-06-01
```

### 5.8 Documentación

`composer require knuckleswtf/scribe --dev` genera:
- `/docs` — HTML interactiva con try-it-out
- `/docs.json` — OpenAPI 3 spec
- `/docs.postman` — Collection Postman

Cada Controller method tiene PHPDoc para que Scribe extraiga título + descripción + ejemplos. Form Requests definen el schema del body automáticamente.

---

## 6. Autenticación

### 6.1 Doble modo Sanctum

| Modo | Caso de uso | Cómo funciona |
|---|---|---|
| **SPA cookies** | Frontend web (Admin, Storefront, SuperAdmin) | Cliente hace `GET /sanctum/csrf-cookie` → `POST /login` → cookies de sesión + CSRF se almacenan. Axios con `withCredentials: true` envía cookies automáticamente. |
| **Personal Access Tokens (Bearer)** | App móvil futura, partners, integraciones B2B | Cliente hace `POST /api/v1/auth/token` con credentials → recibe `Bearer {token}`. Lo envía en `Authorization` header en cada request. |

Sanctum decide automáticamente cuál modo aplicar según la presencia de cookie de sesión o `Authorization` header.

### 6.2 Stateful domains

`config/sanctum.php`:

```php
'stateful' => [
    'eternova.localhost:8080',
    '*.eternova.localhost:8080',
    'eternova.app',
    '*.eternova.app',
    // tenants con custom domain se agregan dinámicamente
],
```

### 6.3 Roles dentro del tenant

| Rol | Permisos |
|---|---|
| `owner` | Todo. Único que puede cambiar plan, agregar usuarios, eliminar tenant. |
| `admin` | Todo menos billing, agregar admins, eliminar tenant. |
| `staff` | POS, inventory, orders. Sin acceso a settings ni billing. |
| `customer` | Cliente final del storefront (no del admin). |

Los roles se chequean en Policies. Cada policy también valida `tenant_id`.

### 6.4 Roles fuera del tenant

| Rol global | Permisos |
|---|---|
| `super_admin` | Panel `/super-admin/*` con gestión de tenants, soporte, métricas SaaS. |

---

## 7. Modelos de datos (entidades principales)

> Los nombres son referenciales. Cada migración debe respetar `tenant_id` cuando aplique.

### 7.1 Tenancy

```
tenants
  id (ULID), name, slug (unique), email, status (active|suspended|cancelled),
  brand_config (json: logo_url, primary_color, secondary_color, favicon_url, business_name),
  locale_config (json: currency, country_code, language, timezone, phone_format),
  trial_ends_at, created_at, updated_at

branches
  id, tenant_id, name, slug, address, phone, is_main, is_active, created_at

reserved_subdomains
  id, subdomain, category (system|brand|trademark|profanity|regulated|security), created_at

tenant_domains
  id, tenant_id, domain, status (pending|verified|failed), verification_token,
  verified_at, ssl_status, ssl_expires_at, created_at
```

### 7.2 Auth global

```
users
  id, name, email (unique), password, avatar_url, is_super_admin, email_verified_at,
  created_at, updated_at

tenant_users
  id, tenant_id, user_id, role (owner|admin|staff|customer), joined_at

personal_access_tokens   (Sanctum default)
  id, tokenable_type, tokenable_id, name, token (hashed), abilities (json),
  last_used_at, expires_at, created_at
```

### 7.3 Billing SaaS

```
plans
  id, slug (basico|pro|enterprise), name, description, price_monthly_cents,
  price_yearly_cents, currency, features (json), limits (json), is_active, sort_order

subscriptions
  id, tenant_id, plan_id, status (trialing|active|past_due|canceled),
  trial_ends_at, current_period_start, current_period_end,
  cancel_at_period_end, canceled_at, created_at

invoices
  id, tenant_id, subscription_id, number (unique), status (draft|open|paid|void|uncollectible),
  subtotal_cents, tax_cents, total_cents, currency, due_at, paid_at,
  wompi_transaction_id, pdf_url, created_at

payments
  id, invoice_id, amount_cents, currency, method, status (pending|succeeded|failed),
  gateway, gateway_reference, paid_at, raw_response (json), created_at

webhook_log
  id, gateway, event_type, payload (json), signature, processed_at, error
```

### 7.4 Catalog (modelo tipo Shopify)

```
categories
  id, tenant_id, name, slug, description, image_url, parent_id, sort_order,
  is_active, created_at

products
  id, tenant_id, name, slug, description, sku_root, base_price_cents,
  cost_price_cents, default_image_url, gallery (json array urls),
  is_active, is_featured, tax_rate, created_at

product_variants
  id, product_id, sku (unique per tenant), barcode, price_cents, cost_price_cents,
  weight_grams, options (json: {Color: "Rojo", Tamaño: "Grande"}),
  image_url, position, created_at

product_options
  id, product_id, name (Color, Tamaño, ...), position

product_option_values
  id, option_id, value, position

category_product   (pivot M2M, también con tenant_id)
  category_id, product_id, sort_order

tags
  id, tenant_id, name, slug

product_tag
  product_id, tag_id
```

### 7.5 Inventory

```
branch_inventory
  id, tenant_id, branch_id, product_variant_id, quantity, reserved,
  available (generated = quantity - reserved), updated_at
  UNIQUE (tenant_id, branch_id, product_variant_id)

inventory_movements
  id, tenant_id, branch_id, product_variant_id, type (entry|exit|adjustment|transfer),
  quantity, reference_type, reference_id, notes, user_id, created_at
```

### 7.6 Orders

```
orders
  id, tenant_id, branch_id, order_number (unique per tenant), customer_id,
  status (pending|preparing|ready|dispatched|delivered|cancelled),
  source (pos|catalog|reservation), subtotal_cents, tax_cents, delivery_fee_cents,
  total_cents, payment_method (cash|card|transfer|other),
  payment_status (pending|partial|paid), notes, shipping_address (json),
  tracking_id, dispatched_at, delivered_at, created_at

order_items
  id, order_id, product_variant_id, quantity, unit_price_cents, total_cents,
  product_snapshot (json: name, variant options en el momento de la compra)
```

### 7.7 Reservations

```
reservations
  id, tenant_id, branch_id, customer_id, description, occasion, delivery_date,
  total_cents, deposit_required_cents, deposit_paid_cents,
  status (inquiry|confirmed|in_progress|ready|delivered|cancelled),
  special_instructions, admin_notes, converted_to_order_id, created_at

reservation_payments
  id, reservation_id, amount_cents, payment_method, reference,
  recorded_by_user_id, paid_at
```

### 7.8 Expenses

```
expense_categories
  id, tenant_id, name, type (operating|products|payroll|rent|other), is_active

expenses
  id, tenant_id, branch_id, expense_category_id, description, amount_cents, currency,
  expense_date, receipt_url, ocr_data (json), is_verified, vendor, vendor_tax_id,
  notes, user_id, created_at
```

### 7.9 Quotations

```
quotations
  id, tenant_id, customer_id, quotation_number (unique per tenant), issue_date,
  valid_until, subtotal_cents, tax_cents, total_cents,
  status (draft|sent|accepted|rejected|expired), notes, pdf_url,
  converted_to_order_id, created_at

quotation_items
  id, quotation_id, product_variant_id, description, quantity,
  unit_price_cents, total_cents
```

### 7.10 Customers

```
customers
  id, tenant_id, name, email, phone, whatsapp, address, city, state, country,
  notes, total_purchases_cents, last_purchase_at, created_at
```

### 7.11 Settings

```
settings
  id, tenant_id, group, key, value (json), type (string|integer|boolean|json|file),
  description, created_at, updated_at
  UNIQUE (tenant_id, group, key)
```

Grupos: `brand`, `contact`, `locale`, `tax`, `orders`, `quotations`, `reservations`, `notifications`, `pos`, `integrations`.

Cache en Redis con invalidación al UPDATE (Observer).

---

## 8. Flujos principales

### 8.1 Catálogo público → Carrito → Checkout WhatsApp

1. Cliente entra a `rosa-eterna.eternova.app`
2. Vue Router monta `Storefront/HomePage.vue`
3. `productsStore.fetchFeatured()` → `ProductsService.featured()` → `GET /api/v1/storefront/products/featured` (sin auth, scope público)
4. Cliente agrega productos al carrito (`cartStore.addItem(variantId, qty)` persiste en localStorage)
5. Cliente abre carrito → `Storefront/CartPage.vue`
6. Click "Enviar por WhatsApp" → genera `order_draft` con number + arma mensaje → `window.open('https://wa.me/{phone}?text={message}')`
7. Admin recibe mensaje WhatsApp y registra el pedido en su panel.

### 8.2 POS (Venta interna)

1. Staff logueado en `rosa-eterna.eternova.app/admin/pos`
2. Búsqueda de productos por nombre/SKU (`ProductsService.search()`)
3. Agrega al carrito POS (`posStore.addItem`)
4. Selecciona cliente o "Walk-in"
5. Elige método de pago
6. Submit → `POST /api/v1/pos/checkout` → backend crea `Order` + `OrderItems` + `inventory_movements` exit en transaction atómica.
7. Frontend recibe Order, abre receipt para imprimir.

### 8.3 Reserva personalizada

1. Cliente describe en storefront (público) o admin la captura.
2. `POST /api/v1/reservations` con descripción, fecha, monto estimado.
3. Admin revisa, crea cotización linkeada, pide adelanto (30% default).
4. Cliente paga adelanto (efectivo, transferencia) → `POST /api/v1/reservations/{id}/payments`.
5. Reserva pasa por estados: inquiry → confirmed → in_progress → ready → delivered.
6. Al entregar: `POST /api/v1/reservations/{id}/convert-to-order` crea Order definitiva.

### 8.4 Gastos con OCR

1. Admin sube foto/PDF → `POST /api/v1/expenses` con `multipart/form-data`.
2. Backend guarda archivo en storage del tenant.
3. Job `ProcessExpenseOcr` dispatch a queue Redis.
4. Worker procesa con Tesseract → extrae vendor, monto, fecha.
5. Update `expense.ocr_data` con resultado, `is_verified = false`.
6. UI lista gastos con badge "Por verificar" → admin abre slideover → ajusta → marca verificado.

### 8.5 Cotizaciones PDF

1. Admin crea cotización en `/admin/quotations/create`.
2. Agrega items, ajusta precios → `PATCH /api/v1/quotations/{id}` autosave.
3. Preview en vivo (HTML render con CSS print stylesheet).
4. Click "Generar PDF" → `POST /api/v1/quotations/{id}/generate-pdf` → backend usa Browsershot/DomPDF → guarda en storage → devuelve URL.
5. Click "Enviar por email" → `POST /api/v1/quotations/{id}/send` → Mailable con PDF attached.

### 8.6 Onboarding nuevo tenant

1. Usuario entra a `eternova.app/signup` (MarketingLayout).
2. `POST /api/v1/auth/register` crea User global.
3. Redirect a `/onboarding/plan` → elige plan.
4. `/onboarding/tenant` → form con nombre, slug, marca.
5. Slug se valida en vivo: `GET /api/v1/tenants/check-slug?slug={x}` (verifica formato + reserved_subdomains + disponibilidad).
6. Submit → `POST /api/v1/tenants` crea Tenant + Branch principal + Subscription en trial + `tenant_users` row con role owner.
7. Email de bienvenida con link al nuevo subdomain.
8. Redirect a `https://{slug}.eternova.app/admin/dashboard`.

---

## 9. Seguridad

### 9.1 Aislamiento multi-tenant

- **Global scope `BelongsToTenant`** filtra todas las queries automáticamente.
- **Policies** validan `$resource->tenant_id === current_tenant()->id` además del rol.
- **Tests obligatorios** en cada feature: "user de tenant A no puede ver/editar/borrar recursos de tenant B".

### 9.2 Sanctum + CSRF

- Cookies marcadas `HttpOnly`, `Secure` (prod), `SameSite=Lax`.
- CSRF token en cookie `XSRF-TOKEN`, Axios lo lee y lo envía en `X-XSRF-TOKEN` header automáticamente.
- Tokens (Bearer) tienen `abilities` (scopes). Cada endpoint puede requerir abilities específicas.

### 9.3 Rate limiting

- Rate limits por defecto Laravel: 60 req/min en endpoints auth.
- Endpoints públicos (storefront): 120 req/min por IP.
- Webhooks (Wompi): sin rate limit, validados por signature.

### 9.4 Validación de uploads

- Imágenes: max 5MB, formatos `jpg/png/webp`.
- PDFs (facturas, recibos): max 10MB.
- Validación de MIME real (no sólo extensión).
- Storage en disco S3 (prod) o local (dev) bajo `tenants/{tenant_id}/`.

### 9.5 Sanitización

- Form Requests validan todo input.
- Resources controlan qué campos se exponen al cliente (nunca exponemos `password`, `remember_token`, hashes internos).
- Logs no incluyen datos sensibles (passwords, tokens, payloads completos).

### 9.6 CORS

Como SPA y API están en el **mismo subdomain por tenant**, no necesitamos CORS para el caso principal. Sólo se configura para:
- Custom domains (CNAME) — el subdomain de Eternova sirve el SPA, el frontend hace requests al mismo dominio.
- API externa para integraciones B2B futuras: CORS abierto con `Authorization` header obligatorio.

---

## 10. Performance

### 10.1 Backend

- **Eager loading** de relaciones (`with([...])`) en todas las queries que se renderizan.
- **Índices** en `tenant_id` siempre primero en compuestos: `(tenant_id, status)`, `(tenant_id, created_at)`, etc.
- **Cache Redis** para:
  - Tenant config + branding (TTL 1h, invalidado por Observer)
  - Plans + features (TTL 1d)
  - Settings del tenant (TTL 1h)
  - Dashboard KPIs (TTL 5min)
- **Queue Redis** para jobs costosos: OCR, generación PDF, envío email, webhook processing.

### 10.2 Frontend

- **Code splitting** por route con Vue Router lazy loading.
- **Lazy loading** de componentes pesados (charts, editor de variants).
- **Lista virtualizada** para listados >100 items.
- **Imágenes optimizadas** con srcset (thumb / medium / full).
- **Pinia store persistence** sólo para carrito y prefs de UI (no datos del servidor).
- **Cache HTTP** con `etag` y `If-None-Match` en endpoints idempotentes.

---

## 11. Testing strategy

Eternova sigue la **doctrina dual-layer no negociable**: toda mutación del sistema requiere DOS capas de test. Sin excepciones. Esta regla evita que tests mockeados nos digan "OK" mientras producción se rompe.

### 11.1 Las dos capas

| Capa | Herramienta | Cuándo aplica |
|---|---|---|
| Feature / integration | PHPUnit con `RefreshDatabase` + factories | Lógica de dominio, autorización, scopes multi-tenant, validación de Form Requests, transformación de Resources, flows backend completos |
| End-to-end | Playwright contra browser real + DB real | Cualquier cambio que toque UI: forms, slideovers, modales, gates de autorización, flujos multi-paso, interacciones multi-tab/multi-rol |

**PHPUnit solo** (sin Playwright) cuando:
- Lógica de dominio pura (value objects, money calculations, validators puros)
- Repositories sin UI
- Comandos Artisan internos
- Jobs queued sin interfaz humana

**Playwright obligatorio** cuando:
- Endpoint con form
- Cambio en UI/slideover/modal
- Gates de autorización (validar que role X NO pueda hacer Y desde la UI)
- Flujos multi-paso (wizards, checkouts)
- Interacciones multi-tab (logueado en 2 tenants distintos)
- Cualquier cosa que vaya a producción tocando humanos

### 11.2 Estructura de carpetas

```
tests/
  Unit/                          # value objects, pure functions
    Catalog/
      MoneyTest.php
      SlugGeneratorTest.php
  Feature/                       # PHPUnit con RefreshDatabase
    Auth/
      LoginApiTest.php
      RegisterApiTest.php
      TokenApiTest.php
    Catalog/
      CategoryApiTest.php
      ProductApiTest.php
      ProductVariantApiTest.php
    Inventory/
      StockMovementTest.php
      LowStockAlertTest.php
    Tenancy/
      TenantIsolationTest.php    # OBLIGATORIO por módulo
    Billing/
      SubscriptionTest.php
      WebhookHandlingTest.php
  e2e/                           # Playwright TypeScript specs
    auth/
      login.spec.ts
      signup.spec.ts
      onboarding.spec.ts
    admin/
      catalog/
        categories.spec.ts
        products.spec.ts
      inventory/
        stock.spec.ts
    storefront/
      cart.spec.ts
      checkout-whatsapp.spec.ts
    super-admin/
      tenants-management.spec.ts
  Fixtures/                      # JSON fixtures, archivos de prueba (PDFs, imágenes)
    products-seed.json
    sample-invoice.pdf
```

### 11.3 Convenciones obligatorias

**Backend (PHPUnit):**
- `declare(strict_types=1);` en todo test
- Class `final` por defecto
- Usar `RefreshDatabase` trait — **nunca** mockear DB
- **Nunca** mockear boundaries HTTP en feature tests
- Factory-driven: `Product::factory()->forTenant($tenantA)->count(5)->create()`
- Naming descriptivo: `test_user_in_tenant_a_cannot_access_resources_of_tenant_b()` (no `testIndex`)
- Arrange / Act / Assert con líneas en blanco entre bloques

**Frontend (Playwright):**
- TypeScript strict mode
- Locators semánticos: `page.getByRole('button', { name: 'Crear producto' })` (no `page.locator('.btn-primary')`)
- Cada spec arranca con su tenant seedeado vía API call de setup
- Cleanup automático con `test.afterEach`
- Screenshots automáticos en fallos (configurado en `playwright.config.ts`)
- Tres viewports obligatorios para flujos críticos: `mobile` (375), `tablet` (768), `desktop` (1280)

### 11.4 Test de aislamiento multi-tenant (regla dura)

**Cada módulo debe tener al menos un test** que verifique que un usuario del tenant A **no puede** acceder/modificar/borrar recursos del tenant B vía API. Si no está ese test, el módulo no está "done".

Patrón de referencia:

```php
final class ProductTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_of_tenant_a_receives_404_when_accessing_product_of_tenant_b(): void
    {
        $tenantA  = Tenant::factory()->create();
        $tenantB  = Tenant::factory()->create();
        $userA    = User::factory()->forTenant($tenantA, role: 'owner')->create();
        $productB = Product::factory()->forTenant($tenantB)->create();

        $this->actingAs($userA)
            ->withHeader('X-Tenant-Id', $tenantA->id)
            ->getJson("/api/v1/products/{$productB->id}")
            ->assertStatus(404);
    }
}
```

### 11.5 Cobertura objetivo

**No perseguir 100%.** Tener cobertura significa que el código se ejecuta, no que está bien.

**Must-have (no negociable):**
- 100% de endpoints REST con happy path + 1 error path mínimo
- 100% de Policies con assert role correcto y assert role incorrecto
- 100% de reglas de negocio en Services
- 100% de migraciones reversibles (`migrate:fresh` y `migrate:rollback` deben funcionar)
- Cada Event/Listener crítico (StockLowDetected, SubscriptionCanceled, etc.)
- 1 test de aislamiento multi-tenant por módulo

**Nice-to-have:**
- Value objects edge cases
- Validators con todos los formatos por país
- Composables frontend (con Vitest si se justifica)

### 11.6 Tests específicos del modelo REST

- **Response envelope:** toda response exitosa tiene `data` + `meta`. Toda response paginada tiene `data` + `links` + `meta`.
- **Error format:** validation errors devuelven 422 con `errors` map. Authorization errors devuelven 403 con `error_code`. Plan gates devuelven 402 con `feature`, `current_plan`, `required_plan`.
- **Headers:** `Accept: application/json` siempre, `X-Request-Id` presente en todas las responses.
- **Pagination:** test que `?page=2&per_page=20` devuelve los siguientes 20 items. Test que `?cursor=...` funciona en endpoints con cursor.
- **Filtros y búsqueda:** test que `?search=rosa` filtra correctamente. Test que `?status[]=pending&status[]=preparing` aplica OR.

### 11.7 Sanctum auth tests obligatorios

- Login con email/password → cookie sesión + CSRF cookie set
- `GET /api/v1/me` con cookie válida → 200 con UserResource
- `GET /api/v1/me` sin auth → 401
- Token endpoint devuelve Bearer válido, que funciona en `Authorization` header
- Token revocado vía `logout` ya no funciona
- CSRF mismatch devuelve 419

### 11.8 CI integration

GitHub Actions workflow:

| Trigger | Qué corre | Bloquea merge |
|---|---|---|
| PR contra develop | `pint --test`, `npm run lint`, `vue-tsc --noEmit`, `php artisan test` | Sí |
| PR contra develop | `npm run build` (asegura que el bundle compila) | Sí |
| Push a develop | Lo anterior + Playwright completo + `scribe:generate` | Sí (revert automático si falla) |
| Push a main | Idem develop + smoke tests post-deploy | Sí |
| Manual `workflow_dispatch` | Suite completa de regresión visual | No (informativo) |

Playwright en cada PR es caro (~5-15 min). Por eso corre sólo en develop y main, no en cada PR. La doctrina dual-layer se mantiene: el dev corre Playwright localmente antes del PR y el `qa-engineer` agent valida visualmente antes del merge.

### 11.9 Comandos frecuentes

```bash
# Suite completa PHPUnit
./vendor/bin/sail artisan test

# Solo un módulo
./vendor/bin/sail artisan test --filter=Catalog

# Con coverage HTML
./vendor/bin/sail artisan test --coverage-html=coverage

# Playwright headless (CI mode)
./vendor/bin/sail npm run test:e2e

# Playwright con browser visible (debug local)
./vendor/bin/sail npm run test:e2e:headed

# Playwright UI interactivo
./vendor/bin/sail npm run test:e2e:ui

# Lint backend
./vendor/bin/sail composer pint

# Lint frontend
./vendor/bin/sail npm run lint

# Type check frontend
./vendor/bin/sail npm run type-check
```

### 11.10 Skill de referencia

Ver `.claude/skills/saas-testing-dual-layer/SKILL.md` para:
- Boilerplate completo de feature tests multi-tenant
- Patrones Playwright para slideovers, multi-tab, multi-rol
- Setup de fixtures y factories
- Trucos para tests de webhooks (Wompi)
- Testing de queued jobs con `Queue::fake()` selectivo

---

## 12. ADRs pendientes (decisiones diferidas)

| # | Tema | Cuándo decidir | Opciones |
|---|---|---|---|
| ADR-001 | SEO del storefront público | Sprint 8 | A) Vite SSR per-page, B) Pre-rendering al build (storefront-prerender), C) Aceptar SEO limitado y traer tráfico vía redes/SEM/WhatsApp |
| ADR-002 | Backup automático tenant | Sprint 9 | A) Artisan command + cron, B) Servicio externo (DB snapshots de cloud provider), C) Híbrido |
| ADR-003 | Estrategia de cache Redis multi-tenant | Sprint 9 | A) Prefix por tenant en mismo Redis, B) Database Redis distinta por tier de plan, C) Redis Cluster con sharding |
| ADR-004 | Generación de tipos TS desde OpenAPI | Sprint 2 | A) Manual sync, B) `openapi-typescript` generador automático en build, C) Híbrido (manual + script de verificación) |
| ADR-005 | Estrategia de billing si Wompi tarda | Sprint 4 | A) Facturación manual hasta Sprint 9, B) Integrar Stripe como secundario, C) Pausar features de plan y ofrecer trial extendido |

---

## 13. Diagrama de bloques

```
                        ┌────────────────────────────┐
                        │   Usuarios finales         │
                        │ (owners, staff, clientes)  │
                        └─────────────┬──────────────┘
                                      │
                  HTTPS (wildcard SSL: *.eternova.app)
                                      │
                        ┌─────────────▼──────────────┐
                        │       Nginx / Caddy        │
                        │   Reverse proxy + SSL      │
                        └─────────────┬──────────────┘
                                      │
                ┌─────────────────────┼─────────────────────┐
                │                                           │
        ┌───────▼────────┐                          ┌───────▼────────┐
        │  Static Assets │                          │   PHP-FPM      │
        │  (Vue SPA bundle│                         │  Laravel 12    │
        │   served from  │                          │                │
        │   /build)      │                          │   ┌──────────┐ │
        └───────┬────────┘                          │   │ Modules  │ │
                │                                   │   │ - Tenancy│ │
                │                                   │   │ - Billing│ │
                │  GET /                            │   │ - Auth   │ │
                │  GET /admin/*                     │   │ - Catalog│ │
                │  GET /products/*                  │   │ - POS    │ │
                │                                   │   │ - Orders │ │
                │                                   │   │ - ...    │ │
                │                                   │   └──────────┘ │
                │  app.js (SPA)                     └───────┬────────┘
                ▼                                           │
        Browser hydrates app                                │
        Vue Router maneja rutas                             │ XHR
        Axios → fetch                                       │
                │                                           │
                │  /api/v1/* (JSON)                         │
                └───────────────────────────────────────────┘
                                      │
                ┌─────────────────────┼─────────────────────┐
                │                     │                     │
        ┌───────▼────────┐    ┌──────▼──────┐       ┌──────▼──────┐
        │   MySQL 8      │    │   Redis     │       │   Workers   │
        │  multi-tenant  │    │  cache+queue│       │ (Queue jobs)│
        │  tenant_id     │    │  + session  │       │  - OCR      │
        └────────────────┘    └─────────────┘       │  - PDF      │
                                                    │  - Email    │
                                                    │  - Webhook  │
                                                    └─────────────┘

                Backend external integrations:
                - Wompi (billing SaaS)
                - Tesseract OCR (gastos)
                - Mail provider (SES / Mailgun)
                - WhatsApp wa.me links (client-side)
                - S3 (storage prod)
                - Let's Encrypt DNS-01 (SSL wildcard)
```

---

## 14. Referencias

- `docs/engineering/engineering-process.md` — sprints, ceremonias, DoR/DoD
- `docs/roadmap.md` — vista de producto del roadmap
- `docs/agents.md` — agentes Claude Code y skills disponibles
- `.claude/skills/` — patrones cristalizados de Laravel SaaS
- `~/.claude/skills/saas-tenant-subdomain-strategy/SKILL.md` — DNS + SSL + wildcard
- `https://laravel.com/docs/12.x` — Laravel docs
- `https://vue3-docs.dev` — Vue 3 docs
- `https://scribe.knuckles.wtf` — Scribe API docs generator
