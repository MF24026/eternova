<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import PosProductGrid from '@/components/Admin/Pos/PosProductGrid.vue'
import PosCartPanel from '@/components/Admin/Pos/PosCartPanel.vue'
import PosCartBottomBar from '@/components/Admin/Pos/PosCartBottomBar.vue'
import PosCustomerSelector from '@/components/Admin/Pos/PosCustomerSelector.vue'
import PosReceiptSlideover from '@/components/Admin/Pos/PosReceiptSlideover.vue'
import PosVariantPickerOverlay from '@/components/Admin/Pos/PosVariantPickerOverlay.vue'
import PosCheckoutOverlay from '@/components/Admin/Pos/PosCheckoutOverlay.vue'
import CashRegisterOverlay from '@/components/Admin/Pos/CashRegisterOverlay.vue'
import AppSlideover from '@/components/base/AppSlideover.vue'
import { LockOpen, Lock } from 'lucide-vue-next'
import { usePosStore } from '@/stores/pos'
import { useBranches } from '@/composables/useBranches'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useToast } from '@/composables/useToast'
import { useCashRegister } from '@/composables/useCashRegister'
import { useAuth } from '@/composables/useAuth'
import PosService from '@/services/PosService'
import type { PosProduct, PosProductVariant, PosProductCategory, PosPaymentMethod, PosReceipt } from '@/types/domain/POS'
import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'
import { computed } from 'vue'

onMounted(async () => {
    document.title = 'Punto de venta — Eternova'
    await loadBranches()
    resolveDefaultBranch()
    void loadProducts()
    if (cashRegisterEnabled.value) void cashRegister.refresh(store.branchId)
})

const store = usePosStore()
const { branches, isLoading: branchesLoading, loadBranches } = useBranches()
const { formatCents } = useFormatCurrency()
const toast = useToast()

// ── Cash register (arqueo) — only when the tenant's giro enables the module ─────
const { currentUser } = useAuth()
const cashRegisterEnabled = computed(() =>
    (currentUser.value?.tenants.find((t) => t.is_current)?.enabled_modules ?? []).includes('cash_register'),
)
const cashRegister = useCashRegister()
const crOverlayOpen = ref(false)
const crMode = ref<'open' | 'close'>('open')
const crSubmitting = ref(false)

function openCashRegisterFlow(): void {
    crMode.value = cashRegister.session.value ? 'close' : 'open'
    crOverlayOpen.value = true
}

async function handleCashRegisterConfirm(payload: { amountCents: number; notes: string }): Promise<void> {
    crSubmitting.value = true
    try {
        if (crMode.value === 'open') {
            await cashRegister.open(store.branchId, payload.amountCents, payload.notes)
            toast.success('Caja abierta')
        } else {
            const closed = await cashRegister.close(payload.amountCents, payload.notes)
            const diff = closed?.difference_cents ?? 0
            toast.success(diff === 0 ? 'Caja cerrada: cuadrada' : `Caja cerrada: ${diff > 0 ? 'sobrante' : 'faltante'} ${formatCents(Math.abs(diff))}`)
        }
        crOverlayOpen.value = false
    } catch {
        toast.error('No se pudo completar la operación de caja.')
    } finally {
        crSubmitting.value = false
    }
}

watch(() => store.branchId, (id) => {
    if (cashRegisterEnabled.value && id) void cashRegister.refresh(id)
})

// ── Branch resolution ──────────────────────────────────────────────────────────

function resolveDefaultBranch(): void {
    // Wait until branches have loaded — validating against an empty list would
    // wrongly discard a valid persisted branchId before the data arrives.
    if (branches.value.length === 0) return

    // Keep the persisted branchId only if it still belongs to this tenant. A stale
    // id (branch removed, or the dev DB re-seeded with new ULIDs) would otherwise
    // 500 the products endpoint — discard it and fall back to the main/first branch.
    const persistedIsValid = store.branchId !== ''
        && branches.value.some((b) => b.id === store.branchId)
    if (persistedIsValid) return

    const main = branches.value.find((b) => b.is_main)
    const first = branches.value[0]
    const resolved = main ?? first
    if (resolved) {
        store.branchId = resolved.id
    }
}

