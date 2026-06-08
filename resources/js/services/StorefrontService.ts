import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type {
    StorefrontTenant,
    StorefrontCategory,
    StorefrontProduct,
    StorefrontProductFilters,
} from '@/types/domain/Storefront'

interface StorefrontTenantResponse {
    data: StorefrontTenant
    meta: Record<string, unknown>
}

interface StorefrontCategoriesResponse {
    data: StorefrontCategory[]
    meta: Record<string, unknown>
}

interface StorefrontFeaturedResponse {
    data: StorefrontProduct[]
    meta: Record<string, unknown>
}

const StorefrontService = {
    async tenant(): Promise<StorefrontTenant> {
        const response = await api.get<StorefrontTenantResponse>('/storefront/tenant')
        return response.data.data
    },

    async categories(): Promise<StorefrontCategory[]> {
        const response = await api.get<StorefrontCategoriesResponse>('/storefront/categories')
        return response.data.data
    },

    async products(filters: StorefrontProductFilters = {}): Promise<Paginated<StorefrontProduct>> {
        const params: Record<string, string | number> = {}

        if (filters.category_slug) params['category_slug'] = filters.category_slug
        if (filters.tag_slug) params['tag_slug'] = filters.tag_slug
        if (filters.search) params['search'] = filters.search
        if (filters.sort) params['sort'] = filters.sort
        if (filters.page !== undefined) params['page'] = filters.page
        if (filters.per_page !== undefined) params['per_page'] = filters.per_page

        const response = await api.get<Paginated<StorefrontProduct>>('/storefront/products', { params })
        return response.data
    },

    async featured(): Promise<StorefrontProduct[]> {
        const response = await api.get<StorefrontFeaturedResponse>('/storefront/products/featured')
        return response.data.data
    },

    async product(slug: string): Promise<StorefrontProduct> {
        const response = await api.get<Resource<StorefrontProduct>>(`/storefront/products/${slug}`)
        return response.data.data
    },
}

export default StorefrontService
