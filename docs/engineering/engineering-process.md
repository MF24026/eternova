# Eternova — Proceso de ingeniería y plan de sprints

> **Documento maestro.** Toda persona o agente que trabaje en el proyecto debe leer este archivo antes de tocar código. Reemplaza al antiguo `docs/agents.md` y `docs/roadmap.md` heredados de la fase "Carol Creaciones".
>
> Última actualización: 2026-06-04. Mantener vivo: si una decisión cambia, se actualiza acá ANTES de mergear el cambio.

---

## 0. Resumen ejecutivo

**Eternova** es una plataforma SaaS multi-tenant para negocios físicos pequeños de Latinoamérica (florerías, regalerías, boutiques, peluches). Cada tenant tiene catálogo público con checkout WhatsApp, POS interno, inventario por sucursal, pedidos, reservas, gastos con OCR, cotizaciones PDF y dashboard. La plataforma se monetiza vía suscripciones por planes (Básico / Pro / Enterprise) cobradas con Wompi.

**Stack:** Laravel 12 (API REST `/api/v1/*`) + Vue 3 SPA con Vue Router + Pinia + Axios + TypeScript + Tailwind 4 + MySQL 8 + Redis. Backend y frontend completamente desacoplados desde el día 1.

**Equipo:** dos desarrolladores (Erick + Carolina) en máquinas distintas, cada uno con Claude Code y acceso a los 6 agentes especializados configurados en `.claude/agents/`. Git es el único canal de sincronización.

**Metodología:** Scrumban Lite — sprints de 2 semanas, 1 ceremonia (planning + review combinados), daily async vía issues/PRs de GitHub. Sin standup en vivo.

---

## 1. Equipo y entornos

### 1.1 Personas

| Persona | GitHub | Máquina | Rol primario |
|---|---|---|---|
| Erick | `HA23039` | Linux (este equipo) — Docker sin sudo, puertos 8080/3308/6381/5174 | Backend + arquitectura + DevOps |
| Carolina | (TBC) | (TBC) | Frontend + UX + producto |

Ambos usan **Claude Code** con los mismos agentes configurados en `.claude/agents/` y los mismos skills locales en `.claude/skills/`.

### 1.2 Entornos

| Entorno | Dominio | Base de datos | Propósito |
|---|---|---|---|
| Local dev | `*.eternova.localhost:8080` | `eternova` (MySQL container) | Desarrollo individual de cada dev |
| Staging | `*.staging.eternova.app` | `eternova_staging` | QA visual + tests E2E antes de prod |
| Producción | `*.eternova.app` + custom domains | `eternova_prod` | Tenants reales |

Los puertos locales están remapeados para no chocar con el otro proyecto del equipo (`restaurant-inventory`) que también corre Sail en la misma máquina:

```
APP        → 8080  (no 80)
MySQL      → 3308  (no 3306)
Redis      → 6381  (no 6379)
Vite HMR   → 5174  (no 5173)
```

### 1.3 Repositorio

`https://github.com/MF24026/eternova.git` — Erick y Carolina son colaboradores, no owners. Por tanto: nada de force-push, todo cambio entra por Pull Request.

---

## 2. Metodología — Scrumban Lite

### 2.1 Ritmo

- **Sprints de 2 semanas.** Lunes a viernes de la segunda semana es el día de cierre.
- **1 sola ceremonia por sprint:** planning + review combinados (60 min máx) el primer lunes del sprint.
- **No hay daily standup en vivo.** El daily es async: cada dev abre o actualiza un comentario en el issue de GitHub asignado.
- **Retro mensual** (cada 2 sprints) en formato escrito (Notion o issue).

### 2.2 Definitions

- **Definition of Ready (DoR):** un ticket está listo para entrar a sprint cuando tiene aceptado: descripción + criterios de aceptación + scope técnico breve + agente sugerido. Sin esto, vuelve a backlog.
- **Definition of Done (DoD):** ver §6. No se mergea sin cumplir DoD completo.

### 2.3 Tracking

- **Issues de GitHub** son la fuente de verdad. Cada ticket es un issue con labels: `module:catalog`, `priority:p1`, `sprint:s0`, `agent:backend-developer`, etc.
- **Notion (opcional, futuro)** sólo para PRD, casos de uso y diagramas UML/Mermaid de alto nivel. No para tickets.
- **Project board** en GitHub Projects, columnas: `Backlog → Ready → In Progress → In Review → Done`.

### 2.4 Cadencia de commits y pulls

- **Pull diario no negociable.** Al iniciar la jornada, antes de tocar código:
  ```bash
  git fetch --all
  git checkout develop
  git pull origin develop
  ```
- **Push al menos 1 vez al día** aunque la feature no esté terminada (rama de feature, no de develop). Evita perder código y permite que el otro dev vea progreso.
- **PRs chicos.** Un PR debe ser revisable en <30 min. Si crece más, partirlo en 2.

---

## 3. Decisiones P0/P1 cerradas (referencia rápida)

Estas decisiones están cerradas y NO se renegocian sin un brainstorming nuevo:

| # | Decisión | Resumen |
|---|---|---|
| 1 | Multi-tenancy | Single database con `tenant_id` en todas las tablas de negocio. Trait `BelongsToTenant` aplica scope global automático. |
| 2 | Resolución de tenant | Wildcard subdomain principal (`*.eternova.app`), custom domain como upgrade (CNAME + verificación), path-based como fallback (`eternova.app/t/{slug}`). |
| 3 | Multi-sucursal | `branch_id` baked-in desde día 1 en todas las tablas relevantes (orders, inventory_movements, expenses). No es retrofit. |
| 4 | Stack | **Backend:** Laravel 12 (PHP 8.4) + MySQL 8 + Redis + Sanctum + Scribe. **Frontend:** Vue 3 SPA (Composition API, `<script setup>`, TypeScript strict) + Vue Router 4 + Pinia + Axios + Tailwind 4 + Lucide Icons + VueUse. |
| 5 | Transporte frontend | **REST API versionada `/api/v1/*` desde día 1** (decisión actualizada 2026-06-04). Inertia.js descartado. La API es el único contrato backend-frontend. La misma API sirve futuras integraciones móvil/B2B. |
| 6 | Auth | **Sanctum dual mode:** cookies SPA (frontend web same-origin) + personal access tokens Bearer (móvil/externos). Roles dentro del tenant: `owner`, `admin`, `staff`, `customer`. Rol global: `super_admin`. |
| 7 | Catálogo | Schema tipo Shopify: `products` + `product_variants` + `product_options` + `categories` (M2M) + `tags`. NO modelo WooCommerce. |
| 8 | Planes SaaS | Tres tiers: Básico / Pro / Enterprise. Feature gating tipo "ver pero bloqueado + CTA upgrade" (no esconder features). |
| 9 | Trial | 30 días sin tarjeta de crédito + cooldown de 90 días post-cancelación para evitar tenant takeover. |
| 10 | Billing SaaS | Wompi (SV + CO) como pasarela. Modelo: Subscription → Invoice → Payment + Webhook handler. |
| 11 | Pasarela interna de tenant | Configurable por tenant en su Settings (futuro). Wompi se usa **solo** para suscripciones del SaaS, no para ventas internas del tenant. |
| 12 | Metodología | Scrumban Lite (ver §2). |

