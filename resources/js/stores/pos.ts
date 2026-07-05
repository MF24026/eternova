import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useTenantStore } from '@/stores/tenant'
import { computeTax } from '@/components/Admin/Pos/computeTax'
import type { TaxConfig } from '@/components/Admin/Pos/computeTax'
import type { PosCartLine, PosPaymentMethod, PosProduct, PosProductVariant } from '@/types/domain/POS'

// ── Storage helpers ───────────────────────────────────────────────────────────

interface PersistedState {
    lines: PosCartLine[]
    branchId: string
    paymentMethod: PosPaymentMethod
    customerId: number | null
    notes: string
}

/**
 * Derives the localStorage key for the current tenant.
 *
 * Isolation strategy: each tenant gets a separate key so that staff signed into
 * different tenant subdomains in the same browser (unlikely but possible) cannot
 * share cart state. The key is resolved from the Pinia tenant store first, then
 * falls back to the leftmost hostname label — matching the same pattern used by
 * the storefront cart store.
 */
function resolveStorageKey(): string {
    const tenantStore = useTenantStore()
    const slug = tenantStore.currentTenant?.slug ?? window.location.hostname.split('.')[0] ?? 'default'
    return `eternova:pos:${slug}`
}

function loadFromStorage(key: string): Partial<PersistedState> {
    try {
        const raw = localStorage.getItem(key)
        if (!raw) return {}
        const parsed: unknown = JSON.parse(raw)
        if (typeof parsed !== 'object' || parsed === null) return {}
        return parsed as Partial<PersistedState>
    } catch {
        return {}
    }
}

function saveToStorage(key: string, state: PersistedState): void {
    try {
        localStorage.setItem(key, JSON.stringify(state))
    } catch {
        // localStorage unavailable in some private-browsing modes — fail silently.
    }
}

// ── Store ─────────────────────────────────────────────────────────────────────

export const usePosStore = defineStore('pos', () => {
    const storageKey = resolveStorageKey()
    const persisted = loadFromStorage(storageKey)

    // ── State ─────────────────────────────────────────────────────────────────

    const lines = ref<PosCartLine[]>(persisted.lines ?? [])
    const branchId = ref<string>(persisted.branchId ?? '')
    const paymentMethod = ref<PosPaymentMethod>(persisted.paymentMethod ?? 'cash')
    const customerId = ref<number | null>(persisted.customerId ?? null)
    const notes = ref<string>(persisted.notes ?? '')

    // Tax configuration resolved from the products endpoint for the active branch.
    // Not persisted — refreshed on every product load so it always reflects the
    // current branch settings.
    const taxConfig = ref<TaxConfig>({ enabled: false, rate_bps: 0, prices_include_tax: false })

    // ── Persistence ───────────────────────────────────────────────────────────

    function persist(): void {
        saveToStorage(storageKey, {
            lines: lines.value,
            branchId: branchId.value,
            paymentMethod: paymentMethod.value,
            customerId: customerId.value,
            notes: notes.value,
        })
    }

    watch([lines, branchId, paymentMethod, customerId, notes], persist, { deep: true })

    // ── Getters ───────────────────────────────────────────────────────────────

    const lineCount = computed((): number =>
        lines.value.reduce((sum, l) => sum + l.quantity, 0),
    )

    const isEmpty = computed((): boolean => lines.value.length === 0)

    /**
     * Raw line total in cents — sum of (price × qty) before tax adjustments.
     * For inclusive pricing, this is the gross amount (tax already embedded).
     */
    const rawSubtotalCents = computed((): number =>
        lines.value.reduce((sum, l) => sum + l.priceCents * l.quantity, 0),
    )

    /**
     * Display-only tax breakdown derived from the active branch tax config.
     * The server recomputes authoritatively at checkout — these values are for
     * the live cart preview only and are never sent to the backend.
     */
    const taxTotals = computed(() => computeTax(rawSubtotalCents.value, 0, taxConfig.value))

    // subtotalCents = pre-tax base (equals rawSubtotalCents for exclusive tax,
    // the net price extracted for inclusive tax).
    const subtotalCents = computed((): number => taxTotals.value.subtotalCents)
    const taxCents = computed((): number => taxTotals.value.taxCents)
    const totalCents = computed((): number => taxTotals.value.totalCents)

    // ── Actions ───────────────────────────────────────────────────────────────

    function addVariant(product: PosProduct, variant: PosProductVariant): void {
        const existing = lines.value.find((l) => l.variantId === variant.id)

        if (existing) {
            // Honor the per-branch available_quantity ceiling.
            existing.quantity = Math.min(
                existing.quantity + 1,
                variant.available_quantity,
            )
            return
        }

        lines.value.push({
            variantId: variant.id,
            productId: product.id,
            productName: product.name,
            variantOptions: { ...variant.options },
            priceCents: variant.price_cents,
            imageUrl: variant.image_url ?? product.default_image_url,
            quantity: 1,
            availableQuantity: variant.available_quantity,
        })
    }

    function incrementLine(variantId: number): void {
        const line = lines.value.find((l) => l.variantId === variantId)
        if (!line) return
        line.quantity = Math.min(line.quantity + 1, line.availableQuantity)
    }

    function decrementLine(variantId: number): void {
        const line = lines.value.find((l) => l.variantId === variantId)
        if (!line) return

        if (line.quantity <= 1) {
            removeLine(variantId)
            return
        }

        line.quantity -= 1
    }

    function setQty(variantId: number, qty: number): void {
        if (qty <= 0) {
            removeLine(variantId)
            return
        }

        const line = lines.value.find((l) => l.variantId === variantId)
        if (!line) return
        line.quantity = Math.min(qty, line.availableQuantity)
    }

    function removeLine(variantId: number): void {
        const index = lines.value.findIndex((l) => l.variantId === variantId)
        if (index !== -1) lines.value.splice(index, 1)
    }

    function clear(): void {
        lines.value = []
        customerId.value = null
        notes.value = ''
    }

    function setBranch(id: string): void {
        branchId.value = id
        // Clear the cart when switching branches — stock counts are per-branch.
        clear()
    }

    function setPaymentMethod(method: PosPaymentMethod): void {
        paymentMethod.value = method
    }

    function setCustomer(id: number | null): void {
        customerId.value = id
    }

    function setNotes(text: string): void {
        notes.value = text
    }

    function setTaxConfig(config: TaxConfig): void {
        taxConfig.value = config
    }

    return {
        // State
        lines,
        branchId,
        paymentMethod,
        customerId,
        notes,
        taxConfig,
        // Getters
        lineCount,
        isEmpty,
        subtotalCents,
        taxCents,
        totalCents,
        // Actions
        addVariant,
        incrementLine,
        decrementLine,
        setQty,
        removeLine,
        clear,
        setBranch,
        setPaymentMethod,
        setCustomer,
        setNotes,
        setTaxConfig,
    }
})
