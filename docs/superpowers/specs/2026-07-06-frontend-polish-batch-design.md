# Frontend Polish Batch — Design Spec

**Goal:** Fix the concrete visual/UX defects found in the 2026-07-06 frontend audit, as 7 independent, separately-shippable units.

**Architecture:** Vue 3 SPA (vue-router + Pinia + axios to /api/v1) with the Ethereal Boutique design system (CSS tokens in `resources/css/app.css`). Each unit is a focused change to base/composite components or a copy sweep. No backend changes except where a test needs a seed hook.

**Tech Stack:** Vue 3 `<script setup>`, Tailwind CSS 4, Lucide icons. Dual-layer testing: PHPUnit (only where domain/API touched — mostly N/A here) + Playwright e2e against the built bundle.

## Global Constraints

- NO emojis anywhere (code, UI, comments, commits).
- NO AI co-author / `Co-Authored-By` trailer in commits.
- Each unit: its own branch off `develop` (`fix/…` or `feat/…`) → PR to `develop`. Never to `main`.
- Playwright e2e runs against `public/build` — run `npm run build` before e2e.
- Design system: No-Line rule (separate with background tiers, not 1px borders), rounded corners `--r-lg`/`--r-xl`, never pure black text (`var(--on-surface)`), dark mode must stay covered.
- Spanish is the UI language; all copy uses correct accents (tildes).
- Visual QA (desktop + mobile + dark) before each PR closes, per CLAUDE.md.

---

## Unit U1 — AppTable responsive (card mode on mobile)

**Problem:** `AppTable.vue` renders a `<table>` in an `overflow-x-auto` wrapper. On < `md` the table scrolls horizontally and hides key columns (Total, Estado, Asignado). Affects 6 admin list views (Orders, Products, Customers, Expenses, Quotations, Reservations).

**Files:**
- Modify: `resources/js/components/base/AppTable.vue`
- Test: `tests/e2e/app-table-responsive.spec.ts` (new)

**Approach:** Keep the `<table>` for `md:` and up. Below `md`, render a stacked-card list from the same `columns` + `rows` props: one card per row, each cell shown as a `label: value` pair using `TableColumn.label`. Reuse `col.render` where present. Preserve `row-click` emit (card is clickable). Columns may opt out of the mobile card via a new optional `TableColumn.hideOnMobile?: boolean` (default false) so noisy columns can be suppressed. Loading + empty states must render in both layouts.

**Test:** At 375px on `/admin/orders`, assert the Total and Estado values for the first order are visible without horizontal scroll (`scrollWidth === clientWidth` on the list container).

## Unit U2 — Storefront horizontal overflow

**Problem:** The public storefront has `scrollWidth (1583) > clientWidth (1425)` → horizontal scrollbar. Root cause: a `position: fixed` decorative petal (`.petal-anim`) extends past the right viewport edge and is not clipped (fixed elements ignore ancestor `overflow-hidden`).

**Files:**
- Modify: `resources/js/components/layout/StorefrontLayout.vue` (root wrapper — holds the `fixed` ambient petals) and/or `resources/js/pages/Storefront/HomePage.vue` (`absolute` petal placement).
- Test: `tests/e2e/storefront-no-hscroll.spec.ts` (new)

**Approach:** Add `overflow-x: clip` to the storefront root element so stray decorative absolutely/fixed-positioned petals cannot create horizontal scroll. Keep decorative petals inside a clipping wrapper rather than fixed-to-viewport where feasible. Verify the hero mosaic (already `overflow-hidden`) is unaffected. Do NOT touch the kawaii flower faces (intentional brand tone).

**Test:** Navigate to `/` on the tenant subdomain at 1440 and 375; assert `document.documentElement.scrollWidth === clientWidth` (no horizontal scroll) at both widths.

## Unit U3 — POS cart line-item name truncation

**Problem:** In `PosCartLine.vue` (desktop cart panel) product names are truncated to 3-4 characters ("Puls...", "Port..."), so the cashier can't read what is being charged.

**Files:**
- Modify: `resources/js/components/Admin/Pos/PosCartLine.vue` (and `PosCartPanel.vue` if the column widths live there)
- Test: `tests/e2e/pos-cart-name.spec.ts` (new)

**Approach:** Rework the cart line layout so the name column gets the flexible width and wraps to a 2-line clamp (`line-clamp-2`) instead of single-line truncate. Keep qty stepper and price on their own fixed columns. Ensure the variant/subtitle line stays legible. Verify at the 380px desktop cart width.