---

## 4. Agentes disponibles

Los agentes de Claude Code están definidos en `.claude/agents/*.md`. Cuando se trabaja en una tarea, **invocar el agente correcto** vía el Task tool de Claude Code (o `Agent({subagent_type: "..."})`).

### 4.1 Catálogo de agentes

| Agente | Cuándo usar | Skill primario |
|---|---|---|
| `software-architect` | Decisiones de arquitectura, esquema DB, contratos de service/repository, configuración de auth, code review estructural | `laravel-saas-architecture-decisions` |
| `backend-developer` | Implementar Controllers, Services, Repositories, Form Requests, Resources, Observers, Jobs, Events, tests PHPUnit | `laravel-design-patterns-toolkit` + `senior-dev-code-style` |
| `frontend-developer` | Componentes Vue, páginas Inertia, Pinia stores, integración con backend, layouts, forms | `vue-inertia-frontend-system` |
| `ui-ux-design-system` | Tokens de design system, layouts, animaciones, dark mode, responsive, accesibilidad | `vue-inertia-frontend-system` (sección design) |
| `devops-integration-engineer` | Docker, Sail, Vite, CI/CD, integraciones externas (OCR, PDF, mail), storage, queues, deploy | (consume `laravel-saas-email-transactional`, `saas-thermal-printing-pipeline`) |
| `qa-engineer` | Validación visual y E2E **antes de cerrar PR**, screenshots desktop+mobile+dark mode, flujos completos con Playwright MCP | `saas-testing-dual-layer` |

### 4.2 Skills disponibles

Los skills están en `.claude/skills/` (proyecto) y `~/.claude/skills/` (globales para todos los proyectos). **Los agentes deben consultar el skill correspondiente ANTES de implementar features grandes.**

**Skills de proyecto (en `.claude/skills/`):**

| Skill | Cuándo invocarlo |
|---|---|
| `laravel-saas-multi-tenant-foundation` | Cualquier tabla nueva → confirmar que aplica `tenant_id` + `BelongsToTenant`. Middleware de resolución de tenant. |
| `laravel-saas-billing-infrastructure` | Suscripciones, invoices, integración Wompi, webhooks, dunning. 1193 líneas, muy completo. |
| `saas-plan-gating-billing` | Antes de gate-ar una feature por plan. Define el patrón "ver bloqueado + CTA upgrade". |
| `laravel-saas-settings-architecture` | Tocar tabla `settings` o agregar nuevo grupo. Cache en Redis con invalidación. |
| `laravel-saas-auth-granularity` | Roles, permisos, policies multi-tenant. Cada policy debe validar `tenant_id`. |
| `laravel-saas-i18n-latam` | Moneda, teléfonos por país, fechas, RUT/NIT/RFC. Validación dinámica por país del tenant. |
| `laravel-saas-email-transactional` | Bienvenida, facturas, alertas, password reset, invitaciones. Setup de mailer. |
| `laravel-saas-architecture-decisions` | Antes de proponer un cambio estructural (módulos, contratos). Documenta trade-offs ya cerrados. |
| `laravel-design-patterns-toolkit` | Repository, Service, Strategy, Observer. Recetas concretas para Laravel 12. |
| `laravel-debugging-toolkit` | Errores comunes en Laravel/Sail: configs, caches, locks, migraciones rotas. |
| `senior-dev-code-style` | Convenciones de código, naming, organización, `declare(strict_types=1)`, `final readonly`. |
| `vue-inertia-frontend-system` | Patrones Vue 3 + Inertia 2 + Pinia. Slideovers swipe-to-close, dark mode class-based, layouts funcionales. |
| `saas-testing-dual-layer` | Doctrina obligatoria: PHPUnit feature test (con factory + `RefreshDatabase`) + Playwright E2E. Boilerplate multi-tenant. |
| `saas-thermal-printing-pipeline` | Impresoras térmicas para POS (recibos, comandas). Solo si el módulo POS lo requiere. |

**Skills globales adicionales (en `~/.claude/skills/`):**

