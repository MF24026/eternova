import { ref, computed } from 'vue'

export interface PaginatedMeta {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
}

export interface PaginatedResponse<T> {
    data: T[]
    meta: PaginatedMeta
}

/**
 * Generic pagination composable.
 * Pass a fetch function that receives (page, perPage) and returns a PaginatedResponse<T>.
 *
 * Usage:
 *   const { items, meta, page, loading, goTo, next, prev } = usePaginated(fetchFn)
 */
export function usePaginated<T>(
    fetchFn: (page: number, perPage: number) => Promise<PaginatedResponse<T>>,
    initialPerPage = 15,
) {
    const items = ref<T[]>([])
    const meta = ref<PaginatedMeta>({
        current_page: 1,
        last_page: 1,
        per_page: initialPerPage,
        total: 0,
        from: null,
        to: null,
    })
    const loading = ref(false)
    const error = ref<string | null>(null)

    const page = computed(() => meta.value.current_page)
    const hasNext = computed(() => meta.value.current_page < meta.value.last_page)
    const hasPrev = computed(() => meta.value.current_page > 1)

    async function load(targetPage = 1): Promise<void> {
        loading.value = true
        error.value = null
        try {
            const response = await fetchFn(targetPage, meta.value.per_page)
            items.value = response.data as T[]
            meta.value = response.meta
        } catch (err) {
            error.value = err instanceof Error ? err.message : 'Error al cargar los datos'
        } finally {
            loading.value = false
        }
    }

    function goTo(targetPage: number): void {
        if (targetPage < 1 || targetPage > meta.value.last_page) return
        void load(targetPage)
    }

    function next(): void {
        if (hasNext.value) goTo(meta.value.current_page + 1)
    }

    function prev(): void {
        if (hasPrev.value) goTo(meta.value.current_page - 1)
    }

    function refresh(): void {
        void load(meta.value.current_page)
    }

    return {
        items,
        meta,
        page,
        loading,
        error,
        hasNext,
        hasPrev,
        load,
        goTo,
        next,
        prev,
        refresh,
    }
}
