# Guía de Agentes y Skills — Eternova

> **Cómo usar este documento.** Este es el catálogo de agentes especializados de Claude Code (`.claude/agents/`) y skills disponibles (`.claude/skills/` + `~/.claude/skills/`) que el equipo usa para construir Eternova. Léelo antes de invocar cualquier agente.
>
> Para arquitectura técnica (REST API + SPA + capas) ver `docs/architecture.md`. Para el plan de sprints ver `docs/engineering/engineering-process.md`. Para roadmap de producto ver `docs/roadmap.md`.
>
> Última actualización: 2026-06-04 (migración Inertia → REST + SPA).

---

## 1. Filosofía

Eternova se construye con **dos personas reales** (Erick + Carolina) **y seis agentes especializados** de Claude Code corriendo en paralelo. Los agentes no reemplazan a los devs: extienden su capacidad.

Reglas base:

1. **Los agentes consultan skills antes de implementar.** Cuando invocas un agente, indícale qué skills debe leer primero.
2. **Los agentes respetan los boundaries por módulo** definidos en `docs/engineering/engineering-process.md` §5.2.
3. **Cada cambio que sale a producción pasa por el `qa-engineer`** para validación visual antes del merge.
4. **Los agentes nunca hacen push sin confirmación humana.** Crean ramas, commits y PRs como borrador.
5. **Sin Co-Authored-By de Claude en los commits.** Esto es regla dura del repositorio.

---

## 2. Catálogo de agentes (`.claude/agents/`)

### 2.1 `software-architect`

**Cuándo invocarlo:**
- Decisiones de arquitectura (módulos nuevos, contratos entre módulos).
- Diseño de esquema de base de datos (migraciones, índices, foreign keys).
- Configuración de auth (roles, permisos, policies, gates).
- Code review estructural (¿este módulo respeta el Repository Pattern? ¿el `tenant_id` está bien aplicado?).

**Skills que típicamente debe invocar:**
- `laravel-saas-architecture-decisions`
- `laravel-saas-multi-tenant-foundation`
- `laravel-saas-auth-granularity`

**No invocarlo para:** implementar código línea por línea (eso lo hace `backend-developer`).

---

### 2.2 `backend-developer`

**Cuándo invocarlo:**
- Implementar Controllers REST en `app/Modules/{Module}/Http/Controllers/Api/V1/`.
- Implementar Services, Repositories, Form Requests, API Resources.
- Crear Observers, Jobs, Events, Listeners.
- Escribir migraciones, seeders, factories.
- Implementar tests PHPUnit con factories + `RefreshDatabase`.
- Optimizar queries y agregar índices.
- Integraciones externas backend (OCR, PDF, mail).
- **Anotar endpoints con PHPDoc para Scribe** (auto-generación de docs API).

**Skills que típicamente debe invocar:**
- `laravel-design-patterns-toolkit` (Repository, Service, Strategy, Observer)
- `senior-dev-code-style` (PSR-12, `declare(strict_types=1)`, `final readonly`)
- `saas-testing-dual-layer` (boilerplate de tests multi-tenant)
- El skill del **módulo específico**: `laravel-saas-billing-infrastructure`, `laravel-saas-settings-architecture`, `laravel-saas-i18n-latam`, `laravel-saas-email-transactional`, etc.

**Convenciones REST que debe respetar (ver `docs/architecture.md` §5):**
- URLs en plural: `/api/v1/products`, `/api/v1/products/{id}/variants`.
- Response envelope: `{ data, meta, links }`.
- Errores RFC 7807 simplificado.
- Resources transforman Models → JSON, jamás se expone el Model directo.
- Paginación page-based para admin, cursor-based para storefront público.

**Output esperado:** código + tests PHPUnit pasando + Scribe genera docs sin warnings.

---

### 2.3 `frontend-developer`

**Cuándo invocarlo:**
- Crear páginas SPA (`resources/js/pages/`) y configurar sus rutas en Vue Router.
- Implementar componentes Vue reutilizables (`components/base/` atoms y `components/composite/` molecules).
- Crear composables en `composables/` (lógica reactiva reutilizable, sin tocar DOM).
- Crear Pinia stores en `stores/`.
- Crear API service clients en `services/` (usando la instancia Axios de `services/api.ts`).
- Construir forms con validación cliente + integración con endpoints REST.
- Implementar slideovers, modales, tabs, tablas con paginación.
- Tests Playwright E2E (spec en `tests/e2e/`).

**Skills que típicamente debe invocar:**
- `vue-inertia-frontend-system` *(nota: a renombrar `vue-spa-frontend-system` — su contenido aplica con adaptación a Vue Router + Pinia + Axios en lugar de Inertia)*
- `saas-plan-gating-billing` (cuando hay UI con gate por plan)
- `saas-testing-dual-layer` (sección Playwright)

