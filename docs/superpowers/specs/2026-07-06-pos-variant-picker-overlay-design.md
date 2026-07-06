# POS Variant Picker Overlay — Design Spec

**Goal:** When a cashier taps a product that has more than one variant in the POS, open a full-screen overlay to choose which variant(s) and how many, then add them all to the cart in one action — replacing today's silent "add `variants[0]`" behavior.

**Architecture:** Frontend-only feature on the Vue 3 SPA (vue-router + Pinia + axios). All data plumbing already exists (`pos` store `addVariant`, cart lines, POS checkout). A new bespoke full-screen overlay component is opened from the existing product-tap handler; on confirm it adds the chosen quantities to the existing POS sale store. No backend, migration, service, or store-shape changes.

**Tech Stack:** Vue 3 `<script setup>`, Tailwind CSS 4, Lucide icons, Ethereal Boutique design tokens (`resources/css/app.css`). Approved UX mockup: full-screen overlay, 2-column on desktop (grouped variant grid + sticky summary aside + gradient CTA), single column + fixed footer on mobile, multi-select with per-variant quantity and a live running total.

## Global Constraints

- NO emojis anywhere (code, UI, comments, commits).
- NO AI co-author / `Co-Authored-By` trailer in commits.
- Own branch off `develop` (`feat/pos-variant-picker`) → PR to `develop`. Never to `main`.
- Playwright e2e runs against `public/build` — run `npm run build` before e2e.
- Design system: No-Line rule (separate with background tiers, not 1px borders), rounded corners `--r-lg`/`--r-xl`/`--r-2xl`, never pure-black text (`var(--on-surface)`), gradient CTA (`var(--gradient)`), dark mode must stay covered, serif (`Noto Serif`) for product name, sans (`Plus Jakarta Sans`) for UI. Spanish copy with correct accents.
- It is an **overlay** (full-screen, teleported, scrim + blur), NOT an `AppSlideover` side panel and NOT the centered max-width `AppModal`.
- Reuse existing data plumbing: do not add store actions or services unless a listed task explicitly requires it.

## Behavior summary

