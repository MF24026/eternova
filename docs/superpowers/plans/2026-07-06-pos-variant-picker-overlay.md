# POS Variant Picker Overlay — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a cashier taps a product with 2+ variants in the POS, open a full-screen overlay to pick which variant(s) and how many, then add them all to the sale at once.

**Architecture:** Frontend-only. A pure grouping helper + a bespoke full-screen overlay component (teleport + scrim, not `AppSlideover`/`AppModal`) opened from the existing `POSPage.handleSelectProduct`. On confirm it writes to the existing `pos` sale store via `addVariant` (already caps at `available_quantity`). No backend, service, migration, or store-shape change.

**Tech Stack:** Vue 3 `<script setup lang="ts">`, Tailwind CSS 4, Lucide icons, Ethereal Boutique tokens (`resources/css/app.css`). Test layer is Playwright e2e (the repo has no JS unit runner); `vue-tsc` + `npm run build` gate the TS/build.

## Global Constraints

- NO emojis anywhere (code, UI, comments, commits).
- NO AI co-author / `Co-Authored-By` trailer in commits.
- Work on branch `feat/pos-variant-picker` (already created off `develop`); PR later to `develop`, never `main`.
- Playwright e2e runs against the built bundle — run `./vendor/bin/sail npm run build` before e2e.
- Design system: No-Line rule (separate with background tiers via `bg-surface-*` / `var(--surface-*)`, never 1px borders), rounded `--r-lg`/`--r-xl`/`--r-2xl`, text `var(--on-surface)` (never pure black), gradient CTA `var(--gradient)`, dark mode must stay covered, serif for the product name, sans for UI. Spanish copy with correct accents.
- It is a full-screen **overlay** (teleport to body, scrim + blur), NOT a side slideover and NOT the centered `AppModal`.
- Reuse existing plumbing: no new store actions or services.
- Run commands via `./vendor/bin/sail` (Sail). App on tenant subdomain `rosa-eterna.eternova.localhost` inside the container.

## File structure

- Create `resources/js/composables/pos/useVariantGrouping.ts` — pure `groupVariantsByFirstOption(variants)`.
- Create `resources/js/components/Admin/Pos/PosVariantPickerOverlay.vue` — the overlay (presentation + local selection).
- Modify `resources/js/pages/Admin/POSPage.vue` — branch the tap handler; render + wire the overlay.
- Create `tests/e2e/admin/pos/pos-variant-picker.spec.ts` — e2e (the test layer).

Relevant existing types (`resources/js/types/domain/POS.ts`):
```ts
export interface PosProductVariant {
    id: number
    sku: string
    price_cents: number
    options: Record<string, string>
    image_url: string | null
    available_quantity: number
    in_stock: boolean
}
export interface PosProduct {
    id: number
    name: string
    // ...
    base_price_cents: number
    default_image_url: string | null
    variants: PosProductVariant[]
}
```
Existing store action (`resources/js/stores/pos.ts`): `addVariant(product: PosProduct, variant: PosProductVariant): void` — adds one unit, capped at `variant.available_quantity`.

---

### Task 1: Variant grouping helper

**Files:**
- Create: `resources/js/composables/pos/useVariantGrouping.ts`

**Interfaces:**
- Consumes: `PosProductVariant` from `@/types/domain/POS`.
- Produces: `interface VariantGroup { label: string; variants: PosProductVariant[] }` and `groupVariantsByFirstOption(variants: PosProductVariant[]): VariantGroup[]`.

- [ ] **Step 1: Create the helper**

```ts
// resources/js/composables/pos/useVariantGrouping.ts
import type { PosProductVariant } from '@/types/domain/POS'

export interface VariantGroup {
    label: string
    variants: PosProductVariant[]
}

/**
 * Group variants by the value of their FIRST option axis (e.g. size), preserving
 * the original variant order. Variants with no options fall into a single group
 * with an empty label.
 */
export function groupVariantsByFirstOption(variants: PosProductVariant[]): VariantGroup[] {
    const order: string[] = []
    const map = new Map<string, PosProductVariant[]>()

    for (const variant of variants) {
        const label = Object.values(variant.options ?? {})[0] ?? ''
        if (!map.has(label)) {
            map.set(label, [])
            order.push(label)
        }
        map.get(label)!.push(variant)
    }

    return order.map((label) => ({ label, variants: map.get(label)! }))
}
```

