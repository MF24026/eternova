import { ref } from 'vue'
import api from '@/services/api'
import type { InventoryBranch } from '@/types/domain/Inventory'

interface BranchesApiItem {
    id: string
    name: string
    slug: string
    address: string | null
    phone: string | null
    is_main: boolean
    is_active: boolean
}

interface BranchesApiResponse {
    data: BranchesApiItem[]
}

// Module-level cache so multiple callers share one fetch per session.
const cached = ref<InventoryBranch[]>([])
let loaded = false
let inflightPromise: Promise<void> | null = null

export function useBranches() {
    const branches = ref<InventoryBranch[]>(cached.value.length > 0 ? [...cached.value] : [])
    const isLoading = ref(false)

    async function loadBranches(): Promise<void> {
        if (loaded) {
            branches.value = cached.value
            return
        }

        // Coalesce concurrent callers (e.g. InventoryPage + POSPage mount at the same time)
        // onto the single in-flight request rather than firing N parallel GETs.
        if (inflightPromise) {
            await inflightPromise
            branches.value = cached.value
            return
        }

        isLoading.value = true

        inflightPromise = (async () => {
            try {
                const response = await api.get<BranchesApiResponse>('/branches')
                // Map the richer API shape to the InventoryBranch contract that
                // existing consumers (InventoryPage) depend on.
                cached.value = response.data.data.map(
                    (b): InventoryBranch => ({
                        id: b.id,
                        name: b.name,
                        slug: b.slug,
                        is_main: b.is_main,
                    }),
                )
                branches.value = cached.value
                loaded = true
            } catch {
                // Leave branches empty — callers handle the empty state gracefully.
                branches.value = []
            } finally {
                isLoading.value = false
                inflightPromise = null
            }
        })()

        await inflightPromise
    }

    return { branches, isLoading, loadBranches }
}
