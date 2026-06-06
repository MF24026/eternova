import { defineStore } from 'pinia'
import { ref } from 'vue'
import CategoriesService, { type CategoryFilters } from '@/services/CategoriesService'
import type { Category, CategoryInput, CategoryReorderItem } from '@/types/domain/Category'
import type { PaginatedMeta, PaginatedLinks } from '@/types/api'

interface CategoryPagination {
    meta: PaginatedMeta | null
    links: PaginatedLinks | null
}

export const useCategoriesStore = defineStore('categories', () => {
    const items = ref<Category[]>([])
    const current = ref<Category | null>(null)
    const isLoading = ref(false)
    const error = ref<string | null>(null)
    const pagination = ref<CategoryPagination>({ meta: null, links: null })

    async function fetchList(filters: CategoryFilters = {}): Promise<void> {
        isLoading.value = true
        error.value = null

        try {
            const result = await CategoriesService.list(filters)
            items.value = result.data
            pagination.value = { meta: result.meta, links: result.links }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading categories'
            items.value = []
        } finally {
            isLoading.value = false
        }
    }

    async function fetchOne(id: number): Promise<void> {
        isLoading.value = true
        error.value = null

        try {
            current.value = await CategoriesService.get(id)
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading category'
            current.value = null
        } finally {
            isLoading.value = false
        }
    }

    async function create(data: CategoryInput): Promise<Category> {
        const created = await CategoriesService.create(data)
        items.value = [created, ...items.value]
        return created
    }

    async function update(id: number, data: Partial<CategoryInput>): Promise<Category> {
        const updated = await CategoriesService.update(id, data)
        const idx = items.value.findIndex((c) => c.id === id)
        if (idx !== -1) {
            items.value[idx] = updated
        }
        if (current.value?.id === id) {
            current.value = updated
        }
        return updated
    }

    async function remove(id: number): Promise<void> {
        await CategoriesService.delete(id)
        items.value = items.value.filter((c) => c.id !== id)
        if (current.value?.id === id) {
            current.value = null
        }
    }

    async function restore(id: number): Promise<Category> {
        const restored = await CategoriesService.restore(id)
        return restored
    }

    async function reorder(reorderItems: CategoryReorderItem[]): Promise<void> {
        await CategoriesService.reorder(reorderItems)

        // Apply the reorder optimistically to local state so the UI updates immediately
        // without a full refetch.
        const orderMap = new Map(reorderItems.map((r) => [r.id, r]))
        items.value = items.value.map((cat) => {
            const update = orderMap.get(cat.id)
            if (update === undefined) return cat
            return { ...cat, sort_order: update.sort_order, parent_id: update.parent_id }
        })
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
        reorder,
    }
})
