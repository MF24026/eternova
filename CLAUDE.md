# Eternova - SaaS Multi-Tenant de Gestion de Negocio

> **Nota:** El proyecto inicio como "Carol Creaciones" (software para un solo negocio). El 2026-05-15 evoluciono a **SaaS multi-tenant** que vende el mismo software a multiples negocios similares (florerias, boutiques de regalos, peluches, accesorios). El nombre "Eternova" es placeholder mientras se define la marca definitiva.

## Proyecto
Plataforma SaaS multi-tenant para gestion integral de negocios de arreglos florales, accesorios y regalos. Cada tenant tiene su propio: catalogo digital publico (checkout via WhatsApp), POS interno, inventario, pedidos, reservas, gastos con OCR, cotizaciones PDF y dashboard.

El SaaS se monetiza con suscripciones por planes (Free/Pro/Enterprise) cobradas via Wompi.

## Stack Tecnologico
- **Backend:** Laravel 12.x (PHP 8.4)
- **Frontend:** Vue.js 3.x + Inertia.js
- **CSS:** Tailwind CSS 4.x con design system Ethereal Boutique
- **Base de datos:** MySQL 8.0 (single DB con tenant_id en todas las tablas de negocio)
- **Cache/Queue:** Redis
- **Contenedores:** Docker via Laravel Sail
- **Iconos:** Lucide Icons (consistente, limpio, sin emojis)
- **Fuentes:** Noto Serif (headlines) + Plus Jakarta Sans (body)
- **PDF:** DomPDF / Browsershot para cotizaciones
- **OCR:** Tesseract OCR via API para facturas de gastos
- **Pasarela de pago (SaaS billing):** Wompi (SV/CO) para suscripciones
- **Pasarela de pago (ventas internas de tenant):** configurable por tenant en Settings

## Arquitectura
- Monolito modular con Laravel + Inertia.js + Vue 3
- **Multi-tenancy: single DB con `tenant_id`** en todas las tablas de modulos de negocio
- Middleware `EnsureTenant` resuelve el tenant actual desde subdominio o usuario autenticado
- Global scope `BelongsToTenant` filtra automaticamente queries por tenant
- SPA-like con SSR opcional
- API interna via Inertia (no REST separado para el frontend)
- Modulos organizados por dominio en `app/Modules/`
- Ver `docs/architecture.md` para detalles completos

## Estructura de Directorios
```
app/
  Modules/
    # ═══ Modulos de plataforma SaaS (sin tenant_id) ═══
    Tenancy/       # Tenants, middleware, BelongsToTenant trait, current tenant
    Plans/         # Planes de suscripcion, features, limites
    Billing/       # Suscripciones, invoices, Wompi integration, webhooks
    SuperAdmin/    # Panel de administracion del SaaS (gestion de tenants)
    Onboarding/    # Signup, eleccion de plan, setup inicial de tenant
    MarketingSite/ # Landing publica del SaaS (pricing, features)

    # ═══ Modulos de negocio del tenant (con tenant_id) ═══
    Auth/          # Autenticacion y roles dentro del tenant
    Dashboard/     # Panel administrativo y KPIs del tenant
    Products/      # Productos, categorias, imagenes
    Inventory/     # Stock, entradas, salidas, alertas
    Catalog/       # Catalogo publico, carrito, checkout WhatsApp
    POS/           # Punto de venta interno
    Orders/        # Pedidos, seguimiento, despachos
    Reservations/  # Reservas personalizadas, adelantos
    Expenses/      # Gastos, OCR, categorias de gasto
    Quotations/    # Cotizaciones, generacion PDF
    Customers/     # Clientes y datos de contacto
    Settings/      # Configuracion del tenant (marca, contacto, locale, etc.)
resources/
  js/
    Components/    # Componentes Vue reutilizables
    Layouts/       # AdminLayout, StorefrontLayout, MarketingLayout, OnboardingLayout
    Pages/         # Paginas por modulo (Admin/, Storefront/, SuperAdmin/, Marketing/, Onboarding/)
    Composables/   # Logica reactiva compartida
  css/
    app.css        # Tailwind + design tokens Ethereal Boutique
```

## Multi-tenancy
- **Estrategia:** single database, `tenant_id` en todas las tablas de modulos de negocio
- **Resolucion de tenant:** subdominio (`tenant.eternova.app`) o desde el usuario autenticado
- **Scope global:** todos los modelos de negocio extienden `BelongsToTenant` trait que aplica scope automatico
- **Modulos SaaS (sin tenant_id):** Tenancy, Plans, Billing, SuperAdmin, Onboarding, MarketingSite
- **Super-admin:** ruta protegida `/super-admin/*` para gestionar tenants, planes, suscripciones, soporte
- **Onboarding flow:** signup → elegir plan → setup tenant (marca, locale, datos iniciales) → dashboard

