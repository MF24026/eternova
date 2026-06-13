import api from './api'
import type { TenantSettings, SettingsGroup, SettingsMeta } from '@/types/domain/Settings'

interface SettingsShowResponse {
    data: TenantSettings
    meta: SettingsMeta
}

interface SettingsUpdateResponse {
    data: TenantSettings
}

/**
 * Tenant settings API client.
 *
 *   getAll()              → GET  /settings  (all groups resolved + catalog meta)
 *   updateGroup(g, body)  → POST /settings/{group}
 *
 * Most groups send a JSON object. The `brand` group may send a FormData payload
 * to carry logo/favicon file uploads — pass a FormData instance and the correct
 * multipart headers are applied automatically.
 */
const SettingsService = {
    async getAll(): Promise<SettingsShowResponse> {
        const response = await api.get<SettingsShowResponse>('/settings')
        return response.data
    },

    async updateGroup(
        group: SettingsGroup,
        payload: Record<string, unknown> | FormData,
    ): Promise<TenantSettings> {
        const isForm = payload instanceof FormData
        const response = await api.post<SettingsUpdateResponse>(
            `/settings/${group}`,
            payload,
            isForm ? { headers: { 'Content-Type': 'multipart/form-data' } } : undefined,
        )
        return response.data.data
    },
}

export default SettingsService
