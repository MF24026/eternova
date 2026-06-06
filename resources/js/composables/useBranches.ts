import { ref } from 'vue'
import type { InventoryBranch } from '@/types/domain/Inventory'

// TODO(backend): replace with real API call once GET /api/v1/branches exists.
// The seeded demo data currently has one branch per tenant. This stub keeps
// the UI functional until that endpoint is available (tracked in a future issue).
const MOCK_BRANCHES: InventoryBranch[] = [
    { id: 'main', name: 'Sucursal Principal', slug: 'main', is_main: true },
]

const cached = ref<InventoryBranch[]>([])
let loaded = false

export function useBranches() {
    const branches = ref<InventoryBranch[]>([])
    const isLoading = ref(false)

    async function loadBranches(): Promise<void> {
        if (loaded) {
            branches.value = cached.value
            return
        }

        isLoading.value = true
        try {
            // Stub: return mock until GET /api/v1/branches is available.
            await Promise.resolve()
            cached.value = MOCK_BRANCHES
            branches.value = MOCK_BRANCHES
            loaded = true
        } finally {
            isLoading.value = false
        }
    }

    return { branches, isLoading, loadBranches }
}