## Pasarela de pago (Wompi)
- Solo se usa para **suscripciones del SaaS** (no para las ventas internas de cada tenant)
- Integracion via Wompi API + webhooks
- Modulo `Billing` maneja: Subscription, Invoice, Payment, WebhookHandler
- Las ventas internas de cada tenant pueden tener su propia pasarela configurable en Settings (futuro)

## Convenciones de Codigo

### Backend (Laravel)
- **Repository Pattern:** Toda la logica de acceso a datos va en Repositories
  - Interface en `app/Modules/{Module}/Repositories/{Model}RepositoryInterface.php`
  - Implementacion en `app/Modules/{Module}/Repositories/Eloquent{Model}Repository.php`
  - Binding en el ServiceProvider del modulo
- Controladores delgados -> llaman Services -> Services llaman Repositories
- Services contienen logica de negocio, Repositories solo acceso a datos
- Form Requests para validacion
- API Resources para transformar respuestas
- Policies para autorizacion (debe verificar tambien `tenant_id` cuando aplique)
- Observers para efectos secundarios del modelo
- Migrations con rollback funcional
- Seeders con datos realistas para desarrollo (incluir al menos 2 tenants demo)
- **Modelos de negocio:** deben usar trait `BelongsToTenant` que agrega `tenant_id` + global scope
- **PSR Compliance:**
  - PSR-1: Basic Coding Standard
  - PSR-4: Autoloading (ya por defecto en Laravel)
  - PSR-12: Extended Coding Style (enforced via PHP-CS-Fixer)
  - PSR-7/PSR-18: HTTP interfaces donde aplique

### Frontend (Vue 3)
- Composition API exclusivamente (no Options API)
- `<script setup>` en todos los componentes
- Props tipados con defineProps
- Componentes en PascalCase
- Slideovers con soporte swipe-to-close via touch events
- Dark mode con clase CSS strategy (class-based)
- NO usar emojis en ninguna parte de la UI
- Lucide Icons para toda iconografia

