import { defineStore } from 'pinia'
import { ref } from 'vue'
import { TagsService } from '@/services/ProductsService'
import type { Tag, TagInput } from '@/types/domain/Product'
import type { PaginatedMeta, PaginatedLinks } from '@/types/api'

interface TagPagination {
    meta: PaginatedMeta | null
    links: PaginatedLinks | null
}

export const useTagsStore = defineStore('tags', () => {
    const items = ref<Tag[]>([])
    const isLoading = ref(false)
    const error = ref<string | null>(null)
    const pagination = ref<TagPagination>({ meta: null, links: null })

    async function fetchList(filters: { search?: string; per_page?: number; page?: number } = {}): Promise<void> {
        isLoading.value = true
        error.value = null

        try {
            const result = await TagsService.list(filters)
            items.value = result.data
            pagination.value = { meta: result.meta, links: result.links }
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error loading tags'
            items.value = []
        } finally {
            isLoading.value = false
        }
    }

    async function create(data: TagInput): Promise<Tag> {
        const created = await TagsService.create(data)
        items.value = [created, ...items.value]
        return created
    }

    async function update(id: number, data: Partial<TagInput>): Promise<Tag> {
        const updated = await TagsService.update(id, data)
        const idx = items.value.findIndex((t) => t.id === id)
        if (idx !== -1) {
            items.value[idx] = updated
        }
        return updated
    }

    async function remove(id: number): Promise<void> {
        await TagsService.delete(id)
        items.value = items.value.filter((t) => t.id !== id)
    }

    return {
        items,
        isLoading,
        error,
        pagination,
        fetchList,
        create,
        update,
        remove,
    }
})
