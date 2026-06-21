import api from './api'
import type {
    BillingMetrics,
    SuperAdminTenantRow,
    SuperAdminTenantDetail,
} from '@/types/domain/SuperAdmin'

/**
 * Platform operator console (super-admin only — the API enforces the super_admin middleware and
 * 403s the rest). Not tenant-scoped: these endpoints key off is_super_admin, never a tenant_id.
 */
const SuperAdminService = {
    async metrics(): Promise<BillingMetrics> {
        const res = await api.get<{ data: BillingMetrics }>('/super-admin/billing/metrics')
        return res.data.data
    },

    async tenants(): Promise<SuperAdminTenantRow[]> {
        const res = await api.get<{ data: SuperAdminTenantRow[] }>('/super-admin/tenants')
        return res.data.data
    },

    async tenant(id: string): Promise<SuperAdminTenantDetail> {
        const res = await api.get<{ data: SuperAdminTenantDetail }>(`/super-admin/tenants/${id}`)
        return res.data.data
    },

    async extendTrial(id: string, days: number, reason: string): Promise<void> {
        await api.post(`/super-admin/tenants/${id}/extend-trial`, { days, reason })
    },

    async suspend(id: string, reason: string): Promise<void> {
        await api.post(`/super-admin/tenants/${id}/suspend`, { reason })
    },

    async reactivate(id: string, reason: string): Promise<void> {
        await api.post(`/super-admin/tenants/${id}/reactivate`, { reason })
    },
}

export default SuperAdminService