**Convenciones frontend que debe respetar (ver `docs/architecture.md` §3.3):**
- **Pages** son smart: pueden importar stores y services.
- **Components base/composite** son dumb: sólo props in, events out. **Nunca** importan stores ni services.
- **Composables** encapsulan lógica reutilizable reactiva.
- **Stores Pinia** son la única fuente de verdad para estado compartido.
- **Services** son la única forma de hablar con el backend. Las pages jamás llaman a `axios` directo.
- **Types** se sincronizan a mano con backend (futuro: generar desde OpenAPI).
- TypeScript strict mode — sin `any` salvo casos justificados y comentados.

**Output esperado:** componentes + páginas + stores + services + spec Playwright pasando + `vue-tsc --noEmit` sin errores.

---

### 2.4 `ui-ux-design-system`

**Cuándo invocarlo:**
- Definir o ajustar tokens del design system Ethereal Boutique.
- Crear layouts nuevos (AdminLayout, StorefrontLayout, MarketingLayout, OnboardingLayout, SuperAdminLayout).
- Implementar animaciones, transiciones, skeletons.
- Asegurar dark mode en una vista.
- Verificar responsive (mobile-first, breakpoints).
- Mejorar accesibilidad (focus states, contraste, keyboard navigation).

**Skills que típicamente debe invocar:**
- `vue-inertia-frontend-system` (sección de design system)

**Trabaja en conjunto con `frontend-developer`:** el design-system define los tokens y los layouts, el frontend-developer los consume.

---

### 2.5 `devops-integration-engineer`

**Cuándo invocarlo:**
- Cambios en Docker, Laravel Sail, compose.yaml.
- Configuración de Vite (multiple entry points, HMR, bundling, build de producción).
- Configuración de TypeScript (`tsconfig.json`, `vue-tsc`).
- Configuración de Sanctum (`stateful_domains`, `SESSION_DOMAIN`, CORS).
- Configuración de Scribe (`config/scribe.php`, hook en CI).
- CI/CD pipelines (GitHub Actions).
- Performance: optimizaciones, caching (Redis), profiling.
- Integraciones externas: Tesseract OCR, DomPDF/Browsershot, mail.
- Storage: configuración de disks (local + S3 en producción).
- Queue workers (Redis, Horizon).
- Monitoring y observabilidad.
- Deploy y release.

**Skills que típicamente debe invocar:**
- `laravel-saas-email-transactional` (cuando configura el mailer)
- `saas-tenant-subdomain-strategy` (DNS, SSL, wildcard, custom domains)
- `saas-thermal-printing-pipeline` (cuando hay setup de impresora térmica para POS)

**Output esperado:** infraestructura corriendo + checks verdes en CI + Scribe genera docs en cada build.

---

### 2.6 `qa-engineer`

**Cuándo invocarlo:**
- **Antes de cerrar cualquier PR que toque UI.** Es paso obligatorio del DoD.
- Validación visual end-to-end con Playwright MCP browser tools.
- Screenshots desktop + mobile + dark mode.
- Detectar regresiones visuales en vistas relacionadas.
- Ejercitar flujos de usuario (clicks, forms, slideovers, navegación).
- Verificar accesibilidad básica (focus states, contraste, keyboard nav).

**Skills que típicamente debe invocar:**
- `saas-testing-dual-layer`
- `laravel-vue-verification-recipes` (global)

**No invocarlo para:** escribir código de producción ni los tests automáticos (eso lo hacen `backend-developer` y `frontend-developer`). El `qa-engineer` valida que lo construido funciona.

---

## 3. Skills disponibles

Los skills son guías cristalizadas con patrones, código de ejemplo y trade-offs ya documentados. Ahorran iteraciones de "redescubrir" lo mismo.

### 3.1 Skills locales del proyecto (`.claude/skills/`)