| Skill | Cuándo invocarlo |
|---|---|
| `saas-tenant-subdomain-strategy` | DNS, SSL (Let's Encrypt DNS-01), wildcard routing, slug validation, reserved subdomains. Cubre 16 edge cases. |
| `laravel-vue-verification-recipes` | Recetas para verificar features con Playwright + curl + tinker antes de marcar done. |
| `explain-code` | Sólo cuando el usuario pide "explicame qué hace X". |

### 4.3 Mapeo agente → skills relevantes

Cuando invocas un agente, el agente **debe** invocar los skills relevantes. Si no lo hace, recuérdaselo en el prompt:

| Agente | Skills que típicamente invoca |
|---|---|
| `software-architect` | `laravel-saas-architecture-decisions`, `laravel-saas-multi-tenant-foundation`, `laravel-saas-auth-granularity` |
| `backend-developer` | `laravel-design-patterns-toolkit`, `senior-dev-code-style`, `saas-testing-dual-layer`, módulo-específico (billing, settings, i18n…) |
| `frontend-developer` | `vue-inertia-frontend-system`, `saas-plan-gating-billing` (cuando gate-a UI), `saas-testing-dual-layer` (Playwright) |
| `ui-ux-design-system` | `vue-inertia-frontend-system` |
| `devops-integration-engineer` | `laravel-saas-email-transactional`, `saas-tenant-subdomain-strategy`, `saas-thermal-printing-pipeline` |
| `qa-engineer` | `saas-testing-dual-layer`, `laravel-vue-verification-recipes` |

### 4.4 Cómo invocar un agente

Ejemplo desde Claude Code (chat):

> "Implementá el CRUD de categorías con el `backend-developer`. Antes de empezar, que consulte `laravel-design-patterns-toolkit` y `laravel-saas-multi-tenant-foundation`. Al terminar, invocá `qa-engineer` para validación visual."

Ejemplo desde código (sub-agentes anidados):
```
Agent({
  subagent_type: "backend-developer",
  prompt: "Implementá CRUD de categorías. Consultá laravel-design-patterns-toolkit y laravel-saas-multi-tenant-foundation antes de empezar."
})
```

---

## 5. Git Flow y paralelización

### 5.1 Ramas

Git Flow simplificado:

```
main      ← producción. Sólo recibe PRs desde develop (release) o hotfix/* (urgente)
  ↑
develop   ← integración. Base para toda feature/fix
  ↑
feature/{module-or-ticket}   ← trabajo en curso
fix/{issue-description}      ← bugs
hotfix/{description}         ← urgentes, único caso que va directo a main
```

**Reglas estrictas de PR (no negociables):**
- `feature/X` → PR contra **`develop`** SIEMPRE. Nunca contra main.
- `fix/X` → PR contra **`develop`**.
- `develop` → PR contra **`main`** sólo cuando develop esté estable y listo para release.
- `hotfix/X` → PR contra **`main`** + cherry-pick a develop después del merge.

**Comando estándar para crear PR desde una feature branch:**
```bash
gh pr create --base develop --fill
```

### 5.2 Boundaries por módulo (quién toca qué)

Para evitar que Erick y Carolina (o dos agentes paralelos) pisen el mismo archivo, **cada sprint se asigna un dueño por módulo**:

| Módulo | Carpeta backend | Carpeta frontend | Notas |
|---|---|---|---|
| Tenancy | `app/Modules/Tenancy/` | `resources/js/pages/SuperAdmin/`, `services/TenantsService.ts`, `stores/tenant.ts` | Sólo `software-architect` o `backend-developer` con review. |
| Billing | `app/Modules/Billing/` | `resources/js/pages/Admin/Billing/`, `services/BillingService.ts` | Cambios al esquema requieren ADR. |
| Plans | `app/Modules/Plans/` | `composables/usePermissions.ts` (gating) | Touch chico. |
| Products / Catalog | `app/Modules/Products/`, `app/Modules/Catalog/` | `resources/js/pages/Admin/Catalog/`, `pages/Storefront/`, `services/ProductsService.ts`, `stores/products.ts` | Sprint 1 owner: backend Erick, frontend Caro. |
| Inventory | `app/Modules/Inventory/` | `pages/Admin/Inventory/`, `services/InventoryService.ts` | Depende de `branch_id`. |
| POS | `app/Modules/POS/` | `pages/Admin/POS/`, `services/POSService.ts`, `stores/cart.ts` (variante POS) | Sprint 3+. |
| Orders | `app/Modules/Orders/` | `pages/Admin/Orders/`, `services/OrdersService.ts` | Sprint 4+. |
| Reservations | `app/Modules/Reservations/` | `pages/Admin/Reservations/`, `services/ReservationsService.ts` | Sprint 5+. |
| Expenses | `app/Modules/Expenses/` | `pages/Admin/Expenses/`, `services/ExpensesService.ts` | Sprint 6+. |
| Quotations | `app/Modules/Quotations/` | `pages/Admin/Quotations/`, `services/QuotationsService.ts` | Sprint 7+. |
| Settings | `app/Modules/Settings/` | `pages/Admin/Settings/`, `services/SettingsService.ts`, `stores/tenant.ts` (parte branding) | Touch transversal — coordinar antes. |
| Auth | `app/Modules/Auth/` | `pages/Auth/`, `services/AuthService.ts`, `stores/auth.ts` | Cambios sensibles, review obligatorio. |

**Archivos transversales (requieren coordinación previa):**
- `resources/js/components/layout/AdminLayout.vue`
- `resources/js/components/layout/StorefrontLayout.vue`
- `resources/js/components/base/*` (atoms del design system)
- `resources/js/router/index.ts` y `router/guards.ts`
- `resources/js/services/api.ts` (Axios instance + interceptors)
- `resources/css/app.css` (design tokens)
- `routes/web.php` (sirve el shell HTML del SPA)
- `routes/api/v1/*.php` (rutas REST por módulo)
- `config/saas.php`, `config/tenancy.php`, `config/sanctum.php`, `config/scribe.php`
- `composer.json`, `package.json`, `tsconfig.json`, `vite.config.ts`

Si vas a tocar cualquiera de estos, **avisar en GitHub issue antes de comenzar** y mergear rápido para no bloquear al otro dev.

### 5.3 Worktrees para paralelización agresiva

Cuando un agente trabaja en una rama feature en paralelo a otro, usar `git worktree` para no estar haciendo `git checkout` constantemente:

```bash
# Erick trabaja en feature/catalog
git worktree add ../eternova-catalog feature/catalog

# Caro trabaja en feature/inventory en su máquina (mismo flujo)
git worktree add ../eternova-inventory feature/inventory
```

Los agentes de Claude Code que soportan `isolation: "worktree"` lo hacen automáticamente cuando van a mutar archivos.

---

## 6. Definition of Ready / Done

### 6.1 Definition of Ready (DoR)

Un ticket está **Ready** para entrar al sprint cuando tiene:

- [ ] Descripción clara del problema o feature en términos de usuario (un párrafo).
- [ ] Criterios de aceptación (3-7 bullets concretos y verificables).
- [ ] Scope técnico breve (qué tablas/módulos toca, si hay migración, si hay UI nueva).
- [ ] Agente sugerido (`backend-developer`, `frontend-developer`, etc.) y skills relevantes anotados.
- [ ] Estimación rough (XS / S / M / L). Sin estimación numérica falsa.
- [ ] Sin bloqueos no resueltos (si depende de otro ticket, ese otro tiene que ir primero).

Si falta cualquiera de estos, vuelve a backlog hasta refinarlo.

### 6.2 Definition of Done (DoD) — obligatorio para mergear

Un PR está **Done** cuando:

- [ ] **Código:** PSR-12, `declare(strict_types=1);`, types en parámetros y returns. No `mixed` cuando se puede ser específico.
- [ ] **Sin emojis** en código, comentarios, commits, ni documentación.
- [ ] **Repository Pattern respetado:** Controller delgado → Service → Repository → Model. Controllers nunca acceden directo al Repository ni al Model.
- [ ] **Multi-tenancy:** si el modelo es de negocio, tiene `tenant_id` y `BelongsToTenant` trait. Policies validan tenant.
- [ ] **Migraciones:** rollback funcional verificado.
- [ ] **Seeders:** datos realistas. Si es un modelo nuevo, agregar al seeder de tenant demo.
- [ ] **Tests PHPUnit:** feature test con factory + `RefreshDatabase`. Sin mocks de DB.
- [ ] **Tests Playwright:** si el cambio toca UI, slideover, form, modal, gate de auth, multi-paso, multi-rol, o cualquier cosa que vaya a producción → spec en `tests/e2e/{modulo}.spec.ts`.
- [ ] **Lint:** `npm run lint` pasa. `vendor/bin/pint --test` pasa.
- [ ] **QA visual:** invocar `qa-engineer` agent para screenshots desktop + mobile + dark mode + regresiones en vistas relacionadas. Adjuntar capturas al PR.
- [ ] **Documentación:** si el cambio introduce un nuevo concepto/módulo, actualizar `docs/architecture.md` o `docs/engineering/engineering-process.md`.
- [ ] **PR description:** título en formato convencional (`feat(scope):`, `fix(scope):`, etc.), descripción con problema + solución + test plan.
- [ ] **Sin Co-Authored-By de Claude ni de ninguna IA.** Esto es regla dura del proyecto.
- [ ] **Review aprobada** por el otro dev (Erick o Caro). Self-merge sólo en hotfix verificados.

### 6.3 Convenciones de commits

Formato: `<type>(<scope>): <message>` — en inglés.

Types aceptados: `feat`, `fix`, `refactor`, `chore`, `docs`, `test`, `style`, `perf`, `ci`, `build`.

Ejemplos:
```
feat(catalog): add product variants and options schema
fix(billing): correct webhook signature verification for Wompi
refactor(inventory): extract stock calculation to dedicated service
docs(engineering): update sprint 1 deliverables
```

---

## 7. Sprint 0 — Foundation multi-tenant (detallado)

**Duración:** 3 semanas (extendido de 2 por setup REST + SPA). **Objetivo:** plataforma con API REST `/api/v1` + SPA Vue Router operativa, login funcional con Sanctum dual, resolución de tenant y schema base de multi-tenancy + plans + billing.

**Estado actual:** parcialmente avanzado. Tenant model, EnsureTenant middleware, BelongsToTenant trait existen. Layouts admin/storefront existen como `.vue`. Sin embargo, antes estaban montados con Inertia: requieren migración a Vue Router. Falta cerrar lo que se lista abajo.

### 7.1 Épicas

| # | Épica | Owner sugerido | Skills |
|---|---|---|---|
| S0-E1 | Infraestructura Docker + Vite multi-entry + ports remap | `devops-integration-engineer` | — |
| S0-E2 | Schema multi-tenant base | `software-architect` + `backend-developer` | `laravel-saas-multi-tenant-foundation` |
| S0-E3 | Resolución de tenant (subdomain + custom + path) | `backend-developer` | `saas-tenant-subdomain-strategy` |
| S0-E4 | API REST base: `/api/v1`, Scribe, Resource envelope, error format | `software-architect` + `backend-developer` | `laravel-saas-architecture-decisions`, `senior-dev-code-style` |
| S0-E5 | Schema de Plans + Subscriptions + Invoices | `backend-developer` | `laravel-saas-billing-infrastructure` |
| S0-E6 | Auth Sanctum dual (cookies SPA + tokens) + roles tenant | `backend-developer` | `laravel-saas-auth-granularity` |
| S0-E7 | Frontend bootstrap: Vue Router + Pinia + Axios + TypeScript strict | `frontend-developer` + `software-architect` | `vue-inertia-frontend-system` (adaptar a SPA puro) |
| S0-E8 | Layouts + design system base + componentes atoms | `ui-ux-design-system` + `frontend-developer` | `vue-inertia-frontend-system` |
| S0-E9 | Onboarding signup → plan → tenant (REST + SPA) | `backend-developer` + `frontend-developer` | `laravel-saas-billing-infrastructure` (sección onboarding) |
| S0-E10 | Seeders demo (mínimo 2 tenants) | `backend-developer` | — |
| S0-E11 | CI básico + linting + Scribe en build | `devops-integration-engineer` | — |

### 7.2 Tickets concretos

**S0-E1: Infraestructura**
- [x] Docker compose corriendo con MySQL 8, Redis, PHP 8.4 (DONE)
- [x] Puertos remapeados a 8080/3308/6381/5174 (DONE)
- [ ] `.env.example` actualizado con todas las variables nuevas (`TENANT_RESOLVER`, `WOMPI_*`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, etc.)
- [ ] Vite con multiple entry points (`admin.ts`, `storefront.ts`, `super-admin.ts`) o single entry con router
- [ ] HMR funcionando en `5174` con `@tailwindcss/oxide` resuelto correctamente
- [ ] `tsconfig.json` con strict mode para TypeScript en frontend

**S0-E2: Schema multi-tenant base**
- [x] Tabla `tenants` (id ULID, name, slug, email, status, brand_config json, locale_config json, trial_ends_at) (DONE)
- [x] Trait `BelongsToTenant` con scope global (DONE — revisar cobertura)
- [ ] Tabla `branches` (id, tenant_id, name, slug, address, phone, is_main, is_active)
- [ ] Migración para agregar `branch_id` a todas las tablas relevantes (orders, inventory_movements, expenses)
- [ ] Tabla `reserved_subdomains` con seeder de ~120 entradas (categorías: system, brand, trademark, profanity, regulated, security-sensitive)

**S0-E3: Resolución de tenant**
- [x] Middleware `EnsureTenant` resuelve por subdomain (DONE — básico)
- [ ] Soporte completo para `.localhost` dev (verificar 3+ host parts)
- [ ] Switch por `config('tenancy.resolver')`: `subdomain` | `path` | `domain`
- [ ] Validación slug RFC 1035 (3-63 chars, lowercase, alfanumérico + guiones, no empieza/termina con guion)
- [ ] Blacklist contra `reserved_subdomains`
- [ ] Tabla `tenant_domains` para custom domain con verificación TXT/CNAME
- [ ] Feature tests para los 3 modos de resolución

**S0-E4: API REST base**
- [ ] Estructura de rutas: `routes/api/v1/auth.php`, `tenants.php`, `catalog.php`, etc., todas montadas en `RouteServiceProvider` bajo prefix `/api/v1`
- [ ] Convenciones globales: response envelope (`data`, `meta`, `links`), error format (RFC 7807 simplificado), header `X-Request-Id`
- [ ] `composer require knuckleswtf/scribe --dev`, configuración en `config/scribe.php`, página `/docs` accesible en dev
- [ ] Resource base class con campo `meta.tenant_id` siempre incluido
- [ ] FormRequest base con manejo de errores `422` consistente
- [ ] `Exception\Handler::render()` overrides para JSON responses limpias

**S0-E5: Plans + Subscriptions + Invoices**
- [ ] Tabla `plans` (id, slug, name, description, price_monthly_cents, price_yearly_cents, currency, features json, limits json, is_active, sort_order)
- [ ] Tabla `subscriptions` (id, tenant_id, plan_id, status, trial_ends_at, current_period_start, current_period_end, cancel_at_period_end, canceled_at)
- [ ] Tabla `invoices` (id, tenant_id, subscription_id, number, status, subtotal_cents, tax_cents, total_cents, currency, due_at, paid_at, wompi_transaction_id, pdf_url)
- [ ] Tabla `payments` (id, invoice_id, amount_cents, currency, method, status, gateway, gateway_reference, paid_at)
- [ ] Tabla `webhook_log`
- [ ] Seeders de 3 planes Básico/Pro/Enterprise

**S0-E6: Auth Sanctum dual + roles**
- [ ] `composer require laravel/sanctum`, publish config
- [ ] `config/sanctum.php` con `stateful` para `*.eternova.localhost:8080` y `*.eternova.app`
- [ ] Endpoint `POST /api/v1/auth/login` (SPA cookies)
- [ ] Endpoint `POST /api/v1/auth/register`
- [ ] Endpoint `POST /api/v1/auth/logout`
- [ ] Endpoint `GET /sanctum/csrf-cookie`
- [ ] Endpoint `POST /api/v1/auth/token` (Bearer token para móvil/externos)
- [ ] Endpoint `GET /api/v1/me` con UserResource
- [ ] Roles dentro del tenant: `owner`, `admin`, `staff`, `customer`
- [ ] Tabla `tenant_users` pivot (tenant_id, user_id, role, joined_at)
- [ ] Migración: agregar `is_super_admin` a users (default false)
- [ ] Policy base abstracta que valida `$resource->tenant_id === current_tenant()->id` + role
- [ ] Tests de aislamiento multi-tenant: user de tenant A NO puede acceder a recursos de tenant B (via API ni via subdomain mismatch)

**S0-E7: Frontend bootstrap**
- [ ] `npm install vue@3 vue-router@4 pinia axios @vueuse/core lucide-vue-next`
- [ ] `npm install --save-dev typescript @vue/tsconfig vue-tsc`
- [ ] `tsconfig.json` strict mode
- [ ] `resources/js/main.ts` con `createApp` + Pinia + Vue Router + Axios
- [ ] `resources/js/services/api.ts` — Axios instance con `baseURL`, `withCredentials: true`, interceptor request (CSRF token, tenant context), interceptor response (parse errors, redirect 401 a login)
- [ ] `resources/js/router/index.ts` con lazy loading por route
- [ ] `resources/js/router/guards.ts`: auth guard, tenant guard, role guard, plan guard
- [ ] `resources/js/stores/auth.ts` (currentUser, login(), logout(), isAuthenticated)
- [ ] `resources/js/stores/tenant.ts` (current tenant data, branding, locale, plan)
- [ ] `resources/js/types/api.ts` (Paginated<T>, Resource<T>, ApiError)
- [ ] Shell HTML `resources/views/spa.blade.php` con `<div id="app">` y mount del bundle Vite
- [ ] Catch-all route Laravel que sirve el shell SPA para cualquier URL no-API

**S0-E8: Layouts + design system + atoms**
- [x] AdminLayout existente (revisar migración a Vue Router sin Inertia)
- [x] StorefrontLayout existente (idem)
- [ ] Migrar 11 páginas Admin existentes de Inertia a Vue Router (sacar `defineOptions({ layout })`, usar `<AdminLayout>` como wrapper directo en cada page)
- [ ] MarketingLayout para landing pública del SaaS
- [ ] OnboardingLayout para signup → plan → setup wizard
- [ ] SuperAdminLayout para panel de gestión de tenants
- [ ] Componentes atoms en `components/base/`: `Button`, `Input`, `Card`, `Slideover` (con swipe-close), `Modal`, `Table`, `Badge`, `Dropdown`, `Spinner`, `Avatar`, `Pagination`
- [ ] Componentes composite: `KpiCard`, `ProductCard`, `PriceDisplay`, `OrderStatusBadge`
- [ ] Composables: `useAuth`, `useTenant`, `useFormatCurrency`, `useFormatDate`, `useFormatPhone`, `useSlideover`, `useToast`, `useDebouncedSearch`, `useThemeMode`, `usePaginated`
- [ ] Dark mode class-based funcionando en todos los layouts
- [ ] Tokens Ethereal Boutique en `resources/css/app.css`

**S0-E9: Onboarding (REST + SPA)**
- [ ] Endpoints REST: `POST /api/v1/auth/register`, `GET /api/v1/plans`, `GET /api/v1/tenants/check-slug?slug=`, `POST /api/v1/tenants` (crea tenant + branch + subscription en trial + tenant_users owner)
- [ ] Página SPA `/signup` (MarketingLayout)
- [ ] Wizard SPA con state local (Pinia store onboarding): 1) datos de cuenta → 2) elegir plan → 3) crear tenant (nombre, slug, marca) → 4) bienvenida + redirect a su subdomain
- [ ] Validación de slug en tiempo real (debounced, contra blacklist + disponibilidad)
- [ ] Email de bienvenida con link al nuevo subdomain

