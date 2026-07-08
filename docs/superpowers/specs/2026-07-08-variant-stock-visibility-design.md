# Per-variant stock visibility in the variants overlay — Design

**Date:** 2026-07-08
**Status:** Approved (brainstorming)
**Branch:** `feat/variant-stock-visibility`

## Goal

Let an admin see, at a glance, the available stock of each product variant from
the `ProductVariantsOverlay`, scoped to a specific branch via a selector, so they
can spot out-of-stock or low variants without leaving the product editor.

## Problem framing

The variants overlay is a **tenant-wide, branch-agnostic** admin view (the product
editor has no branch context). Stock, however, is inherently **per-branch**
(`branch_inventory`, with inter-branch transfers modelled). A single unlabelled
number lies by omission:

- A **sum across branches** makes the viewer assume that quantity is on hand at
  their location, when it is spread across sedes (the concern that drove this design).
- **Main-branch-only** understates for multi-branch and hides which branch it is.

Users are **not** tied to a branch (`tenant_users` = one tenant-wide role per user;
no branch axis). A tenant can have N branches, and the POS already operates with an
"active branch". So multi-branch is a real, modelled scenario, and the honest UI is
to make the branch **explicit**.

## Decision

Show stock **per branch, selected via a branch picker** in the overlay header,
defaulting to the main branch. The picker is **hidden when the tenant has a single
branch** (the number is then unambiguous on its own). Stock is **read-only** here —
mutating stock is the Inventory module's job (entries/exits/adjustments with a reason
and audit trail). A future "adjust" shortcut into the Inventory flow is out of scope.

## Architecture & data flow

The overlay already consumes `GET /api/v1/products/{id}` (`ProductResource` with its
`variants`). We embed **all-branch** availability per variant in that payload and let
the selector filter **client-side** — for one product this is few rows
(V variants × B branches; typically ≤ ~10), so the second round-trip a dedicated
stock endpoint would need is not worth it, and switching branches becomes instant.

Availability shown is `available` (= `quantity - reserved`, the sellable figure),
matching the POS. Not `quantity` (on hand).

### Backend

- **`app/Modules/Catalog/Models/ProductVariant.php`** — add relation:
  ```php
  /** @return HasMany<BranchInventory, $this> */
  public function branchInventory(): HasMany
  {
      return $this->hasMany(BranchInventory::class, 'product_variant_id');
  }
  ```
- **`app/Modules/Catalog/Repositories/EloquentProductRepository.php`
  (`findWithRelations`)** — eager-load `'variants.branchInventory'`. `BranchInventory`
  uses `BelongsToTenant`, so the rows are tenant-scoped automatically; no N+1.
- **`app/Modules/Catalog/Http/Resources/ProductVariantResource.php`** — expose, only
  when the relation is loaded (single-variant add/update responses do not load it, so
  they stay unchanged):
  ```php
  'min_stock_alert' => $variant->min_stock_alert,
  'available_by_branch' => $this->when(
      $variant->relationLoaded('branchInventory'),
      static fn () => $variant->branchInventory
          ->mapWithKeys(static fn ($bi) => [(string) $bi->branch_id => (int) $bi->available]),
  ),
  ```
  The resource stays thin — no status derivation server-side (frontend presenter owns
  the "Agotado"/"Bajo" copy, consistent with the notifications presenter pattern).

### Frontend

- **`resources/js/types/domain/Product.ts`** — `ProductVariant` gains:
  ```ts
  min_stock_alert?: number | null
  available_by_branch?: Record<string, number>
  ```
- **`resources/js/components/Admin/Products/ProductVariantsOverlay.vue`**:
  - On open, `useBranches().loadBranches()` (existing composable, cached). Keep a
    `selectedBranchId` ref, defaulted to the main branch (`is_main`) or the first
    branch. Reset on open.
  - Branch selector in the header, rendered **only when `branches.length > 1`**.
    `data-testid="pv-branch-select"`, a native `<select>` styled `.field`.
  - New stock column per row (`data-testid="pv-stock-{id}"`):
    - `available = variant.available_by_branch?.[selectedBranchId] ?? 0`
    - status: `out` if `available <= 0`; `low` if `min_stock_alert && available <= min_stock_alert`;
      else `ok`.
    - render: the number, plus a badge — red **"Agotado"** for `out`, amber **"Bajo"**
      for `low`, nothing for `ok`. Badges use design tokens (`--error` for out; an amber
      warning tone for low). No new hard 1px borders (No-Line rule).
  - Row grid becomes `sm:grid-cols-[1fr_100px_110px_90px_auto_auto]`
    (label, SKU, price, stock, toggle, actions).

## Testing (dual-layer)

- **PHPUnit `tests/Feature/Catalog/VariantStockTest.php`**: create a tenant with two
  branches; one variant; record entries of different sizes per branch and one reserve;
  `GET /api/v1/products/{id}` → assert `available_by_branch` carries the right
  `available` per branch id and that `reserved` reduces it. Assert a single-variant
  `PATCH .../variants/{id}` response omits `available_by_branch` (relation not loaded).
  Uses `ActingAsTenantMember` + `RefreshDatabase` + `tenantUrl`.
- **e2e `tests/e2e/admin/catalog/product-variants-manage.spec.ts`** (extend): after the
  overlay opens on a seeded option product, assert `pv-stock-{id}` renders for an existing
  variant and shows a numeric value. If the seed exposes >1 branch, changing
  `pv-branch-select` updates a stock cell; otherwise assert the selector is absent
  (single-branch seed) and the number renders. Leaves demo data as-is.

## Out of scope (YAGNI)

- Editing / adjusting stock from this overlay (belongs to Inventory).
- Showing `reserved` / on-hand columns — just `available` + status.
- A dedicated per-branch stock endpoint (client-side filter suffices at this scale).
- Realtime stock updates.

## Global constraints

- No emojis anywhere (code, UI, comments, commits). No AI co-author trailer.
- Feature branch → PR against `develop` (never `main`); squash-merge.
- Multi-tenant: all reads tenant-scoped; do not leak cross-tenant stock.
- Design system Ethereal Boutique: tokens only, No-Line rule, Lucide icons, dark mode.
- Dual-layer testing (PHPUnit + Playwright) before the PR closes; visual QA in light + dark.
