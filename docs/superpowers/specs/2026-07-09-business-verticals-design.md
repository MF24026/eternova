# Business verticals (giro/nicho) framework — Design

**Date:** 2026-07-09
**Status:** Approved (brainstorming)
**Phase:** 2 of the multi-vertical roadmap (`docs/product/roadmap-multi-vertical-2026.md`)
**Branch:** `feat/business-verticals`

## Goal

Let a tenant pick its **business type** (giro) at signup — Treinta-style — and have
the software adapt: modules that do not apply to that giro are hidden, and sensible
defaults are set. The framework is an **extensible catalog**, so adding a new giro
later is a config entry, not a code change scattered across the app.

Scope is deliberately limited to **stock-managing retail businesses** — where Eternova
is strong (products, variants, inventory, POS). Gastronomía (tables/kitchen/recipes,
POSLatam's domain) and a services-without-stock mode are **out of scope**.

## Verticals (initial catalog)

| key | Label | Reservas | Cotizaciones | starter_template |
|---|---|---|---|---|
| `floreria_regalos` | Florería / Regalos | on | on | `floreria` |
| `ropa_boutique` | Ropa / Boutique | off | off | null |
| `accesorios` | Accesorios / Maquillaje | off | off | `accesorios` |
| `peluches` | Peluches / Juguetería | off | off | `peluches` |
| `minimarket` | Minimarket / Abarrotes | off | off | null |
| `otro` | Otro (general) | on | on | null |

## Unification with the existing `starter_template` (senior decision)

The onboarding already captured a transient `starter_template`
(`StarterCatalogService::TEMPLATES = floreria, accesorios, peluches, reposteria`) used
**once** to seed a demo catalog — it is not persisted. Rather than add a second
"what kind of business?" question (a DRY/UX violation), `business_type` is the **single
source of truth**: the vertical catalog owns the `starter_template` per giro, the
onboarding asks **once**, and `TenantProvisioner` **derives** the seed template from the
catalog. `starter_template` is removed as a separate user input. A giro with
`starter_template => null` (ropa, minimarket, otro) seeds **nothing** — provisioning must
skip seeding on null (do not fall back to the old default template). The legacy
`reposteria` template is archived (repostería is gastronomía, out of scope); `peluches`
becomes a giro so its existing demo catalog is reachable.

**Core modules (always on, giro-agnostic):** Panel, Productos, POS, Inventario,
Pedidos, Gastos, Clientes, Catálogo/Vitrina, Ajustes, Facturación.

**Giro-gated modules (this phase):** Reservas, Cotizaciones. Future modules
(e.g. **Caja registradora**) plug into the same catalog without framework changes.

## Key semantic decision — vertical-gating hides, plan-gating locks

These are **different concerns and must not be conflated**:

- **Plan-gating** (`usePlanGate`, Pattern B): a feature the plan does not grant stays
  **VISIBLE** with an upgrade CTA (`<UpgradeLock>`). It is an upsell.
- **Vertical-gating** (this phase): a module that does not apply to the giro is
  **HIDDEN entirely**. It is not an upsell — a florist showing a "kitchen" tab is
  noise, not an opportunity. No CTA, no lock — just absent.

The module-visibility resolver composes both, but they answer different questions.

## Architecture

Respects the modular structure: business modules stay **ignorant of verticals**. One
resolver decides visibility; the catalog is the single source of truth; enforcement
is server-side (REST) as well as in the nav.

### 1. `business_type` on the tenant

- **Migration:** add `business_type` to `tenants` — `string('business_type', 40)->default('otro')`,
  reversible `down()`.
- **Enum:** `App\Modules\Tenancy\Enums\BusinessType` (string enum) with the five cases
  above — the authoritative list used by validation, catalog, and defaults.
- **Model:** add `business_type` to Tenant `$fillable` + `protected $attributes` default
  `'otro'` (Eloquent does not populate a DB default in-memory after `create()` — same
  lesson as `admin_theme`).

### 2. Vertical catalog — single source of truth

`config/verticals.php` maps each `business_type` to its metadata and default modules:

```php
return [
    'floreria_regalos' => [
        'label' => 'Florería / Regalos',
        'icon' => 'flower',                       // Lucide icon name
        'modules' => ['reservations', 'quotations'], // optional modules ON by default
    ],
    'ropa_boutique' => ['label' => 'Ropa / Boutique', 'icon' => 'shirt', 'modules' => []],
    'accesorios'    => ['label' => 'Accesorios / Maquillaje', 'icon' => 'gem', 'modules' => []],
    'minimarket'    => ['label' => 'Minimarket / Abarrotes', 'icon' => 'shopping-basket', 'modules' => []],
    'otro'          => ['label' => 'Otro', 'icon' => 'store', 'modules' => ['reservations', 'quotations']],
];
```

Adding a giro = one entry here (+ the enum case). No other code changes.

### 3. Module-visibility resolver (central)

`App\Modules\Tenancy\Services\ModuleVisibilityService` — the one place that answers
"which optional modules does this tenant see?". Given a tenant:

- Start from the giro's default modules (from the catalog).
- Apply the tenant's **per-module overrides** (stored — see §5); an override wins over
  the giro default.
- Return the set of enabled optional-module ids.

The list of **gateable** optional modules is a constant (`['reservations', 'quotations']`
now; grows as modules are added). Core modules are never in this set.

Plan-gating stays separate (`usePlanGate`); the resolver does not touch it.

### 4. Enforcement — backend + nav

- **Bootstrap (`/me`):** expose `enabled_modules` (the resolver's result) on the current
  tenant block of `UserResource`, alongside `business_type`. The SPA reads it.
- **Frontend nav:** each `navItem` gains an optional `module` id. `visibleNavItems`
  additionally filters out items whose `module` is a gateable module not in
  `enabled_modules`. Router guards do the same for the gated routes (redirect to
  dashboard if the module is disabled), reusing the existing guard pattern.
- **API (defense in depth):** a middleware `EnsureModuleEnabled:{module}` on the gated
  route groups (Reservations, Quotations) returns 403 when the tenant has that module
  disabled — so a disabled module cannot be reached by hitting the API directly. This
  mirrors how module gates already work (`Gate::define` per module).

### 5. Per-tenant overrides (Settings)

Module toggles are a **policy** concern → stored where brand/locale live. Add a
`module_overrides` JSON column to `tenants` (nullable), holding `{ module: bool }` only
for modules the tenant explicitly changed from the giro default. Exposed as a new
settings group `modules`:

- `GET /api/v1/settings` → `modules: { reservations: bool, quotations: bool }` (resolved
  effective values).
- `POST /api/v1/settings/modules` → persists the overrides (owner/admin only, brand-like
  policy).
- Settings UI: a "Módulos" tab with a toggle per gateable module, explaining that the
  giro sets the defaults.

`business_type` itself is edited in the brand/identity area (it is part of the tenant's
identity), also via the settings API.

### 6. Onboarding

The onboarding **TenantStep** gains a `business_type` picker — a grid of giro cards
(icon + label from the catalog), Treinta-style. The chosen giro is sent when the tenant
is created; the tenant boots with that giro's default modules (no overrides yet).

## Testing (dual-layer)

- **PHPUnit:**
  - `BusinessTypeTest`: default `otro`; onboarding/settings persists a valid giro;
    invalid giro rejected 422; tenant isolation.
  - `ModuleVisibilityTest`: florería resolves `reservations+quotations`; ropa resolves
    none; an override flips a module regardless of giro; core modules never gated.
  - `EnsureModuleEnabledTest`: a tenant with `quotations` disabled gets 403 on
    `GET /api/v1/quotations`; an enabled tenant gets 200. `/me` carries `enabled_modules`.
- **Playwright e2e:**
  - Onboarding: pick a "Ropa" giro → the new tenant's admin nav has no Reservas /
    Cotizaciones; pick "Florería" → both present.
  - Settings → Módulos: toggle Cotizaciones off → the nav item disappears; on → returns.

## Out of scope (YAGNI)

- Terminology per giro (needs an i18n layer — its own future phase).
- Gastronomía vertical (tables/kitchen/recipes — a different product).
- Services-without-stock mode (a deeper catalog-behavior change — future).
- The Caja registradora module itself (its own phase; it will register as a gateable
  module in this framework, broadly enabled).

## Global constraints

- No emojis anywhere (code, UI, comments, commits). No AI co-author trailer.
- Feature branch → PR against `develop`; squash-merge.
- Multi-tenant: `business_type` / overrides are per-tenant; never leak across tenants.
- Modules stay ignorant of verticals; one resolver, one catalog (single source of truth).
- Vertical-gating HIDES; plan-gating LOCKS — do not conflate.
- Enforcement is server-side (REST) as well as nav — a disabled module 403s on the API.
- Dual-layer testing (PHPUnit + Playwright) before the PR closes.