**S0-E10: Seeders demo**
- [ ] Tenant 1: "Floristería Rosa Eterna" (slug `rosa-eterna`, plan Pro, trial activo)
- [ ] Tenant 2: "Regalos Tatiana" (slug `tatiana`, plan Básico, trial activo)
- [ ] Cada tenant con: 1 branch principal, 1 owner, 3 staff, 5 productos demo, 3 categorías
- [ ] 1 usuario super_admin para acceso a `/super-admin`

**S0-E11: CI**
- [ ] GitHub Actions: PR check con `pint --test`, `npm run lint`, `vue-tsc --noEmit`, `php artisan test`
- [ ] `php artisan scribe:generate` ejecutado en CI para verificar que no rompe
- [ ] Playwright sólo en main + develop (no en cada PR — costoso)
- [ ] Branch protection en main y develop: PR review obligatoria + checks verdes

### 7.3 Entregable Sprint 0

Al final del sprint debe ser posible:
1. Hacer `./vendor/bin/sail up -d && sail npm run dev` y tener la app andando.
2. Visitar `http://rosa-eterna.eternova.localhost:8080` y ver el storefront SPA del tenant demo 1.
3. Visitar `http://tatiana.eternova.localhost:8080` y ver el storefront SPA del tenant demo 2.
4. Login con el owner del tenant correspondiente y entrar al AdminLayout (SPA con Vue Router).
5. Visitar `http://eternova.localhost:8080/signup` y crear un tenant nuevo end-to-end via API REST.
6. Visitar `http://eternova.localhost:8080/super-admin` con cuenta super admin y ver listado de tenants.
7. Visitar `http://eternova.localhost:8080/docs` y ver la documentación Scribe de la API.
8. Probar `curl -X POST http://eternova.localhost:8080/api/v1/auth/token -d 'email=...&password=...'` y recibir un Bearer token funcional (validar con `GET /api/v1/me`).
9. Verificar que las cookies Sanctum funcionan: hacer `GET /sanctum/csrf-cookie` luego `POST /api/v1/auth/login` y poder llamar endpoints autenticados desde el browser.