// If branches load after mount (race), resolve once they arrive. The function
// self-guards (empty list / already-valid), so it is safe to call unconditionally.
watch(branches, () => {
    resolveDefaultBranch()
})

// ── Product loading ────────────────────────────────────────────────────────────

const products = ref<PosProduct[]>([])
const categories = ref<PosProductCategory[]>([])
const isLoadingProducts = ref(false)
const searchQuery = ref('')
const activeCategory = ref('all')

let searchDebounce: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchDebounce) clearTimeout(searchDebounce)
    searchDebounce = setTimeout(() => { void loadProducts() }, 350)
})

watch(activeCategory, () => { void loadProducts() })

async function loadProducts(page = 1): Promise<void> {
    if (!store.branchId) return

    isLoadingProducts.value = true

    try {
        const result = await PosService.products({
            branch_id: store.branchId,
            search: searchQuery.value.trim() || undefined,
            category_slug: activeCategory.value !== 'all' ? activeCategory.value : undefined,
            per_page: 40,
            page,
        })

        products.value = result.data
        store.setTaxConfig(result.tax)

        // Derive categories from the loaded products — deduped by slug.
        if (page === 1 && activeCategory.value === 'all' && !searchQuery.value) {
            categories.value = deduplicateCategories(
                result.data.flatMap((p) => p.categories),
            )
        }
    } catch {
        toast.error('No se pudieron cargar los productos')
        products.value = []
    } finally {
        isLoadingProducts.value = false
    }
}

function deduplicateCategories(cats: PosProductCategory[]): PosProductCategory[] {
    const seen = new Set<string>()
    return cats.filter((c) => {
        if (seen.has(c.slug)) return false
        seen.add(c.slug)
        return true
    })
}

// ── Checkout + receipt ────────────────────────────────────────────────────────

const isSubmitting = ref(false)
const receiptData = ref<PosReceipt | null>(null)
const receiptOpen = ref(false)

const checkoutOverlayOpen = ref(false)

// The "Cobrar" button opens the checkout overlay (method + cash tendered + change);
// the actual sale is submitted from the overlay's confirm.
function openCheckout(): void {
    if (store.isEmpty || isSubmitting.value) return
    checkoutOverlayOpen.value = true
}

async function submitCheckout(amountReceivedCents: number | null): Promise<void> {
    if (store.isEmpty || isSubmitting.value) return

    isSubmitting.value = true

    try {
        const result = await PosService.checkout({
            branch_id: store.branchId,
            items: store.lines.map((l) => ({
                product_variant_id: l.variantId,
                quantity: l.quantity,
            })),
            payment_method: store.paymentMethod,
            amount_received_cents: amountReceivedCents,
            customer_id: store.customerId ?? undefined,
            notes: store.notes || undefined,
        })

        checkoutOverlayOpen.value = false

        // The sale is committed at this point. Clear the cart and refresh stock
        // immediately so a subsequent receipt-fetch failure can never leave the
        // cashier with a full cart that invites a duplicate checkout.
        store.clear()
        void loadProducts()

        // Close the mobile cart sheet before opening the receipt.
        mobileCartOpen.value = false

        // Fetch the richer receipt for display/printing. If this fails the sale
        // still went through, so we surface a soft message rather than a checkout
        // error.
        try {
            const receiptResponse = await PosService.receipt(result.data.id)
            receiptData.value = receiptResponse.data
            receiptOpen.value = true
        } catch {
            toast.success(`Venta #${result.data.order_number} registrada. No se pudo cargar el recibo.`)
        }
    } catch (err) {
        const axiosErr = err as AxiosError<ApiErrorResponse>
        const status = axiosErr.response?.status
        const apiMessage = axiosErr.response?.data?.message

        if (status === 422 && apiMessage) {
            // Insufficient stock — keep the cart so the cashier can adjust qty.
            toast.error(apiMessage)
        } else {
            toast.error('Ocurrio un error al procesar la venta. Intenta de nuevo.')
        }
    } finally {
        isSubmitting.value = false
    }
}