### CSS / Design System "Ethereal Boutique"
- Design tokens en `resources/css/app.css` (CSS variables + Tailwind theme integration)
- Referencia visual: `storage/app/design-reference/carol-creaciones/` (prototipo de Claude Design)
- Paleta pastel: surface #fff8f7, primary #7c545d, secondary #5a4b71
- **No-Line Rule:** sin bordes de 1px, separar con cambios de fondo (`tier`, `tier-mid`, `tier-high`)
- Esquinas redondeadas: `--r-lg` (1rem) o `--r-xl` (1.5rem) minimo, `--r-full` para pills
- Sombras: solo `--shadow-ambient`, `--shadow-rest`, `--shadow-lifted` (difusas, nunca drop-shadow duro)
- Texto: nunca negro puro - usar `var(--on-surface)` (#3d2f32)
- Gradientes signature: `--gradient` (primary→primary-container a 135deg), `--gradient-bloom` (160deg pastel)
- Glassmorphism (.glass) para navegacion flotante
- Componentes CSS base: `.btn`, `.btn-primary`, `.btn-tertiary`, `.btn-secondary`, `.btn-icon`, `.card`, `.field`, `.bloom`, `.serif`, `.label-gilt`, `.slideover`, `.tabs`

### Git
- **Repositorio:** https://github.com/MF24026/eternova.git
- **Trabajo en equipo:** dos devs minimo (Carolina + Erick). Git es el unico canal de sincronizacion.
- **Pull diario (no negociable):** al iniciar la jornada, ANTES de tocar codigo: `git fetch --all && git checkout develop && git pull origin develop`
- Commits en ingles, formato convencional: feat|fix|refactor|docs(scope): message
- **PROHIBIDO** incluir Co-Authored-By de Claude o cualquier IA en los commits
- **Branching strategy:** Git Flow simplificado
  - `main` - produccion, solo merge via PR
  - `develop` - rama de integracion, base para features
  - `feature/{module-name}` - nuevas funcionalidades (ej: feature/tenancy, feature/billing-wompi)
  - `fix/{issue-description}` - correccion de bugs
  - `hotfix/{description}` - fixes urgentes directo a main
- Crear rama desde `develop` antes de trabajar en cualquier feature
- No hacer push sin confirmar con el usuario
- No hacer merge a main sin PR revisado

### Docker
- Todo se ejecuta via Laravel Sail (requiere sudo en este entorno, password: developer)
- `./vendor/bin/sail up -d` para levantar
- `./vendor/bin/sail artisan` para comandos Artisan
- `./vendor/bin/sail npm` para comandos npm
- Puertos remapeados: APP en 8080, MySQL en 3307 (host tiene Apache + MySQL ocupando 80/3306)

## Comandos Frecuentes
```bash
# Levantar entorno
./vendor/bin/sail up -d

# Migraciones
./vendor/bin/sail artisan migrate

# Seeders
./vendor/bin/sail artisan db:seed

# Frontend dev
./vendor/bin/sail npm run dev

# Tests PHPUnit / Pest
./vendor/bin/sail artisan test

# Tests Playwright E2E
./vendor/bin/sail npm run test:e2e
./vendor/bin/sail npm run test:e2e:ui       # modo UI interactivo
./vendor/bin/sail npm run test:e2e:headed   # ver el browser corriendo

# Linting
./vendor/bin/sail npm run lint
```

## Testing (regla dual-layer, no negociable)
- **Doctrina:** toda mutacion del sistema requiere DOS capas de test:
  1. **PHPUnit feature test** con factory + `RefreshDatabase` para logica de dominio, autorizacion, scopes multi-tenant
  2. **Playwright E2E** corriendo contra navegador real + DB real para flujos con UI (forms, modales, slideovers, wizards, multi-step, multi-rol)
- **Sin excepciones** para "fixes chicos" — un cambio de 1 linea de config puede romper la UI; corre ambas capas
- **NO mockear** la base de datos ni los boundaries HTTP en feature tests — usa factories + RefreshDatabase
- **PHPUnit solo** cuando: logica de dominio pura, value objects, repositories sin UI, comandos artisan internos
- **Playwright obligatorio** cuando: endpoint con form, cambio en UI/slideover/modal, gates de autorizacion, flujos multi-paso, interacciones multi-tab, flujos cross-rol, cualquier cosa que salga a produccion
- Ver `.claude/skills/saas-testing-dual-layer/SKILL.md` para boilerplate completo de tests multi-tenant y patrones Playwright
- Test suite por modulo: cuando se completa un modulo, debe entregarse con su carpeta `tests/Feature/{Modulo}/` + `tests/e2e/{modulo}.spec.ts` antes de marcarlo "done"
- **QA visual obligatoria antes de cerrar PR:** invocar agente `qa-engineer` para validar visualmente con Playwright MCP (screenshots desktop + mobile + dark mode, regresiones en vistas relacionadas, flujos end-to-end manuales). Ver `.claude/agents/qa-engineer.md`

## Skills disponibles (`.claude/skills/`)
Reglas y patrones cristalizados de experiencia real con SaaS Laravel. Consulta el skill correspondiente ANTES de implementar features grandes:
- `saas-testing-dual-layer` — PHPUnit + Playwright doctrine
- `laravel-saas-multi-tenant-foundation` — patron tenant_id, scopes, middleware
- `laravel-saas-billing-infrastructure` — suscripciones, invoices, Stripe/Wompi, dunning (1193 lineas, muy completo)
- `saas-plan-gating-billing` — feature flags por plan, limites, upgrade prompts
- `laravel-saas-settings-architecture` — settings configurables por tenant
- `laravel-saas-auth-granularity` — roles, permisos, policies multi-tenant
- `laravel-saas-architecture-decisions` — decisiones de stack y trade-offs
- `laravel-saas-i18n-latam` — moneda, telefonos, fechas, RUT/NIT/RFC por pais
- `laravel-saas-email-transactional` — bienvenida, facturas, alertas
- `saas-thermal-printing-pipeline` — impresoras termicas POS
- `laravel-design-patterns-toolkit` — Repository, Service, Strategy, Observer
- `laravel-debugging-toolkit` — diagnostico de errores comunes
- `vue-inertia-frontend-system` — patrones Vue 3 + Inertia + Pinia
- `senior-dev-code-style` — convenciones de codigo, naming, organizacion

## Reglas Importantes
- **Multi-tenant:** todo modelo de negocio debe tener `tenant_id` y aplicar `BelongsToTenant` trait
- **Marca SaaS configurable:** el nombre/logo de Eternova debe estar en config, no hardcoded - solo aparece en landing/login/super-admin/emails
- **Marca de tenant configurable:** cada tenant configura su propia marca (logo, nombre, colores, moneda, pais, telefono) desde su Settings - el sistema lo respeta en su storefront publico y panel admin
- Responsive web design en todas las vistas (mobile-first)
- Slideovers en lugar de modales donde sea posible, con swipe-to-close
- Dark mode en toda la aplicacion
- Sin emojis en la interfaz - usar Lucide Icons
- Validacion de telefono dinamica segun pais configurado del tenant
- Idioma de la UI: Espanol (futuro multi-idioma)
- Idioma del codigo: Ingles
- **Marca SaaS placeholder:** "Eternova" en el codigo. Cambiar centralmente en `config/saas.php` cuando se decida el nombre definitivo
