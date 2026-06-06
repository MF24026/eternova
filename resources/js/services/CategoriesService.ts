import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type { Category, CategoryInput, CategoryReorderItem } from '@/types/domain/Category'

export interface CategoryFilters {
    search?: string
    is_active?: boolean
    parent_id?: number | null
    include_children?: boolean
    per_page?: number
    page?: number
}

const CategoriesService = {
    async list(filters: CategoryFilters = {}): Promise<Paginated<Category>> {
        const params: Record<string, string | number | boolean> = {}

        if (filters.search !== undefined && filters.search !== '') {
            params['search'] = filters.search
        }
        if (filters.is_active !== undefined) {
            params['is_active'] = filters.is_active
        }
        if (filters.parent_id !== undefined && filters.parent_id !== null) {
            params['parent_id'] = filters.parent_id
        }
        if (filters.include_children !== undefined) {
            params['include_children'] = filters.include_children
        }
        if (filters.per_page !== undefined) {
            params['per_page'] = filters.per_page
        }
        if (filters.page !== undefined) {
            params['page'] = filters.page
        }

        const response = await api.get<Paginated<Category>>('/categories', { params })
        return response.data
    },

    async get(id: number): Promise<Category> {
        const response = await api.get<Resource<Category>>(`/categories/${id}`)
        return response.data.data
    },

    async create(data: CategoryInput): Promise<Category> {
        const response = await api.post<Resource<Category>>('/categories', data)
        return response.data.data
    },

    async update(id: number, data: Partial<CategoryInput>): Promise<Category> {
        const response = await api.patch<Resource<Category>>(`/categories/${id}`, data)
        return response.data.data
    },

    async delete(id: number): Promise<void> {
        await api.delete(`/categories/${id}`)
    },

    async restore(id: number): Promise<Category> {
        const response = await api.post<Resource<Category>>(`/categories/${id}/restore`)
        return response.data.data
    },

    async reorder(items: CategoryReorderItem[]): Promise<void> {
        await api.patch('/categories/reorder', { items })
    },
}

export default CategoriesService
