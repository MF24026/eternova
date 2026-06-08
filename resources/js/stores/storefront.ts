import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import StorefrontService from '@/services/StorefrontService'
import type {
    StorefrontTenant,
    StorefrontCategory,
    StorefrontProduct,
    StorefrontProductFilters,
} from '@/types/domain/Storefront'
import type { PaginatedMeta, PaginatedLinks } from '@/types/api'

interface ProductPagination {
    meta: PaginatedMeta | null
    links: PaginatedLinks | null
}

export const useStorefrontStore = defineStore('storefront', () => {
    const tenant = ref<StorefrontTenant | null>(null)
    const categories = ref<StorefrontCategory[]>([])
    const featured = ref<StorefrontProduct[]>([])
    const products = ref<StorefrontProduct[]>([])
    const pagination = ref<ProductPagination>({ meta: null, links: null })
    const currentProduct = ref<StorefrontProduct | null>(null)
    const isLoading = ref(false)
    const error = ref<string | null>(null)

    // fetchTenant runs once per session — repeated calls are no-ops if data is present.
    async function fetchTenant(): Promise<void> {
        if (tenant.value !== null) return

        try {
            tenant.value = await StorefrontService.tenant()
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading tenant'
        }
    }

    async function fetchCategories(): Promise<void> {
        try {
            categories.value = await StorefrontService.categories()
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading categories'
        }
    }

    async function fetchFeatured(): Promise<void> {
        try {
            featured.value = await StorefrontService.featured()
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading featured products'
        }
    }

    async function fetchProducts(filters: StorefrontProductFilters = {}): Promise<void> {
        isLoading.value = true
        error.value = null

        try {
            const result = await StorefrontService.products(filters)
            products.value = result.data
            pagination.value = { meta: result.meta, links: result.links }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading products'
            products.value = []
        } finally {
            isLoading.value = false
        }
    }

    async function fetchProduct(slug: string): Promise<void> {
        isLoading.value = true
        error.value = null
        currentProduct.value = null

        try {
            currentProduct.value = await StorefrontService.product(slug)
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading product'
        } finally {
            isLoading.value = false
        }
    }

    // Formats a cent amount using the tenant's currency and language locale.
    // Falls back to USD / en-US when the tenant is not yet loaded.
    const formatPrice = computed(() => {
        const currency = tenant.value?.currency ?? 'USD'
        const language = tenant.value?.language ?? 'en-US'

        const formatter = new Intl.NumberFormat(language, {
            style: 'currency',
            currency,
        })

        return (cents: number): string => formatter.format(cents / 100)
    })

    return {
        tenant,
        categories,
        featured,
        products,
        pagination,
        currentProduct,
        isLoading,
        error,
        fetchTenant,
        fetchCategories,
        fetchFeatured,
        fetchProducts,
        fetchProduct,
        formatPrice,
    }
})