**Test:** Add a product with a long name to the cart; assert the rendered cart line text contains the full product name (not an ellipsis-truncated fragment).

## Unit U4 — Unified product-image placeholder

**Problem:** Product image fallback is inconsistent: `PosProductTile.vue` falls back to a plain gradient; the storefront product card falls back to a flower illustration. No shared component.

**Files:**
- Create: `resources/js/components/base/ProductImage.vue`
- Modify: `resources/js/components/Admin/Pos/PosProductTile.vue`, `resources/js/pages/Storefront/HomePage.vue` (product card), `resources/js/pages/Storefront/ProductsListPage.vue`, `resources/js/pages/Storefront/ProductDetailPage.vue`
- Test: `tests/e2e/product-image-fallback.spec.ts` (new) + component-level assertion

**Approach:** `ProductImage` takes `src?: string`, `alt: string`, and an aspect ratio. When `src` is present it renders the `<img>` with `object-cover` + `loading="lazy"`; when absent it renders ONE consistent themed fallback — the existing flower illustration (`Petal`/`Surrogate`), keeping the kawaii face (brand decision). Replace the ad-hoc fallbacks in POS and storefront with this component so both surfaces match.

**Test:** Render a product with no image in POS and in storefront; assert both show the same fallback component (same test id), not a gradient in one and an illustration in the other.

## Unit U5 — Dashboard sales-chart empty state

**Problem:** `SalesLineChart.vue` draws an empty axis grid when there is no sales data in the selected range, while the sibling "Más vendidos" panel shows a proper empty-state message. Inconsistent.

**Files:**
- Modify: `resources/js/components/composite/SalesLineChart.vue`
- Test: `tests/e2e/dashboard-chart-empty.spec.ts` (new)

**Approach:** When the series total is 0 / no datapoints in range, render an empty-state message ("Sin ventas en este periodo") centered in the chart area instead of the bare grid. Match the empty-state styling used by the "Más vendidos" panel for consistency.

**Test:** On a tenant/range with no sales, assert the chart region shows the empty-state text and not an SVG polyline.

## Unit U6 — Spanish accent (tilde) sweep

**Problem:** UI copy has inconsistent accents ("administracion" vs "mínimo"/"día") because strings are inline in templates (no vue-i18n). Systemic across the app.

**Files:**
- Modify: Spanish string literals across `resources/js/**/*.vue` (and any `.ts` holding user-facing copy).
- Test: `tests/e2e/copy-accents.spec.ts` (spot-check) — assert known headers render with correct accents.

**Approach:** Sweep all `.vue` templates for Spanish words that are missing their tilde. Build a reference list of the common offenders (administracion→administración, gestion→gestión, catalogo→catálogo, articulo→artículo, numero→número, telefono→teléfono, direccion→dirección, informacion→información, configuracion→configuración, categoria→categoría, credito→crédito, metodo→método, dia→día, etc.) and correct each occurrence in user-facing copy. Do NOT alter: code identifiers, slugs, route names, API keys, test fixtures, or data values — copy strings only. Preserve `ó`/`í`/`á`/`é`/`ú`/`ñ` correctly.

**Test:** Assert the admin topbar/section headers ("Panel de administración", "Gestión") render with correct accents on a representative page.

## Unit U7 — Nits

**Problem:** Two low-severity items.

**Files:**
- Modify: `resources/js/pages/Storefront/HomePage.vue` (category card spacing)
- Modify: `resources/js/pages/Auth/LoginPage.vue` (show-password toggle) + the axios auth-probe caller (silence the pre-auth `/api/v1/me` 401 console error — likely in `useAuth.ts` or the api service)
- Test: `tests/e2e/login-password-toggle.spec.ts` (new)

**Approach:**
1. Category cards: when a category has no description, collapse the empty body so the label sits directly under the image (no large blank gap). Keep card heights consistent via the image + label, not a fixed body height.
2. Login: add a show/hide password toggle (Lucide eye/eye-off) inside the password field. Suppress the expected pre-auth `/api/v1/me` 401 from logging as a console error (treat 401 on the auth-probe as a normal unauthenticated signal, not an error).

**Test:** On `/login`, toggle the password visibility and assert the input `type` switches between `password` and `text`.

---

## Sequencing

Quick wins first, then the high-value systemic one, then the rest:
U2 → U3 → U1 → U4 → U5 → U6 → U7.

Each unit ships independently; none depends on another's code (U4 and U6 both touch HomePage.vue — sequence U4 before U6, or rebase U6 after U4 merges, to avoid a conflict).
