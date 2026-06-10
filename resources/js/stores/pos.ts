import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useTenantStore } from '@/stores/tenant'
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
     * Subtotal in cents, computed client-side from variant prices.
     *
     * Money correctness note: all prices are in cents from the backend.
     * We do NOT add a client-side tax here — tax_cents from the backend is
     * currently 0. The "Cobrar" button amount equals subtotalCents, which
     * matches what the backend will actually charge. After checkout we show
     * the authoritative totals from the 201 response.
     */
    const subtotalCents = computed((): number =>
        lines.value.reduce((sum, l) => sum + l.priceCents * l.quantity, 0),
    )

    // Tax is zero until the backend implements tax logic.
    const taxCents = computed((): number => 0)

    const totalCents = computed((): number => subtotalCents.value + taxCents.value)

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

    return {
        // State
        lines,
        branchId,
        paymentMethod,
        customerId,
        notes,
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
    }
})