- [ ] **Step 2: Typecheck**

Run: `./vendor/bin/sail npx vue-tsc --noEmit`
Expected: exit 0 (no errors).

- [ ] **Step 3: Commit**

```bash
git add resources/js/composables/pos/useVariantGrouping.ts
git commit -m "feat(pos): variant grouping helper for the picker overlay"
```

---

### Task 2: PosVariantPickerOverlay component

**Files:**
- Create: `resources/js/components/Admin/Pos/PosVariantPickerOverlay.vue`

**Interfaces:**
- Consumes: `groupVariantsByFirstOption` (Task 1); `PosProduct`, `PosProductVariant` from `@/types/domain/POS`.
- Produces (used by Task 3):
  - Props: `show: boolean`, `product: PosProduct | null`, `formatCents: (cents: number) => string`.
  - Emits: `close` (no payload); `confirm` with `Array<{ variant: PosProductVariant; quantity: number }>` (only quantity > 0).
  - Test ids: `pos-variant-overlay` (dialog root), `pos-variant-card-{variantId}`, `pos-variant-inc-{variantId}`, `pos-variant-dec-{variantId}`, `pos-variant-qty-{variantId}`, `pos-variant-total`, `pos-variant-confirm`, `pos-variant-close`.

- [ ] **Step 1: Create the component**

