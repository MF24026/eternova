import { useTenantStore } from '@/stores/tenant'
import { storeToRefs } from 'pinia'

/**
 * Convenience composable for tenant state.
 */
export function useTenant() {
    const store = useTenantStore()
    const { currentTenant } = storeToRefs(store)
    const { setTenant, clearTenant } = store

    return { currentTenant, setTenant, clearTenant }
}
