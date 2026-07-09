# Admin theme system (Ethereal + Minimalista) — Design

**Date:** 2026-07-09
**Status:** Approved (brainstorming)
**Phase:** 1 of the multi-vertical roadmap (`docs/product/roadmap-multi-vertical-2026.md`)
**Branch:** `feat/admin-theme-system`

## Goal

Give each tenant a choice of admin/POS theme — **Ethereal** (the current rosa
palette, default) or **Minimalista** (a neutral emerald/slate palette in the spirit
of restaurant-inventory) — so the software stops imposing pink on merchants for whom
it does not fit. The theme is a **per-tenant** default seen by all of that tenant's
users. Light/dark stays a **per-user** preference, orthogonal to the theme.

Non-goal: arbitrary per-tenant custom colours in the admin (that stays a
storefront-only feature, already built via `useStorefrontBranding`). Only two
hand-authored palettes here.

## Problem framing

The admin already renders entirely through a two-layer CSS token system in
`resources/css/app.css`:

- Runtime tokens in `:root` (rosa: `--primary #7c545d`, `--surface #fff8f7`, the
  full `--surface-*` ramp, `--secondary`, `--on-surface`, `--error`, radii, shadows).
- `.dark {}` overrides those runtime tokens for dark mode.
- `@theme { --color-*: var(--*) }` maps them into Tailwind utilities.

So every component is already theme-driven. Adding a second palette is additive: a
new set of token overrides plus a way to select it. No component markup changes.

## Architecture

### Token layer — a theme axis by root class

Rosa stays the default (unclassed `:root`). The minimal theme is a root class
`theme-minimal` on `<html>`, orthogonal to the existing `.dark` class:

| `<html>` classes | Result |
|---|---|
| (none) | Ethereal light |
| `.dark` | Ethereal dark |
| `.theme-minimal` | Minimalista light |
| `.theme-minimal.dark` | Minimalista dark |

In `app.css`, after the existing `:root` and `.dark` blocks, add:

```css
/* Minimalista: neutral emerald/slate. Overrides the runtime tokens; the @theme
   mapping and every component pick these up unchanged. */
.theme-minimal {
    --surface: #f8fafc;            /* slate-50 */
    --surface-lowest: #ffffff;
    --surface-low: #f1f5f9;        /* slate-100 */
    --surface-mid: #e9eef4;
    --surface-high: #e2e8f0;       /* slate-200 */
    --surface-highest: #dbe2ea;
    --primary: #059669;            /* emerald-600 */
    --primary-dim: #047857;        /* emerald-700 */
    --primary-container: #d1fae5;  /* emerald-100 */
    --secondary: #475569;          /* slate-600 */
    --on-surface: #1e293b;         /* slate-800 (never pure black) */
    /* ...every runtime token the rosa :root defines, given a neutral value... */
}

.theme-minimal.dark {
    --surface: #0f172a;            /* slate-900 */
    --surface-lowest: #0b1220;
    --surface-low: #1e293b;        /* slate-800 */
    /* ...dark neutral values for the full token set... */
    --primary: #34d399;            /* emerald-400, lifts on dark */
    --primary-container: #064e3b;  /* emerald-900 */
    --on-surface: #e2e8f0;
}
```

**Specificity note:** `:root`, `.dark`, and `.theme-minimal` are all specificity
(0,1,0); `.theme-minimal.dark` is (0,2,0). The minimal blocks must come **after**
`:root`/`.dark` in source order so equal-specificity light rules win, and
`.theme-minimal.dark` (higher specificity) wins for minimal dark. The
implementation must copy the **complete** runtime token list from `:root`/`.dark`
into both minimal blocks — any token left out falls back to the rosa value and
leaks pink into the neutral theme.

### Persistence & where the value lives

`admin_theme` is a **brand policy setting**, so it joins the existing brand group
stored on the `tenants` table (alongside `business_name`, `primary_color`,
`secondary_color` — see `SettingsService::TENANT_ROW_GROUPS['brand']`).

- **Migration:** add `admin_theme` to `tenants` — `string('admin_theme', 20)->default('ethereal')`.
  Reversible `down()`.