---

## 8. Sprint 1 — Catalog & Inventory (detallado)

**Duración:** 2 semanas. **Objetivo:** módulo de catálogo completo (productos con variants tipo Shopify) e inventario por sucursal funcional para los tenants demo.

**Pre-requisitos:** Sprint 0 cerrado.

### 8.1 Épicas

| # | Épica | Owner sugerido | Skills |
|---|---|---|---|
| S1-E1 | Schema catálogo tipo Shopify | `software-architect` + `backend-developer` | `laravel-saas-multi-tenant-foundation` |
| S1-E2 | CRUD Categorías | `backend-developer` + `frontend-developer` | `laravel-design-patterns-toolkit` |
| S1-E3 | CRUD Productos con variants y options | `backend-developer` + `frontend-developer` | `vue-inertia-frontend-system` |
| S1-E4 | Upload de imágenes (single + galería) | `backend-developer` | (Intervention Image) |
| S1-E5 | Schema branch_inventory + movimientos | `software-architect` + `backend-developer` | `laravel-design-patterns-toolkit` |
| S1-E6 | UI inventario con búsqueda y filtros | `frontend-developer` + `ui-ux-design-system` | `vue-inertia-frontend-system` |
| S1-E7 | Alertas de stock bajo (event + listener) | `backend-developer` | `laravel-saas-email-transactional` |
| S1-E8 | Tests dual-layer del módulo | `backend-developer` + `qa-engineer` | `saas-testing-dual-layer` |