```vue
<!-- resources/js/components/Admin/Pos/PosVariantPickerOverlay.vue -->
<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { X, Minus, Plus } from 'lucide-vue-next'
import { groupVariantsByFirstOption } from '@/composables/pos/useVariantGrouping'
import type { PosProduct, PosProductVariant } from '@/types/domain/POS'

const props = defineProps<{
    show: boolean
    product: PosProduct | null
    formatCents: (cents: number) => string
}>()

const emit = defineEmits<{
    close: []
    confirm: [picks: Array<{ variant: PosProductVariant; quantity: number }>]
}>()

// Local selection: variantId -> quantity. Reset on each open.
const qty = ref<Record<number, number>>({})

watch(
    () => props.show,
    (open) => {
        if (open) qty.value = {}
    },
)

const groups = computed(() =>
    props.product ? groupVariantsByFirstOption(props.product.variants) : [],
)

const variantLabel = (v: PosProductVariant): string =>
    Object.values(v.options ?? {}).join(' · ') || v.sku

function stockLabel(v: PosProductVariant): { text: string; low: boolean } {
    if (v.available_quantity <= 0) return { text: 'Sin stock', low: true }
    if (v.available_quantity <= 3) return { text: `Quedan ${v.available_quantity}`, low: true }
    return { text: `${v.available_quantity} disp.`, low: false }
}

function inc(v: PosProductVariant): void {
    const next = Math.min((qty.value[v.id] ?? 0) + 1, v.available_quantity)
    qty.value = { ...qty.value, [v.id]: next }
}

function dec(v: PosProductVariant): void {
    const next = Math.max((qty.value[v.id] ?? 0) - 1, 0)
    const copy = { ...qty.value }
    if (next === 0) delete copy[v.id]
    else copy[v.id] = next
    qty.value = copy
}

function clearAll(): void {
    qty.value = {}
}

const picks = computed(() => {
    if (!props.product) return []
    return props.product.variants
        .filter((v) => (qty.value[v.id] ?? 0) > 0)
        .map((v) => ({ variant: v, quantity: qty.value[v.id] }))
})

const totalCents = computed(() =>
    picks.value.reduce((sum, p) => sum + p.variant.price_cents * p.quantity, 0),
)
const unitCount = computed(() => picks.value.reduce((sum, p) => sum + p.quantity, 0))

function confirm(): void {
    if (picks.value.length === 0) return
    emit('confirm', picks.value)
}

// ESC closes the overlay while it is open.
function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') emit('close')
}
watch(
    () => props.show,
    (open) => {
        if (open) document.addEventListener('keydown', onKeydown)
        else document.removeEventListener('keydown', onKeydown)
    },
)
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show && product"
                class="fixed inset-0 z-[60] grid place-items-center p-0 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="pos-variant-title"
                data-testid="pos-variant-overlay"
            >
                <!-- Scrim -->
                <div
                    class="absolute inset-0"
                    style="background: rgba(61,47,50,.42); backdrop-filter: blur(6px)"
                    @click="emit('close')"
                />

                <!-- Panel -->
                <div
                    class="relative flex flex-col w-full max-w-[1080px] h-[100dvh] sm:h-[min(88dvh,780px)]
                           overflow-hidden bg-surface sm:rounded-[var(--r-2xl)] shadow-[var(--shadow-lifted)]"
                >
                    <!-- Header -->
                    <div class="flex items-start gap-4 px-6 sm:px-8 pt-6 pb-5">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold tracking-[0.16em] uppercase text-primary mb-2">
                                Punto de venta · Elegí variante
                            </p>
                            <h2
                                id="pos-variant-title"
                                class="serif text-2xl sm:text-3xl leading-tight text-on-surface"
                            >
                                {{ product.name }}
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="btn-icon ml-auto shrink-0"
                            aria-label="Cerrar"
                            data-testid="pos-variant-close"
                            @click="emit('close')"
                        >
                            <X :size="20" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="grid grid-cols-1 md:grid-cols-[1.55fr_1fr] min-h-0 flex-1">
                        <!-- Left: grouped variant grid -->
                        <div class="overflow-y-auto px-6 sm:px-8 pb-6">
                            <section
                                v-for="group in groups"
                                :key="group.label"
                                class="mt-1 first:mt-0 [&+section]:mt-6"
                            >
                                <div class="flex items-baseline justify-between gap-3 mb-3">
                                    <h3 class="serif text-lg text-on-surface">
                                        {{ group.label || 'Variantes' }}
                                    </h3>
                                    <span class="text-[11px] tracking-[0.12em] uppercase text-on-surface-variant">
                                        Elegí cantidades
                                    </span>
                                </div>

                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr))">
                                    <div
                                        v-for="v in group.variants"
                                        :key="v.id"
                                        class="flex flex-col gap-3 p-4 rounded-[var(--r-lg)] bg-surface-lowest
                                               shadow-[var(--shadow-ambient)] outline outline-2 transition-[outline-color]"
                                        :class="(qty[v.id] ?? 0) > 0 ? 'outline-primary' : 'outline-transparent'"
                                        :style="v.available_quantity <= 0 ? 'opacity:.55' : ''"
                                        :data-testid="`pos-variant-card-${v.id}`"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="font-semibold text-sm text-on-surface truncate">
                                                    {{ variantLabel(v) }}
                                                </p>
                                            </div>
                                            <span class="font-bold text-primary tabular-nums whitespace-nowrap">
                                                {{ formatCents(v.price_cents) }}
                                            </span>
                                        </div>

                                        <span
                                            class="self-start text-[11px] font-semibold px-2.5 py-0.5 rounded-full"
                                            :style="stockLabel(v).low
                                                ? 'background: var(--error-container); color: var(--error)'
                                                : 'background: var(--secondary-container); color: var(--secondary)'"
                                        >
                                            {{ stockLabel(v).text }}
                                        </span>

                                        <div class="flex items-center justify-between">
                                            <template v-if="v.available_quantity > 0">
                                                <div
                                                    class="inline-flex items-center gap-0.5 rounded-full p-0.5"
                                                    style="background: var(--surface-mid)"
                                                >
                                                    <button
                                                        type="button"
                                                        class="w-8 h-8 grid place-items-center rounded-full transition-colors hover:bg-surface-high disabled:opacity-30"
                                                        :disabled="(qty[v.id] ?? 0) === 0"
                                                        :aria-label="`Quitar uno de ${variantLabel(v)}`"
                                                        :data-testid="`pos-variant-dec-${v.id}`"
                                                        @click="dec(v)"
                                                    >
                                                        <Minus :size="14" />
                                                    </button>
                                                    <span
                                                        class="min-w-[26px] text-center font-bold tabular-nums text-on-surface"
                                                        :data-testid="`pos-variant-qty-${v.id}`"
                                                    >
                                                        {{ qty[v.id] ?? 0 }}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        class="w-8 h-8 grid place-items-center rounded-full transition-colors hover:bg-surface-high disabled:opacity-30"
                                                        :disabled="(qty[v.id] ?? 0) >= v.available_quantity"
                                                        :aria-label="`Agregar uno de ${variantLabel(v)}`"
                                                        :data-testid="`pos-variant-inc-${v.id}`"
                                                        @click="inc(v)"
                                                    >
                                                        <Plus :size="14" />
                                                    </button>
                                                </div>
                                            </template>
                                            <span v-else class="text-sm text-on-surface-variant">No disponible</span>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <!-- Right: summary + CTA -->
                        <aside
                            class="flex flex-col min-h-0 bg-surface-low md:bg-surface-low
                                   max-md:shadow-[0_-12px_32px_rgba(61,47,50,0.08)]"
                        >
                            <div class="hidden md:block overflow-y-auto px-6 pt-6 pb-2 flex-1">
                                <h3 class="serif text-xl text-on-surface mb-1">Tu selección</h3>
                                <p class="text-sm text-on-surface-variant mb-4">
                                    <template v-if="unitCount === 0">Todavía no elegiste ninguna variante.</template>
                                    <template v-else>
                                        {{ unitCount }} {{ unitCount === 1 ? 'unidad' : 'unidades' }} ·
                                        {{ picks.length }} {{ picks.length === 1 ? 'variante' : 'variantes' }}
                                    </template>
                                </p>

                                <div v-if="picks.length === 0" class="text-center text-sm text-on-surface-variant py-6">
                                    <span class="block font-semibold text-on-surface mb-1">Sin variantes elegidas</span>
                                    Tocá una tarjeta para sumarla a la venta.
                                </div>
                                <div v-else class="flex flex-col gap-2.5">
                                    <div
                                        v-for="p in picks"
                                        :key="p.variant.id"
                                        class="flex items-center gap-3 p-3 rounded-[var(--r-md)] bg-surface-lowest shadow-[var(--shadow-ambient)]"
                                    >
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-sm text-on-surface truncate">{{ variantLabel(p.variant) }}</p>
                                            <p class="text-xs text-on-surface-variant">
                                                {{ p.quantity }} × {{ formatCents(p.variant.price_cents) }}
                                            </p>
                                        </div>
                                        <span class="font-bold tabular-nums text-on-surface">
                                            {{ formatCents(p.variant.price_cents * p.quantity) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3.5 px-6 pt-4 pb-6 max-md:flex-row max-md:items-center max-md:pb-[calc(1rem+env(safe-area-inset-bottom))]">
                                <div class="flex items-baseline justify-between max-md:flex-col max-md:items-start">
                                    <span class="serif text-lg text-on-surface max-md:text-sm max-md:text-on-surface-variant">Total</span>
                                    <span class="serif text-3xl font-semibold tabular-nums text-on-surface max-md:text-2xl" data-testid="pos-variant-total">
                                        {{ formatCents(totalCents) }}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    class="btn-primary w-full max-md:w-auto max-md:flex-1 justify-center disabled:opacity-45"
                                    :disabled="picks.length === 0"
                                    data-testid="pos-variant-confirm"
                                    @click="confirm"
                                >
                                    Agregar al carrito<template v-if="totalCents > 0"> · {{ formatCents(totalCents) }}</template>
                                </button>
                                <button
                                    v-if="picks.length > 0"
                                    type="button"
                                    class="hidden md:block text-sm text-on-surface-variant hover:text-primary self-center"
                                    @click="clearAll"
                                >
                                    Vaciar selección
                                </button>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
```