- Tap a product with **0 or 1 variant** → add instantly to the sale (today's behavior, unchanged).
- Tap a product with **2+ variants** → open the overlay.
- In the overlay: variants shown as cards, **grouped by their first option axis** (e.g. size), each with label, price, stock chip, and a 0..`available_quantity` quantity stepper. Out-of-stock variants are disabled.
- A sticky summary (desktop aside / mobile footer) shows the chosen lines, a running total, and one **"Agregar al carrito · $X"** CTA (disabled until at least one unit is chosen).
- Confirm → each chosen variant is added with its quantity to the sale, the overlay closes. Cancel/close/ESC → nothing is added.

---

## File structure

- **Create** `resources/js/components/Admin/Pos/PosVariantPickerOverlay.vue` — the overlay (presentation + local selection state). One responsibility: let the user pick variant quantities for one product and emit the result.
- **Create** `resources/js/composables/pos/useVariantGrouping.ts` — pure helper `groupVariantsByFirstOption(variants)` returning `[{ label, variants }]`. Isolated so it is unit-testable and reusable.
- **Modify** `resources/js/pages/Admin/POSPage.vue` — branch `handleSelectProduct` on variant count; render the overlay; handle its `confirm` event.
- **Test** `tests/e2e/admin/pos/pos-variant-picker.spec.ts` — new e2e.
- **Test** `tests/Unit/...` is not applicable (frontend); grouping helper is covered indirectly by the e2e. (No PHP changes.)

## Component: PosVariantPickerOverlay.vue

**Interfaces**
- Consumes (props):
  - `show: boolean`
  - `product: PosProduct | null` (from `@/types/domain/POS`; `variants: PosProductVariant[]`, each with `id`, `options: Record<string,string>`, `price_cents`, `available_quantity`, `image_url`).
  - `formatCents: (cents: number) => string` (same formatter POS already passes to tiles).
- Emits:
  - `close` — user dismissed (X, scrim, ESC, Cancel).
  - `confirm` — payload `Array<{ variant: PosProductVariant; quantity: number }>` (only variants with quantity > 0).

**Structure** (mirrors the approved mockup):
- Teleport to `body`; `role="dialog" aria-modal="true"`, labelled by the product name. Scrim `var(--scrim-equivalent)` with `backdrop-filter: blur`. ESC closes (via a keydown listener added on mount, removed on unmount). Focus moves into the panel on open; scrim click closes.
- Header: eyebrow "Punto de venta · Elegí variante", product name (serif), optional description/subtitle, close button.
- Body grid: `grid-template-columns: 1fr` below `860px`, `1.55fr 1fr` at/above.
  - Left (`overflow-y:auto`): one `<section>` per group from `groupVariantsByFirstOption`. Group header = option-value label + "Elegí cantidades" hint. Cards grid (`repeat(auto-fill, minmax(210px,1fr))`). Each card: variant option label (`Object.values(options).join(' · ')`), price, stock chip (`Sin stock` when 0, `Quedan N` when `<= 3` using error tokens, `N disp.` otherwise), and a stepper (`−  qty  +`). Out-of-stock card: dimmed, stepper replaced by "No disponible". Stepper `+` disabled at `available_quantity`.
  - Right (desktop) sticky summary / (mobile) fixed footer: "Tu selección" title, per-line rows (`option label`, `q × price`, line total), running Subtotal, gradient CTA `Agregar al carrito · {{ formatCents(total) }}`, and a "Vaciar selección" text button (desktop only). Empty state when nothing chosen; CTA disabled.
- Local state: `qty: Record<number, number>` keyed by variant id. `total` and `count` computed. No external store writes — the component is pure until `confirm`.
- Reset local `qty` whenever `show` transitions to `true` (fresh selection per open).

**Grouping helper** `useVariantGrouping.ts`:
```ts
import type { PosProductVariant } from '@/types/domain/POS'

export interface VariantGroup { label: string; variants: PosProductVariant[] }

/**
 * Group variants by the value of their FIRST option axis (e.g. size), preserving
 * variant order. Variants with no options fall into a single unlabeled group.
 */
export function groupVariantsByFirstOption(variants: PosProductVariant[]): VariantGroup[] {
    const order: string[] = []
    const map = new Map<string, PosProductVariant[]>()
    for (const v of variants) {
        const label = Object.values(v.options ?? {})[0] ?? ''
        if (!map.has(label)) { map.set(label, []); order.push(label) }
        map.get(label)!.push(v)
    }
    return order.map((label) => ({ label, variants: map.get(label)! }))
}
```

## POSPage.vue wiring

Replace the current handler:
```ts
const variantPickerProduct = ref<PosProduct | null>(null)
const variantPickerOpen = ref(false)

function handleSelectProduct(product: PosProduct): void {
    // 0 or 1 variant: keep the instant-add fast path.
    if (product.variants.length <= 1) {
        const variant = product.variants[0]
        if (variant) store.addVariant(product, variant)
        return
    }
    variantPickerProduct.value = product
    variantPickerOpen.value = true
}

function handleVariantPickerConfirm(
    picks: Array<{ variant: PosProductVariant; quantity: number }>,
): void {
    for (const { variant, quantity } of picks) {
        // addVariant adds one unit and already caps at available_quantity.
        for (let i = 0; i < quantity; i++) store.addVariant(variantPickerProduct.value!, variant)
    }
    variantPickerOpen.value = false
}
```
Render `<PosVariantPickerOverlay :show="variantPickerOpen" :product="variantPickerProduct" :format-cents="formatCents" @close="variantPickerOpen = false" @confirm="handleVariantPickerConfirm" />` near the existing customer-selector overlay.

Note: `store.addVariant` increments an existing line and is capped at `available_quantity`, so looping is safe and needs no store change. (A future `addVariantQty` optimization is out of scope.)

## Data flow

1. `PosProductGrid` emits `select-product` (unchanged).
2. `POSPage.handleSelectProduct` decides: instant-add (≤1 variant) or open overlay (2+).
3. Overlay holds local quantities; on confirm emits the picks.
4. `POSPage.handleVariantPickerConfirm` writes them to the `pos` sale store via existing `addVariant`.
5. Cart panel / bottom bar re-render from the store (existing reactivity).

## Error / edge cases

- **All variants out of stock:** overlay still opens; every card disabled; CTA stays disabled; only close is possible. (The tile itself is already disabled when the default variant is out of stock; multi-variant products with mixed stock are the real case.)
- **Stepper ceiling:** `+` disabled at `available_quantity`; the store's own cap is a second guard.
- **Re-open:** local selection resets each open (no leaked quantities).
- **Single option axis vs none:** grouping helper degenerates to one group; layout still valid.
- **Dark mode:** all tokens theme-driven; verify no white-box leaks.

## Testing (dual-layer)

- **PHPUnit:** none — no backend change. POS checkout with multiple lines is already covered by existing POS feature tests.
- **Playwright e2e** (`tests/e2e/admin/pos/pos-variant-picker.spec.ts`, seeded `rosa-eterna`, subdomain base URL):
  1. Tapping a **multi-variant** product opens the overlay (assert the dialog is visible with the product name).
  2. Setting quantity on two variants updates the running total and enables the CTA.
  3. Confirm adds **two cart lines** with the expected quantities; the overlay closes.
  4. Tapping a **single-variant** product adds instantly with **no** overlay.
  5. An out-of-stock variant's stepper/add control is disabled.
- **Visual QA** (qa-engineer / manual Playwright MCP): overlay on desktop (2-col) + mobile (1-col + fixed footer) + dark mode; verify scrim/blur, No-Line separation, gradient CTA, focus/ESC.

## Out of scope (YAGNI)

- Modifiers, combos, per-item notes, per-item discounts (not in Eternova's domain).
- Editing an existing cart line through the overlay (the cart already has qty steppers + remove).
- Per-variant image thumbnails inside the picker, drag-reorder, and a qty-aware `addVariantQty` store action.
- Any change to product creation/edition variant management (separate, deferred effort).