function handleNewSale(): void {
    receiptData.value = null
    receiptOpen.value = false
}

// ── Cart event handlers ───────────────────────────────────────────────────────

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

function handlePaymentMethodChange(method: PosPaymentMethod): void {
    store.setPaymentMethod(method)
}

// ── Mobile cart sheet ─────────────────────────────────────────────────────────

const mobileCartOpen = ref(false)

// ── Customer selector ─────────────────────────────────────────────────────────

const customerSelectorOpen = ref(false)
const selectedCustomerName = ref<string | null>(null)

function handleSelectCustomer(id: number | null, name: string | null): void {
    store.setCustomer(id)
    selectedCustomerName.value = name
}
</script>

<template>
    <!--
        Layout strategy:
        - Mobile  (< 768px): full-width product grid + floating bottom bar + mobile cart sheet
        - Tablet  (768–1023px): same mobile pattern — bottom-bar cart keeps things intentional
          on a portrait tablet; no horizontal scroll risk, no cramped side panel.
        - Desktop (>= 1024px): classic split-view — product grid (flex 2) + cart panel (380px)
    -->

    <!-- ── Main shell ─────────────────────────────────────────────────────────── -->
    <div
        class="pos-page"
        :style="{ paddingBottom: store.lineCount > 0 ? '88px' : '0' }"
    >
        <!-- Cash register control (only when the giro enables the module) -->
        <div v-if="cashRegisterEnabled" class="pos-caja-bar" data-testid="pos-caja-bar">
            <template v-if="cashRegister.session.value">
                <span class="pos-caja-chip">
                    <Lock :size="14" />
                    Caja #{{ cashRegister.session.value.session_number }} · efectivo {{ formatCents(cashRegister.session.value.opening_amount_cents + cashRegister.session.value.cash_sales_cents) }}
                </span>
                <button type="button" class="btn btn-tertiary" data-testid="btn-cerrar-caja" @click="openCashRegisterFlow">Cerrar caja</button>
            </template>
            <template v-else>
                <span class="text-sm text-on-surface-variant">Caja cerrada</span>
                <button type="button" class="btn-primary gap-2" data-testid="btn-abrir-caja" @click="openCashRegisterFlow">
                    <LockOpen :size="16" /> Abrir caja
                </button>
            </template>
        </div>

        <div class="pos-shell">
        <!-- Left: products panel (full-width on mobile/tablet, flex-2 on desktop) -->
        <PosProductGrid
            :products="products"
            :categories="categories"
            :active-category="activeCategory"
            :search="searchQuery"
            :is-loading="isLoadingProducts || branchesLoading"
            :format-cents="formatCents"
            @update:search="searchQuery = $event"
            @update:active-category="activeCategory = $event"
            @select-product="handleSelectProduct"
        />

        <!--
            Right: cart panel — only visible inline on desktop (lg+).
            On mobile/tablet it renders inside the bottom-sheet instead.
            data-testid is on the outer column wrapper so the test
            can assert visibility via the CSS display property.
        -->
        <div class="hidden lg:block pos-cart-column" data-testid="pos-cart-panel-desktop">
            <PosCartPanel
                :lines="store.lines"
                :payment-method="store.paymentMethod"
                :subtotal-cents="store.subtotalCents"
                :tax-cents="store.taxCents"
                :total-cents="store.totalCents"
                :tax="store.taxConfig"
                :is-submitting="isSubmitting"
                :format-cents="formatCents"
                :selected-customer-name="selectedCustomerName"
                @increment="store.incrementLine"
                @decrement="store.decrementLine"
                @remove-line="store.removeLine"
                @update:payment-method="handlePaymentMethodChange"
                @checkout="openCheckout"
                @open-customer-selector="customerSelectorOpen = true"
            />
        </div>
        </div>
    </div>

    <!-- ── Mobile floating cart bar ──────────────────────────────────────────── -->
    <PosCartBottomBar
        :line-count="store.lineCount"
        :total-cents="store.totalCents"
        :format-cents="formatCents"
        @open="mobileCartOpen = true"
    />

    <!-- ── Mobile cart bottom-sheet (hidden on lg+) ───────────────────────────
         AppSlideover already renders as a bottom-sheet on mobile (below md) and
         as a right-side panel on md+. Since we only show the trigger on < lg
         and the desktop has an inline panel, the sheet can be opened at any width
         but will be naturally unreachable on large screens.
    ─────────────────────────────────────────────────────────────────────────── -->
    <AppSlideover
        v-model="mobileCartOpen"
        title="Tu carrito"
        side="right"
        width="420px"
        data-testid="pos-cart-sheet"
    >
        <PosCartPanel
            :lines="store.lines"
            :payment-method="store.paymentMethod"
            :subtotal-cents="store.subtotalCents"
            :tax-cents="store.taxCents"
            :total-cents="store.totalCents"
            :tax="store.taxConfig"
            :is-submitting="isSubmitting"
            :format-cents="formatCents"
            :selected-customer-name="selectedCustomerName"
            style="max-width: none; flex: 1"
            @increment="store.incrementLine"
            @decrement="store.decrementLine"
            @remove-line="store.removeLine"
            @update:payment-method="handlePaymentMethodChange"
            @checkout="openCheckout"
            @open-customer-selector="customerSelectorOpen = true"
        />
    </AppSlideover>

    <!-- ── Customer selector (slideover on md+, bottom-sheet on mobile) ──────── -->
    <PosCustomerSelector
        v-model="customerSelectorOpen"
        :selected-id="store.customerId"
        @select="handleSelectCustomer"
    />

    <!-- ── Receipt slideover ─────────────────────────────────────────────────── -->
    <PosReceiptSlideover
        v-model="receiptOpen"
        :receipt="receiptData"
        @new-sale="handleNewSale"
    />

    <!-- ── Variant picker overlay (products with 2+ variants) ─────────────────── -->
    <PosVariantPickerOverlay
        :show="variantPickerOpen"
        :product="variantPickerProduct"
        :format-cents="formatCents"
        @close="variantPickerOpen = false"
        @confirm="handleVariantPickerConfirm"
    />

    <!-- ── Checkout overlay (payment method + cash tendered + change) ──────────── -->
    <PosCheckoutOverlay
        :show="checkoutOverlayOpen"
        :lines="store.lines"
        :subtotal-cents="store.subtotalCents"
        :tax-cents="store.taxCents"
        :total-cents="store.totalCents"
        :payment-method="store.paymentMethod"
        :format-cents="formatCents"
        :submitting="isSubmitting"
        @update:payment-method="store.setPaymentMethod"
        @close="checkoutOverlayOpen = false"
        @confirm="submitCheckout($event.amountReceivedCents)"
    />

    <CashRegisterOverlay
        :show="crOverlayOpen"
        :mode="crMode"
        :session="cashRegister.session.value"
        :submitting="crSubmitting"
        @close="crOverlayOpen = false"
        @confirm="handleCashRegisterConfirm"
    />
</template>

<style scoped>
.pos-caja-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 14px;
    border-radius: var(--r-lg);
    background: var(--surface-low);
}
.pos-caja-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--on-surface);
}
.pos-page {
    display: flex;
    flex-direction: column;
    gap: 12px;
    /* Full-height minus AdminLayout header (~64px) + page padding (~56px). */
    height: calc(100vh - 120px);
}
.pos-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
    flex: 1;
    min-height: 0;
    /* Prevent horizontal overflow at all widths. */
    overflow-x: hidden;
}

@media (min-width: 1024px) {
    .pos-shell {
        flex-direction: row;
    }
}

/*
 * Cart column on desktop: fixed width, flex container so the inner card
 * can fill the full column height. Only applied at lg+ so that the scoped
 * "display: flex" doesn't override Tailwind's "hidden" (display: none)
 * at narrower viewports.
 */
@media (min-width: 1024px) {
    .pos-cart-column {
        flex-shrink: 0;
        width: 380px;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }
}

.pos-cart-column .card {
    max-width: none !important;
    height: 100%;
}
</style>