- [ ] **Step 2: Typecheck**

Run: `./vendor/bin/sail npx vue-tsc --noEmit`
Expected: exit 0.

- [ ] **Step 3: Build**

Run: `./vendor/bin/sail npm run build`
Expected: `✓ built` with no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/Admin/Pos/PosVariantPickerOverlay.vue
git commit -m "feat(pos): variant picker overlay component"
```

---

### Task 3: Wire the overlay into POSPage

**Files:**
- Modify: `resources/js/pages/Admin/POSPage.vue`

**Interfaces:**
- Consumes: `PosVariantPickerOverlay` (Task 2) props/emits; existing `store.addVariant`, `formatCents`, `PosProduct`/`PosProductVariant` types already imported/used in POSPage.

- [ ] **Step 1: Import the overlay**

Add to the existing `<script setup>` imports (near the other `@/components/Admin/Pos/...` imports):
```ts
import PosVariantPickerOverlay from '@/components/Admin/Pos/PosVariantPickerOverlay.vue'
import type { PosProduct, PosProductVariant } from '@/types/domain/POS'
```
(If `PosProduct` is already imported in POSPage, extend that import to include `PosProductVariant` instead of duplicating.)

- [ ] **Step 2: Add overlay state + replace the tap handler**

Replace the existing `handleSelectProduct` (currently:
```ts
function handleSelectProduct(product: PosProduct): void {
    const variant = product.variants[0]
    if (!variant) return
    store.addVariant(product, variant)
}
```
) with:
```ts
const variantPickerOpen = ref(false)
const variantPickerProduct = ref<PosProduct | null>(null)

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
    const product = variantPickerProduct.value
    if (!product) return
    for (const { variant, quantity } of picks) {
        // addVariant adds one unit and already caps at available_quantity.
        for (let i = 0; i < quantity; i++) store.addVariant(product, variant)
    }
    variantPickerOpen.value = false
}
```
(`ref` is already imported in POSPage; if not, add it to the `vue` import.)

- [ ] **Step 3: Render the overlay in the template**

Add near the existing customer-selector overlay in the template (e.g. right after the `<PosCustomerSelector ... />` / customer selector block):
```html
    <PosVariantPickerOverlay
        :show="variantPickerOpen"
        :product="variantPickerProduct"
        :format-cents="formatCents"
        @close="variantPickerOpen = false"
        @confirm="handleVariantPickerConfirm"
    />
