import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { Tenant } from '@/types/domain/Tenant'

export const useTenantStore = defineStore('tenant', () => {
    const currentTenant = ref<Tenant | null>(null)

    function setTenant(tenant: Tenant): void {
        currentTenant.value = tenant
    }

    function clearTenant(): void {
        currentTenant.value = null
    }

    return { currentTenant, setTenant, clearTenant }
})