### 8.2 Tickets concretos

**S1-E1: Schema catálogo**
- [ ] Tabla `categories` (id, tenant_id, name, slug, description, image, parent_id, sort_order, is_active)
- [ ] Tabla `products` (id, tenant_id, name, slug, description, base_price, cost_price, is_active, is_featured, tax_rate, default_image, gallery json, sku_root)
- [ ] Tabla `product_variants` (id, product_id, sku, barcode, price, cost_price, weight, options json — { "Color": "Rojo", "Tamaño": "Grande" })
- [ ] Tabla `product_options` (id, product_id, name, position) — define opciones del producto (Color, Tamaño)
- [ ] Tabla `product_option_values` (id, option_id, value, position) — valores posibles
- [ ] Tabla pivot `category_product` (category_id, product_id) — M2M
- [ ] Tabla `tags` (id, tenant_id, name, slug) + pivot `product_tag`
- [ ] Todas con `tenant_id` + `BelongsToTenant`

**S1-E2: CRUD Categorías** — patrón de referencia para CRUDs (replicar en otros módulos)

Backend:
- [ ] Migration `categories` + `category_product` (M2M con tenant_id)
- [ ] Model `Category` con `BelongsToTenant`
- [ ] `CategoryRepositoryInterface` + `EloquentCategoryRepository`
- [ ] `CategoryService` con `list`, `create`, `update`, `delete`, `reorder`
- [ ] `StoreCategoryRequest`, `UpdateCategoryRequest`
- [ ] `CategoryResource`, `CategoryCollection`
- [ ] `CategoryController` REST en `app/Modules/Catalog/Http/Controllers/Api/V1/`
- [ ] `CategoryPolicy` con validación tenant + role
- [ ] Rutas en `routes/api/v1/catalog.php`
- [ ] Factory + seeder demo
- [ ] Scribe annotations en el controller

Frontend:
- [ ] `services/CategoriesService.ts` con métodos `list`, `get`, `create`, `update`, `delete`, `reorder`
- [ ] `stores/categories.ts` Pinia
- [ ] `pages/Admin/Catalog/CategoriesListPage.vue` con drag-sort para reordenar
- [ ] `pages/Admin/Catalog/CategoryFormPage.vue` o slideover de creación/edición
- [ ] Soporte para subcategorías (parent_id, max 2 niveles)
- [ ] Slug auto-generado con validación de unicidad por tenant (debounced)
- [ ] Soft delete UI

**S1-E3: CRUD Productos con variants**

Backend:
- [ ] Migrations: `products`, `product_variants`, `product_options`, `product_option_values`, `tags`, `product_tag`
- [ ] Models con `BelongsToTenant`, relaciones, casts json para `options`
- [ ] Repositories e Services siguiendo el patrón
- [ ] `ProductVariantService` con generación de variants por combinación de options
- [ ] `StoreProductRequest` con validación nested para variants
- [ ] `ProductResource` con embeds (variants, categories, tags)
- [ ] `ProductController` REST + sub-resource `ProductVariantController`