```

- [ ] **Step 4: Typecheck + build**

Run: `./vendor/bin/sail npx vue-tsc --noEmit && ./vendor/bin/sail npm run build`
Expected: tsc exit 0; `✓ built`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Admin/POSPage.vue
git commit -m "feat(pos): open variant picker for multi-variant products"
```

---

### Task 4: E2E coverage + visual QA

**Files:**
- Create: `tests/e2e/admin/pos/pos-variant-picker.spec.ts`

**Interfaces:**
- Consumes: the test ids from Task 2 (`pos-variant-overlay`, `pos-variant-inc-{id}`, `pos-variant-qty-{id}`, `pos-variant-total`, `pos-variant-confirm`) and the existing POS tile `aria-label` `Agregar {name} al carrito`, plus the existing cart line testid `pos-cart-line-name` (from `PosCartLine.vue`).

- [ ] **Step 1: Write the e2e spec**

```ts
// tests/e2e/admin/pos/pos-variant-picker.spec.ts
import { test, expect, type Page } from '@playwright/test'

/**
 * POS variant picker overlay — tapping a multi-variant product opens a
 * full-screen overlay to choose variant quantities; single-variant products add
 * instantly. Runs against the seeded rosa-eterna tenant on its subdomain (only
 * Chromium resolves *.eternova.localhost).
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('#password', OWNER.password)
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function openPos(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/pos`)
    await page.waitForSelector('.product-tile', { timeout: 15_000 })
}

interface ApiProduct {
    name: string
    variants: Array<{ id: number; available_quantity: number }>
}

