import { defineStore } from 'pinia'
import { ref } from 'vue'
import ProductsService, { type ProductFilters } from '@/services/ProductsService'
import type { Product, ProductInput, ProductVariant, ProductVariantInput } from '@/types/domain/Product'
import type { PaginatedMeta, PaginatedLinks } from '@/types/api'

interface ProductPagination {
    meta: PaginatedMeta | null
    links: PaginatedLinks | null
}

export const useProductsStore = defineStore('products', () => {
    const items = ref<Product[]>([])
    const current = ref<Product | null>(null)
    const isLoading = ref(false)
    const error = ref<string | null>(null)
    const pagination = ref<ProductPagination>({ meta: null, links: null })

    async function fetchList(filters: ProductFilters = {}): Promise<void> {
        isLoading.value = true
        error.value = null

        try {
            const result = await ProductsService.list(filters)
            items.value = result.data
            pagination.value = { meta: result.meta, links: result.links }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading products'
            items.value = []
        } finally {
            isLoading.value = false
        }
    }

    async function fetchOne(id: number): Promise<void> {
        isLoading.value = true
        error.value = null

        try {
            current.value = await ProductsService.get(id)
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading product'
            current.value = null
        } finally {
            isLoading.value = false
        }
    }

    async function create(data: ProductInput): Promise<Product> {
        const created = await ProductsService.create(data)
        items.value = [created, ...items.value]
        return created
    }

    async function update(id: number, data: Partial<ProductInput>): Promise<Product> {
        const updated = await ProductsService.update(id, data)
        const idx = items.value.findIndex((p) => p.id === id)
        if (idx !== -1) {
            items.value[idx] = updated
        }
        if (current.value?.id === id) {
            current.value = updated
        }
        return updated
    }

    async function remove(id: number): Promise<void> {
        await ProductsService.delete(id)
        items.value = items.value.filter((p) => p.id !== id)
        if (current.value?.id === id) {
            current.value = null
        }
    }

    async function restore(id: number): Promise<Product> {
        return ProductsService.restore(id)
    }

    async function addVariant(productId: number, data: ProductVariantInput): Promise<ProductVariant> {
        const variant = await ProductsService.addVariant(productId, data)
        if (current.value?.id === productId) {
            current.value = {
                ...current.value,
                variants: [...current.value.variants, variant],
            }
        }
        return variant
    }

    async function updateVariant(productId: number, variantId: number, data: Partial<ProductVariantInput>): Promise<ProductVariant> {
        const updated = await ProductsService.updateVariant(productId, variantId, data)
        if (current.value?.id === productId) {
            const variants = current.value.variants.map((v) => (v.id === variantId ? updated : v))
            current.value = { ...current.value, variants }
        }
        return updated
    }

    async function removeVariant(productId: number, variantId: number): Promise<void> {
        await ProductsService.removeVariant(productId, variantId)
        if (current.value?.id === productId) {
            current.value = {
                ...current.value,
                variants: current.value.variants.filter((v) => v.id !== variantId),
            }
        }
    }

    async function reorderVariants(productId: number, items: Array<{ id: number; position: number }>): Promise<void> {
        await ProductsService.reorderVariants(productId, items)
    }

    return {
        items,
        current,
        isLoading,
        error,
        pagination,
        fetchList,
        fetchOne,
        create,
        update,
        remove,
        restore,
        addVariant,
        updateVariant,
        removeVariant,
        reorderVariants,
    }
})
