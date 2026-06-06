import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type { Product, ProductInput, ProductVariant, ProductVariantInput, Tag, TagInput } from '@/types/domain/Product'

export interface ProductFilters {
    search?: string
    category_id?: number | null
    tag_id?: number | null
    is_active?: boolean
    is_featured?: boolean
    with?: string
    per_page?: number
    page?: number
}

const ProductsService = {
    async list(filters: ProductFilters = {}): Promise<Paginated<Product>> {
        const params: Record<string, string | number | boolean> = {}

        if (filters.search !== undefined && filters.search !== '') {
            params['search'] = filters.search
        }
        if (filters.category_id !== undefined && filters.category_id !== null) {
            params['category_id'] = filters.category_id
        }
        if (filters.tag_id !== undefined && filters.tag_id !== null) {
            params['tag_id'] = filters.tag_id
        }
        if (filters.is_active !== undefined) {
            params['is_active'] = filters.is_active
        }
        if (filters.is_featured !== undefined) {
            params['is_featured'] = filters.is_featured
        }
        if (filters.with !== undefined && filters.with !== '') {
            params['with'] = filters.with
        }
        if (filters.per_page !== undefined) {
            params['per_page'] = filters.per_page
        }
        if (filters.page !== undefined) {
            params['page'] = filters.page
        }

        const response = await api.get<Paginated<Product>>('/products', { params })
        return response.data
    },

    async get(id: number): Promise<Product> {
        const response = await api.get<Resource<Product>>(`/products/${id}`)
        return response.data.data
    },

    async create(data: ProductInput): Promise<Product> {
        const response = await api.post<Resource<Product>>('/products', data)
        return response.data.data
    },

    async update(id: number, data: Partial<ProductInput>): Promise<Product> {
        const response = await api.patch<Resource<Product>>(`/products/${id}`, data)
        return response.data.data
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/products/${id}`)
    },

    async restore(id: number): Promise<Product> {
        const response = await api.post<Resource<Product>>(`/products/${id}/restore`)
        return response.data.data
    },

    async addVariant(productId: number, data: ProductVariantInput): Promise<ProductVariant> {
        const response = await api.post<Resource<ProductVariant>>(`/products/${productId}/variants`, data)
        return response.data.data
    },

    async updateVariant(productId: number, variantId: number, data: Partial<ProductVariantInput>): Promise<ProductVariant> {
        const response = await api.patch<Resource<ProductVariant>>(`/products/${productId}/variants/${variantId}`, data)
        return response.data.data
    },

    async removeVariant(productId: number, variantId: number): Promise<void> {
        await api.delete(`/products/${productId}/variants/${variantId}`)
    },

    async reorderVariants(productId: number, items: Array<{ id: number; position: number }>): Promise<void> {
        await api.patch(`/products/${productId}/variants/reorder`, { items })
    },
}

export const TagsService = {
    async list(filters: { search?: string; per_page?: number; page?: number } = {}): Promise<Paginated<Tag>> {
        const params: Record<string, string | number> = {}
        if (filters.search !== undefined && filters.search !== '') params['search'] = filters.search
        if (filters.per_page !== undefined) params['per_page'] = filters.per_page
        if (filters.page !== undefined) params['page'] = filters.page

        const response = await api.get<Paginated<Tag>>('/tags', { params })
        return response.data
    },

    async create(data: TagInput): Promise<Tag> {
        const response = await api.post<Resource<Tag>>('/tags', data)
        return response.data.data
    },

    async update(id: number, data: Partial<TagInput>): Promise<Tag> {
        const response = await api.patch<Resource<Tag>>(`/tags/${id}`, data)
        return response.data.data
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/tags/${id}`)
    },
}

export default ProductsService