- **Model:** add `admin_theme` to the Tenant `$fillable` + cast (string).
- **Enum guard:** allowed values `ethereal` | `minimal`. Introduce
  `App\Modules\Settings\Enums\AdminTheme` (string enum) used by validation and
  defaults, so the set of themes is one authoritative list.

### Backend wiring

- **`SettingsService`:** add `admin_theme` to the `brand` group mapping and to the
  brand section of the resolve/show output. Reads/writes the tenant column like the
  other brand fields.
- **`UpdateSettingsRequest`:** validate `brand.admin_theme` with
  `Rule::enum(AdminTheme::class)` (`sometimes`).
- **Bootstrap exposure:** the admin must know the tenant's theme at load. Expose
  `admin_theme` wherever the admin already receives tenant brand data at bootstrap
  (the same tenant payload that carries `primary_color`). If that payload is the
  `/me` tenant block, add the field there; confirm the exact resource during
  implementation and keep it beside `primary_color`.

### Frontend wiring

- **`stores/ui.ts`:** add `theme: 'ethereal' | 'minimal'` state and a `setTheme()`
  action that toggles the `theme-minimal` class on `document.documentElement`
  (mirrors how `darkMode` toggles `.dark`). Persist to `localStorage`
  (`eternova-admin-theme`) for instant paint on the next load (no flash of pink),
  exactly like `eternova-dark-mode`.
- **Boot reconciliation:** apply the cached localStorage theme immediately at store
  init; once the tenant payload loads, reconcile to the tenant's `admin_theme`
  (backend is the source of truth; update the cache). This avoids a flash while
  keeping the per-tenant value authoritative.
- **`useTheme` composable:** expose `theme` + `setTheme` next to the existing
  `isDark`/`toggle`.
- **Settings > Apariencia:** a new section in `SettingsPage.vue` (brand tab area)
  with two selectable cards — "Ethereal" and "Minimalista" — each a small live
  swatch. Selecting one sets `brand.admin_theme` and saves via the existing settings
  save flow. `data-testid="theme-option-ethereal"` / `theme-option-minimal`.
- **Apply the theme app-wide:** `AdminLayout.vue` already applies dark on mount via
  `useTheme`; apply the theme class the same way. The storefront is unaffected (it
  keeps `useStorefrontBranding`).

## Testing (dual-layer)

- **PHPUnit `tests/Feature/Settings/AdminThemeTest.php`:**
  - default `admin_theme` is `ethereal` for a new tenant;
  - `PATCH /api/v1/settings` with `brand.admin_theme = minimal` persists on the
    tenant row and the show endpoint returns it;
  - an invalid value (e.g. `neon`) is rejected 422;
  - tenant isolation: tenant B's theme change does not affect tenant A.
- **e2e `tests/e2e/admin/settings/theme.spec.ts`:**
  - Settings > Apariencia: select Minimalista → `<html>` gains `theme-minimal` and a
    primary-colored element computes the emerald value (not rosa); persists across a
    reload; switching back to Ethereal removes the class. Verify light + dark both
    render (toggle dark, assert no rosa token leaks).
- **Visual QA:** admin dashboard + POS in all four combinations (ethereal/minimal ×
  light/dark), confirming no pink leaks into the minimal theme and contrast holds.

## Out of scope (YAGNI)

- Arbitrary custom admin colours (storefront-only, already exists).
- More than two themes (the enum makes adding a third cheap later).
- Theming the marketing site / super-admin (SaaS-brand surfaces, not tenant themes).
- Per-user theme override of the tenant default.

## Global constraints

- No emojis anywhere (code, UI, comments, commits). No AI co-author trailer.
- Feature branch → PR against `develop`; squash-merge.
- Multi-tenant: `admin_theme` is per-tenant; never leak across tenants.
- Ethereal Boutique discipline holds for BOTH themes: tokens only, No-Line rule,
  never pure black, Lucide icons, dark mode first-class and verified.
- Dual-layer testing (PHPUnit + Playwright) before the PR closes; visual QA in the
  four theme×mode combinations.
