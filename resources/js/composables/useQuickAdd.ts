import { ref } from 'vue'
import type { StorefrontProduct } from '@/types/domain/Storefront'

/**
 * Shared quick-add state for the storefront. A grid card calls `open(product)`
 * with the list product (name/image/price for the header); the single
 * StorefrontQuickAddSheet rendered in StorefrontLayout reacts to it, fetches the
 * product's variants, and lets the shopper add to cart without leaving the grid.
 *
 * Module-scoped singleton: one sheet, triggered from any grid.
 */
const isOpen = ref(false)
const product = ref<StorefrontProduct | null>(null)

export function useQuickAdd() {
    function open(p: StorefrontProduct): void {
        product.value = p
        isOpen.value = true
    }

    function close(): void {
        isOpen.value = false
    }

    return { isOpen, product, open, close }
}