| Skill | Resumen | Cuándo invocarlo |
|---|---|---|
| `laravel-saas-multi-tenant-foundation` | Patrón `tenant_id` + `BelongsToTenant` + middleware + scopes globales | Cualquier tabla nueva, cualquier modelo nuevo, cualquier policy. |
| `laravel-saas-billing-infrastructure` | Suscripciones, invoices, payments, Wompi/Stripe, webhooks, dunning. 1193 líneas. | Sprint 0 (schema base) y Sprint 9 (billing real). |
| `saas-plan-gating-billing` | Patrón "ver bloqueado + CTA upgrade" para gate por plan | Cuando se gate-a una feature por plan. |
| `laravel-saas-settings-architecture` | Tabla `settings` con grupos, cache Redis con invalidación | Tocar settings o agregar grupo nuevo. |
| `laravel-saas-auth-granularity` | Roles, permisos, policies multi-tenant. Cada policy valida `tenant_id`. | Cuando se agrega una policy o se modifican roles. |
| `laravel-saas-i18n-latam` | Moneda por país, validación de teléfono dinámica, RUT/NIT/RFC, formatos de fecha | Sprint 11 (i18n completo) — antes ya por preview en Settings. |
| `laravel-saas-email-transactional` | Bienvenida, facturas, alertas, password reset, invitaciones | Cuando se configura un email nuevo. |
| `laravel-saas-architecture-decisions` | Decisiones P0/P1 ya cerradas, trade-offs documentados | Antes de proponer cambios estructurales. |
| `laravel-design-patterns-toolkit` | Repository, Service, Strategy, Observer en Laravel 12 | Implementar cualquier módulo. |
| `laravel-debugging-toolkit` | Errores comunes en Laravel/Sail | Cuando algo se rompe (config, cache, locks, migraciones). |
| `senior-dev-code-style` | Naming, organización, `declare(strict_types=1)`, `final readonly`, SOLID | Toda la vida — referencia constante. |
| `vue-inertia-frontend-system` | Composition API + Inertia 2 + Pinia + slideovers + dark mode | Cualquier UI nueva. |
| `saas-testing-dual-layer` | Doctrina: PHPUnit + Playwright. Boilerplate multi-tenant. | Cualquier cambio que mute el sistema. |
| `saas-thermal-printing-pipeline` | Impresoras térmicas POS para recibos y comandas | Sprint 3 (POS) si hay hardware térmico. |

### 3.2 Skills globales (`~/.claude/skills/`)

Disponibles desde cualquier proyecto del equipo. Son los más portables.

| Skill | Resumen | Cuándo invocarlo |
|---|---|---|
| `saas-tenant-subdomain-strategy` | Wildcard subdomain + custom domain + SSL Let's Encrypt DNS-01 + slug RFC 1035 + reserved subdomains + 16 edge cases | Sprint 0 (resolución de tenant) y Sprint 10 (custom domains end-to-end). |
| `laravel-vue-verification-recipes` | Recetas Playwright + curl + tinker para verificar features antes de marcar Done | Después de implementar cualquier feature. |
| `explain-code` | Explicar qué hace un trozo de código | Sólo cuando el usuario lo pide explícitamente. |

### 3.3 Cómo "hacerle saber" a un agente que existen skills

Cuando invocas un agente desde el chat, **mencioná explícitamente los skills relevantes en el prompt**. Ejemplo:

> "Implementá el CRUD de categorías con `backend-developer`. Antes de empezar consultá `laravel-design-patterns-toolkit` y `laravel-saas-multi-tenant-foundation`. Al terminar invocá `qa-engineer` para validación visual."

Si invocás un agente vía tool desde código:

```
Agent({
  subagent_type: "backend-developer",
  prompt: "Implementá el módulo Plans. Consultá ANTES estos skills: laravel-saas-billing-infrastructure (sección plans), laravel-saas-architecture-decisions, senior-dev-code-style, saas-testing-dual-layer. Output esperado: migration + model + factory + repository + service + form requests + tests PHPUnit."
})
```

**Si el agente no menciona el skill en su respuesta o no lo aplica visiblemente, recordáselo y pedile que lo lea explícitamente.**

---

## 4. Orden de ejecución sugerido por sprint

```
Sprint 0 (Foundation):
  software-architect  ── define schema, contratos, decisiones
       │
       ▼
  devops-integration-engineer  ── infra, ports, CI
       │  (paralelo)
       ▼
  backend-developer  ── implementa módulos Tenancy, Plans, Billing, Auth
       │  (paralelo)
       ▼
  ui-ux-design-system  ── layouts, tokens, design system
       │  (paralelo con backend)
       ▼
  frontend-developer  ── consume layouts, implementa Onboarding wizard
       │
       ▼
  qa-engineer  ── valida todo antes de mergear

Sprints 1-7 (Módulos de negocio):
  software-architect  ── revisa contratos del módulo nuevo
       │
       ▼
  backend-developer + frontend-developer  ── EN PARALELO
       │
       ▼
  qa-engineer  ── valida cada PR

Sprint 8 (Polish):
  ui-ux-design-system  ── revisa accesibilidad, animaciones, dark mode
  frontend-developer  ── dashboard + gráficos
  backend-developer  ── KPIs reales, optimizaciones
  qa-engineer  ── auditoría visual completa
```

---

## 5. Reglas para TODOS los agentes (resumidas — el detalle en CLAUDE.md y engineering-process.md)