Frontend:
- [ ] `services/ProductsService.ts`, `stores/products.ts`
- [ ] `pages/Admin/Catalog/ProductsListPage.vue` con búsqueda + filtros (categoría/tag/estado)
- [ ] `pages/Admin/Catalog/ProductFormPage.vue` multi-step: datos básicos → opciones y variants → imágenes → categorías y tags → SEO
- [ ] Component `ProductVariantsEditor.vue` (tabla generada por combinación)
- [ ] Composable `useProductForm` con validación cliente sincronizada con backend
- [ ] Preview en vivo del producto como se vería en el storefront

**S1-E4: Upload imágenes**

Backend:
- [ ] Endpoint `POST /api/v1/products/{id}/images` con `multipart/form-data`
- [ ] Service que maneja resize con Intervention Image (thumbnail 200px, medium 600px, full 1200px)
- [ ] Storage en disk configurado: `tenants/{tenant_id}/products/{product_id}/`
- [ ] Endpoint `DELETE /api/v1/products/{id}/images/{image_id}`
- [ ] Endpoint `PATCH /api/v1/products/{id}/images/reorder`
- [ ] Validación: max 5MB, formatos jpg/png/webp, dimensión mínima 400x400

Frontend:
- [ ] Component `ImageUploader.vue` con drop-zone
- [ ] Component `ImageGallery.vue` con drag-sort
- [ ] Composable `useImageUpload` con progress, retry, cancel
- [ ] Preview optimista antes de upload confirmation

**S1-E5: Schema inventario por sucursal**

Backend:
- [ ] Migration `branch_inventory` (id, tenant_id, branch_id, product_variant_id, quantity, reserved, available — generated column = quantity - reserved) con UNIQUE (tenant_id, branch_id, product_variant_id)
- [ ] Migration `inventory_movements` (id, tenant_id, branch_id, product_variant_id, type [entry|exit|adjustment|transfer], quantity, reference_type, reference_id, notes, user_id, created_at)
- [ ] `InventoryService` con métodos: `recordEntry`, `recordExit`, `recordAdjustment`, `transferBetweenBranches` (todos en DB transaction)
- [ ] Stock disponible en tiempo real (no batch) — bloqueo optimista con `lockForUpdate` en transacciones
- [ ] Endpoints REST: `GET /api/v1/inventory`, `GET /api/v1/inventory/{branch}`, `POST /api/v1/inventory/movements`, `POST /api/v1/inventory/transfers`

**S1-E6: UI inventario**

Frontend:
- [ ] `services/InventoryService.ts`, `stores/inventory.ts`
- [ ] `pages/Admin/Inventory/InventoryListPage.vue` con stock por sucursal (columnas dinámicas por branch)
- [ ] Filtros: por sucursal, por categoría, stock bajo, sin stock
- [ ] Slideover de ajuste de stock (componente `AdjustStockSlideover.vue`)
- [ ] Slideover de transferencia entre sucursales (`TransferStockSlideover.vue`)
- [ ] Vista de movimientos recientes (panel lateral o page dedicada)
- [ ] Composable `useStockBadge` que devuelve clase tailwind según nivel

**S1-E7: Alertas stock bajo**

Backend:
- [ ] Campo `min_stock_alert` en `product_variants` (opcional, fallback a config global por tenant)
- [ ] Event `StockLowDetected` disparado en el `InventoryService` al cruzar threshold
- [ ] Listener `NotifyOwnerOfLowStock` (queued) envía email + crea row en tabla `notifications`
- [ ] Endpoint `GET /api/v1/notifications/unread-count` para badge en sidebar

Frontend:
- [ ] Composable `useNotifications` con polling cada 60s o subscripción WebSocket (Sprint 9)
- [ ] Badge "Stock bajo" en sidebar admin con conteo (consumido del endpoint)

**S1-E8: Tests dual-layer**

PHPUnit:
- [ ] `tests/Feature/Catalog/CategoryApiTest.php` — CRUD endpoints + scope tenant
- [ ] `tests/Feature/Catalog/ProductApiTest.php` — CRUD + variants generation + scope tenant
- [ ] `tests/Feature/Catalog/ProductImageUploadTest.php`
- [ ] `tests/Feature/Inventory/StockMovementTest.php` — atomicidad, transferencia, concurrencia
- [ ] `tests/Feature/Inventory/LowStockAlertTest.php` — event + listener fires correctly
- [ ] Cada test usa factory + `RefreshDatabase`, scenarios con 2 tenants para validar aislamiento

Playwright:
- [ ] `tests/e2e/catalog/categories.spec.ts` — crear/editar/reordenar/eliminar categoría
- [ ] `tests/e2e/catalog/products.spec.ts` — formulario multi-step, crear producto con variants
- [ ] `tests/e2e/catalog/product-images.spec.ts` — drag-drop upload + reorder
- [ ] `tests/e2e/inventory/stock.spec.ts` — ajustar stock desde slideover, transferencia entre sucursales
- [ ] `tests/e2e/inventory/low-stock-alert.spec.ts` — badge aparece en sidebar tras movimiento que cruza threshold

### 8.3 Entregable Sprint 1

- Tenant demo "Rosa Eterna" tiene 20 productos con variants, distribuidos en 5 categorías.
- Tenant demo "Tatiana" tiene su propio catálogo distinto (verificación visual de scope).
- Admin puede crear/editar/eliminar productos con variants desde la UI sin tocar SQL.
- Inventario por sucursal funciona: ajustar stock en sucursal A no afecta a sucursal B.
- Alerta de stock bajo se ve en el sidebar y dispara email al owner del tenant.
- Suite de tests pasa: PHPUnit + Playwright.

---

## 9. Sprint roadmap — Sprint 2 a Sprint 8 (alto nivel)

> Estos sprints se detallan al inicio de cada uno (durante el planning). Acá solo el norte.

### Sprint 2 — Storefront público + Carrito + Checkout WhatsApp (2 sem)
- Catálogo público del tenant (`/`, `/products`, `/products/{slug}`)
- Carrito persistente (Pinia + localStorage)
- Checkout que arma mensaje WhatsApp formateado y abre `wa.me`
- Bloom Chips para categorías, surrogates de imagen mientras carga
- SEO básico: meta tags, sitemap.xml por tenant

