import { computed } from 'vue'
import { useTenantStore } from '@/stores/tenant'

/**
 * Returns a formatter function that converts cent amounts to a locale-aware
 * currency string. Falls back to USD / en-US when no tenant is loaded.
 */
export function useFormatCurrency() {
    const tenantStore = useTenantStore()

    const formatter = computed(() => {
        const currency = tenantStore.currentTenant?.currency ?? 'USD'
        const language = tenantStore.currentTenant?.language ?? 'en-US'

        return new Intl.NumberFormat(language, {
            style: 'currency',
            currency,
        })
    })

    function formatCents(cents: number): string {
        return formatter.value.format(cents / 100)
    }

    return { formatCents }
}