1. **Leer `CLAUDE.md`** antes de tocar código.
2. **Leer `docs/engineering/engineering-process.md`** para entender el proceso y boundaries.
3. **Consultar el skill correspondiente** antes de implementar.
4. **Sin emojis** en código, comentarios, commits, ni documentación.
5. **Sin Co-Authored-By de Claude** ni ninguna IA en commits.
6. **Repository Pattern** obligatorio: Controller → Service → Repository → Model.
7. **`tenant_id` + `BelongsToTenant`** en todo modelo de negocio.
8. **PSR-12** + `declare(strict_types=1)` + type hints estrictos.
9. **Composition API** con `<script setup>` en Vue.
10. **Responsive mobile-first** + **dark mode** en toda UI.
11. **Slideovers** preferidos sobre modales (con swipe-close).
12. **Tests dual-layer** (PHPUnit + Playwright) en todo cambio que toque UI.
13. **Branding tenant-configurable** — no hardcodear nombres ni logos.
14. **Commits convencionales** en inglés: `feat|fix|refactor|chore|docs|test|style|perf|ci|build(scope): message`.
15. **No hacer push sin confirmación** del usuario humano.
16. **No mergear a main** sin PR revisado.
17. **PR contra `develop`** siempre, salvo hotfix urgente.

---

## 6. FAQ para agentes

**P: ¿Puedo crear un nuevo módulo sin consultar antes?**
R: No. Cualquier módulo nuevo requiere `software-architect` que valide el contrato + skill `laravel-saas-architecture-decisions`.

**P: ¿Puedo tocar `AdminLayout.vue` o `resources/css/app.css`?**
R: Son archivos transversales. Avisar primero en GitHub issue. Mergear rápido para no bloquear al otro dev.

**P: ¿Cuándo NO necesito Playwright?**
R: Lógica de dominio pura, value objects, repositories sin UI, comandos artisan internos. Cualquier cosa con UI: Playwright obligatorio.

**P: ¿Y si no encuentro un skill para mi caso?**
R: Implementá con el patrón general (CLAUDE.md + senior-dev-code-style). Si el caso es recurrente, proponé crear un skill nuevo en la retro.

**P: ¿Puedo mockear la DB en tests?**
R: No. Feature tests usan factory + `RefreshDatabase`. Mockear DB nos quemó antes y la regla es estricta.

**P: ¿Cómo manejo `tenant_id` en una tabla pivot M2M?**
R: También tiene `tenant_id`. Revisar `laravel-saas-multi-tenant-foundation` sección "M2M tables". Excepción: pivots de modelos SaaS (no de negocio), como `plan_features`.

**P: ¿Y si una decisión P0/P1 me parece equivocada?**
R: No la cambies por tu cuenta. Levantá la observación en un issue, brainstorming corto, ADR si se acepta cambio. Las decisiones cerradas están en `docs/engineering/engineering-process.md` §3.

**P: ¿Por qué REST API + SPA y no Inertia.js?**
R: Decisión tomada el 2026-06-04. Razones: 1) Ganar experiencia REST profesional, 2) abrir puertas a app móvil + integraciones B2B sin reescribir, 3) forzar separación de responsabilidades clara desde día 1, 4) escalabilidad horizontal real. Trade-off conocido: ~1.5x-2x más tiempo por feature, peor SEO del storefront (ADR-001 pendiente). Ver `docs/architecture.md` §1 y §11.

**P: ¿En el frontend puedo hacer `axios.get('/api/v1/products')` directo desde una page?**
R: No. Siempre vía service: `ProductsService.list()`. Si la lista es estado compartido también pasar por store: `productsStore.fetchList()`. La page solo invoca el store o el service y renderiza.

**P: ¿Cómo manejo loading states y errores en una page?**
R: El store de Pinia expone `isLoading`, `error`, `items`. La page los lee y muestra Spinner/ErrorState/EmptyState/list según corresponda. Ver composable `usePaginated` para el patrón genérico.

**P: ¿Cuándo uso cookies (SPA) vs Bearer token?**
R: Cookies para el frontend web SPA (mismo dominio que la API). Bearer token para clientes externos (app móvil futura, partners, integraciones B2B). Nunca mezclar en el mismo cliente.

**P: ¿Necesito documentar manualmente los endpoints?**
R: No. Scribe lo genera desde PHPDoc del controller + Form Request rules + Resource fields. Tu trabajo es escribir buen PHPDoc, no mantener un Postman manual.

**P: ¿Cómo evito que el SEO del storefront me arruine?**
R: ADR-001 pendiente (Sprint 8). Por ahora desarrollá normal. Cuando llegue Sprint 8 decidiremos entre Vite SSR, pre-rendering o estrategia compensatoria.