### Sprint 3 — POS interno (2 sem)
- Interfaz POS con grid de productos + carrito lateral
- Búsqueda rápida nombre/SKU/barcode
- Filtros por categoría (tabs)
- Selector de cliente (walk-in o registrado)
- Métodos de pago: efectivo, tarjeta (manual, sin gateway)
- Checkout → crea order + descuenta inventario
- Receipt/ticket básico (HTML printable)

### Sprint 4 — Orders y Despachos (2 sem)
- Listado de pedidos con tabs de estado
- Vista detalle con timeline
- Cambios de estado (workflow): pending → preparing → ready → dispatched → delivered
- Notas internas, asignación a staff
- Tracking ID para clientes (link público read-only)

### Sprint 5 — Reservations (2 sem)
- Reservas personalizadas (ej: arreglos custom para eventos)
- Captura: descripción, fecha entrega, ocasión, monto
- Adelantos (30% default, configurable en Settings)
- Estados: inquiry → confirmed → in_progress → ready → delivered
- Conversión a Order al entregar

### Sprint 6 — Expenses con OCR (2 sem)
- CRUD gastos con categorías (operating, products, payroll, rent, other)
- Upload de foto/PDF de factura
- OCR vía Tesseract (queued job): extrae vendor, monto, fecha, items
- UI de verificación: usuario corrige extracción antes de guardar
- Reporte mensual por categoría

### Sprint 7 — Quotations PDF (2 sem)
- CRUD cotizaciones (draft → sent → accepted/rejected/expired)
- Editor con items, ajustes de precio y cantidad
- Preview en vivo del PDF (DomPDF o Browsershot)
- Send por email/WhatsApp con link de aceptación
- Convertir cotización aceptada en Order

### Sprint 8 — Dashboard + Polish + Plan gating (2 sem)
- Dashboard con KPI cards reales (no mock)
- Gráficos (Chart.js): ventas, top productos, ocupación de staff
- Plan gating activado: features de Pro/Enterprise bloqueadas con CTA en plan Básico
- Onboarding tour para nuevos tenants
- Pulido visual general, accesibilidad, performance

### Sprint 9+ (post-MVP)
- Billing real con Wompi (webhooks + dunning)
- Multi-idioma (en/es)
- Reportes avanzados y exportables
- Integraciones (email marketing, contabilidad)
- App móvil (PWA primero)

---

## 10. Riesgos identificados

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Conflictos git por trabajo paralelo en `AdminLayout.vue` o `app.css` | Alta | Media | Comunicar antes de tocar archivos transversales. Mergear rápido. |
| `tenant_id` olvidado en una migración → leak entre tenants | Media | Crítica | Code review obligatoria. CI check que valida que toda migración tenga `tenant_id` (excepto módulos SaaS). |
| Wompi cambia su API antes de Sprint 9 | Baja | Alta | Abstraer en `PaymentGatewayInterface`. Sandbox testing early. |
| Caro y Erick implementan el mismo módulo por mala coordinación | Media | Alta | Boundaries del §5.2. Asignar dueño claro por sprint en planning. |
| Tests Playwright se vuelven flaky con multi-tenant | Media | Media | Aislar DB por test con `RefreshDatabase`. No compartir tenants entre tests. |
| Performance del global scope `BelongsToTenant` en queries pesadas | Media | Media | Índice en `(tenant_id, ...)`. Benchmark al final de Sprint 1. |
| Acumulación de skills duplicados (proyecto vs global) | Baja | Bajo | Limpieza programada para Sprint 3. |

---

## 11. Documentos relacionados

| Documento | Propósito |
|---|---|
| `CLAUDE.md` (raíz) | Convenciones de código + comandos + reglas duras para Claude Code |
| `docs/architecture.md` | Arquitectura técnica (TODO: actualizar a multi-tenant, está desactualizado) |
| `docs/roadmap.md` | Roadmap de producto a alto nivel (reescrito para Eternova) |
| `docs/agents.md` | Catálogo de agentes y skills (reescrito para Eternova) |
| `docs/engineering/engineering-process.md` | **Este documento** — proceso de ingeniería y sprints |
| `docs/features/stitch_gestor_integral_de_negocio/` | Diseños visuales de referencia (Pastel Bloom Gilt) |
| `.claude/skills/` | Skills locales (14 skills) |
| `.claude/agents/` | Definiciones de agentes (6 agentes) |
| `~/.claude/skills/saas-tenant-subdomain-strategy/` | Skill global para wildcard subdomain (645 líneas) |

---

## 12. Cambios pendientes (TODO sobre este documento)

- [x] ~~Actualizar `docs/architecture.md`~~ → Hecho 2026-06-04 con migración Inertia → REST + SPA.
- [ ] Crear `docs/decisions/` con ADRs (Architecture Decision Records) numerados para cada decisión P0/P1 cerrada — uno por decisión, con contexto + alternativas + decisión + consecuencias. Empezar por ADR-000 (Inertia → REST migración 2026-06-04).
- [ ] Resolver ADR-001 (SEO storefront): Vite SSR vs pre-rendering vs accept-SEO-loss + estrategia compensatoria. Decisión en Sprint 8.
- [ ] Resolver ADR-004 (TypeScript types desde OpenAPI): manual vs `openapi-typescript` automático. Decisión temprano en Sprint 2.
- [ ] Limpiar skills duplicados entre `.claude/skills/` (proyecto) y `~/.claude/skills/` (global). Definir cuál vive dónde.
- [ ] Renombrar skill `vue-inertia-frontend-system` → `vue-spa-frontend-system` y actualizar contenido para reflejar Vue Router + Pinia + Axios pattern (Inertia fuera).
- [ ] Decidir si Notion entra como complemento (PRD + UML + diagramas Mermaid) o si todo vive en `docs/`.
- [ ] Definir métricas mínimas para el dashboard de Sprint 8 (qué KPIs son must-have del MVP).

## 13. Changelog

| Fecha | Cambio |
|---|---|
| 2026-06-04 | Creación del documento. Decisiones P0/P1 cerradas. Sprint 0 + 1 detallados. |
| 2026-06-04 | **Cambio arquitectónico mayor:** migración de Inertia.js → REST API + Vue SPA con Vue Router/Pinia/Axios/Scribe. Auth Sanctum dual mode. Sprint 0 extendido a 3 semanas. `docs/architecture.md` reescrito en paralelo. |
