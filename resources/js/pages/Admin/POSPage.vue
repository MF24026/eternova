<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import PosProductGrid from '@/components/Admin/Pos/PosProductGrid.vue'
import PosCartPanel from '@/components/Admin/Pos/PosCartPanel.vue'
import { usePosStore } from '@/stores/pos'
import { useBranches } from '@/composables/useBranches'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useToast } from '@/composables/useToast'
import PosService from '@/services/PosService'
import type { PosProduct, PosProductCategory, PosPaymentMethod } from '@/types/domain/POS'
import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'

onMounted(async () => {
    document.title = 'Punto de venta — Eternova'
    await loadBranches()
    resolveDefaultBranch()
    void loadProducts()
})

const store = usePosStore()
const { branches, isLoading: branchesLoading, loadBranches } = useBranches()
const { formatCents } = useFormatCurrency()
const toast = useToast()

// ── Branch resolution ──────────────────────────────────────────────────────────

function resolveDefaultBranch(): void {
    if (store.branchId) return  // already set from localStorage

    const main = branches.value.find((b) => b.is_main)
    const first = branches.value[0]
    const resolved = main ?? first
    if (resolved) {
        // setBranch clears the cart, but since branchId was empty there is nothing to clear.
        store.branchId = resolved.id
    }
}

// If branches load after mount (race), resolve once they arrive.
watch(branches, () => {
    if (!store.branchId) resolveDefaultBranch()
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

// ── Checkout ─────────────────────────────────────────────────────────────────

const isSubmitting = ref(false)

async function handleCheckout(): Promise<void> {
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
            customer_id: store.customerId ?? undefined,
            notes: store.notes || undefined,
        })

        // Clear the cart on success — the authoritative totals are in result.data.
        store.clear()
        toast.success(`Venta #${result.data.order_number} registrada correctamente`)

        // Reload products so available_quantity reflects the new stock levels.
        void loadProducts()
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

// ── Cart event handlers ───────────────────────────────────────────────────────

function handleSelectProduct(product: PosProduct): void {
    const variant = product.variants[0]
    if (!variant) return
    store.addVariant(product, variant)
}

function handlePaymentMethodChange(method: PosPaymentMethod): void {
    store.setPaymentMethod(method)
}
</script>

<template>
    <div class="pos-shell">
        <!-- Left: products panel -->
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

        <!-- Right: cart panel -->
        <PosCartPanel
            :lines="store.lines"
            :payment-method="store.paymentMethod"
            :subtotal-cents="store.subtotalCents"
            :tax-cents="store.taxCents"
            :total-cents="store.totalCents"
            :is-submitting="isSubmitting"
            :format-cents="formatCents"
            @increment="store.incrementLine"
            @decrement="store.decrementLine"
            @remove-line="store.removeLine"
            @update:payment-method="handlePaymentMethodChange"
            @checkout="handleCheckout"
        />
    </div>
</template>

<style scoped>
.pos-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
    /* Full-height minus the AdminLayout header (~64px) + page padding (~56px). */
    height: calc(100vh - 120px);
}

@media (min-width: 1024px) {
    .pos-shell {
        flex-direction: row;
    }

    /* On desktop the cart panel has a fixed max-width and the grid gets the rest. */
    .pos-shell :deep(.card:last-child) {
        /* The cart panel already sets max-width: 380px via inline style. */
        flex-shrink: 0;
    }
}
</style>
