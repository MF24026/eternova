import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { useStorefrontStore } from '@/stores/storefront'
import type { StorefrontProduct, StorefrontVariant } from '@/types/domain/Storefront'

export interface CartItem {
    variantId: number
    productId: number
    productName: string
    productSlug: string
    variantOptions: Record<string, string>
    priceCents: number
    imageUrl: string | null
    quantity: number
}

interface AddItemPayload {
    variant: StorefrontVariant
    product: StorefrontProduct
    qty: number
}

/**
 * Resolves the localStorage key for the current tenant.
 *
 * Per-tenant isolation: each subdomain gets its own cart so that two storefronts
 * open in the same browser cannot share cart state. The key includes the tenant
 * slug — resolved from the Pinia storefront store first, then from the leftmost
 * hostname label as a fallback while the store is still loading.
 */
function resolveStorageKey(): string {
    const storefront = useStorefrontStore()
    const slug = storefront.tenant?.slug ?? window.location.hostname.split('.')[0] ?? 'default'
    return `eternova:cart:${slug}`
}

function loadFromStorage(key: string): CartItem[] {
    try {
        const raw = localStorage.getItem(key)
        if (!raw) return []
        const parsed: unknown = JSON.parse(raw)
        if (!Array.isArray(parsed)) return []
        return parsed as CartItem[]
    } catch {
        return []
    }
}

function saveToStorage(key: string, items: CartItem[]): void {
    try {
        localStorage.setItem(key, JSON.stringify(items))
    } catch {
        // localStorage may be unavailable in private browsing — fail silently
    }
}

export const useCartStore = defineStore('cart', () => {
    const storageKey = resolveStorageKey()
    const items = ref<CartItem[]>(loadFromStorage(storageKey))

    // Persist every mutation immediately
    watch(items, (updated) => saveToStorage(storageKey, updated), { deep: true })

    // ── Getters ──────────────────────────────────────────────────────────────

    const count = computed((): number =>
        items.value.reduce((sum, item) => sum + item.quantity, 0),
    )

    const subtotalCents = computed((): number =>
        items.value.reduce((sum, item) => sum + item.priceCents * item.quantity, 0),
    )

    const isEmpty = computed((): boolean => items.value.length === 0)

    // ── Actions ───────────────────────────────────────────────────────────────

    function addItem({ variant, product, qty }: AddItemPayload): void {
        const existing = items.value.find((i) => i.variantId === variant.id)

        if (existing) {
            existing.quantity += qty
            return
        }

        // Variant price takes precedence; fall back to the product base price
        const priceCents = variant.price_cents ?? product.base_price_cents

        items.value.push({
            variantId: variant.id,
            productId: product.id,
            productName: product.name,
            productSlug: product.slug,
            variantOptions: { ...variant.options },
            priceCents,
            imageUrl: variant.image_url ?? product.default_image_url,
            quantity: qty,
        })
    }

    function removeItem(variantId: number): void {
        const index = items.value.findIndex((i) => i.variantId === variantId)
        if (index !== -1) items.value.splice(index, 1)
    }

    function updateQty(variantId: number, qty: number): void {
        if (qty <= 0) {
            removeItem(variantId)
            return
        }

        const item = items.value.find((i) => i.variantId === variantId)
        if (item) item.quantity = qty
    }

    function clear(): void {
        items.value = []
    }

    return {
        items,
        count,
        subtotalCents,
        isEmpty,
        addItem,
        removeItem,
        updateQty,
        clear,
    }
})