/** Load POS products via an in-page fetch (Node cannot resolve the subdomain). */
async function loadProducts(page: Page): Promise<ApiProduct[]> {
    return page.evaluate(async () => {
        const b = await fetch('/api/v1/branches', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        const branches = (await b.json()).data as Array<{ id: string; is_main: boolean }>
        const branchId = (branches.find((x) => x.is_main) ?? branches[0]).id
        const r = await fetch(`/api/v1/pos/products?branch_id=${branchId}&per_page=100`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        return (await r.json()).data
    })
}

test.describe('POS variant picker (seeded tenant)', () => {
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'covered on desktop; mobile in QA')
    })

    test('multi-variant product opens the overlay and adds the chosen variants', async ({ page }) => {
        await login(page)
        const products = await loadProducts(page)
        const multi = products.find(
            (p) => p.variants.filter((v) => v.available_quantity > 0).length >= 2,
        )
        expect(multi, 'a seeded product with 2+ in-stock variants').toBeTruthy()

        await openPos(page)
        await page.getByRole('button', { name: `Agregar ${multi!.name} al carrito` }).first().click()

        const overlay = page.locator('[data-testid="pos-variant-overlay"]')
        await expect(overlay).toBeVisible()

        const inStock = multi!.variants.filter((v) => v.available_quantity > 0).slice(0, 2)
        for (const v of inStock) {
            await overlay.locator(`[data-testid="pos-variant-inc-${v.id}"]`).click()
            await expect(overlay.locator(`[data-testid="pos-variant-qty-${v.id}"]`)).toHaveText('1')
        }

        // Total is no longer $0 and the CTA is enabled.
        await expect(overlay.locator('[data-testid="pos-variant-total"]')).not.toHaveText('$0.00')
        const confirm = overlay.locator('[data-testid="pos-variant-confirm"]')
        await expect(confirm).toBeEnabled()
        await confirm.click()

        // Overlay closes and the cart shows the two chosen variant lines.
        await expect(overlay).toBeHidden()
        await expect(page.locator('[data-testid="pos-cart-line-name"]')).toHaveCount(2)
    })

    test('single-variant product adds instantly with no overlay', async ({ page }) => {
        await login(page)
        const products = await loadProducts(page)
        const single = products.find(
            (p) => p.variants.length === 1 && p.variants[0].available_quantity > 0,
        )
        expect(single, 'a seeded single-variant in-stock product').toBeTruthy()

        await openPos(page)
        await page.getByRole('button', { name: `Agregar ${single!.name} al carrito` }).first().click()

        await expect(page.locator('[data-testid="pos-variant-overlay"]')).toHaveCount(0)
        await expect(page.locator('[data-testid="pos-cart-line-name"]')).toHaveCount(1)
    })
})
```

- [ ] **Step 2: Build then run the spec (expect PASS after Tasks 1-3)**

Run:
```bash
./vendor/bin/sail npm run build
./vendor/bin/sail npm run test:e2e -- tests/e2e/admin/pos/pos-variant-picker.spec.ts --project=chromium-desktop
```
Expected: `2 passed`. (If the seed lacks a single-variant or 2+-variant product, adjust the selection predicates to the seeded catalog — but the rosa-eterna demo catalog has both.)

- [ ] **Step 3: Visual QA (manual, Playwright MCP or qa-engineer)**

Verify on the running app (`http://rosa-eterna.eternova.localhost:8080`): overlay on desktop (2-column, sticky summary), mobile 375px (single column + fixed footer + safe-area), and dark mode (no white-box leaks, gradient CTA legible, scrim/blur present). Confirm ESC and scrim-click close without adding to the cart.

- [ ] **Step 4: Commit**

```bash
git add tests/e2e/admin/pos/pos-variant-picker.spec.ts
git commit -m "test(pos): e2e for the variant picker overlay"
```

---

## Self-review notes

- **Spec coverage:** trigger split (Task 3), overlay UX + grouping + stock chips + steppers + summary + CTA (Task 2), grouping helper (Task 1), e2e for all five spec test cases condensed into two robust flows + single-variant fast path (Task 4). Out-of-scope items are not implemented.
- **Type consistency:** `groupVariantsByFirstOption` / `VariantGroup` (Task 1) used verbatim in Task 2; `confirm` payload shape `Array<{ variant, quantity }>` identical in Tasks 2 and 3; `addVariant(product, variant)` matches the existing store.
- **No backend:** no PHP, migration, or route changes — Pint/PHPUnit/Scribe are unaffected; the CI gate is `vue-tsc` + build (run per task).
