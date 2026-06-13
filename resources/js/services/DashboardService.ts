import api from './api'
import type { DashboardSummary, DashboardRange } from '@/types/domain/Dashboard'

interface DashboardResponse {
    data: DashboardSummary
}

/**
 * Dashboard API client. Returns the tenant KPI summary for a given chart range.
 */
const DashboardService = {
    async get(range: DashboardRange = 14): Promise<DashboardSummary> {
        const response = await api.get<DashboardResponse>('/dashboard', {
            params: { range },
        })
        return response.data.data
    },
}

export default DashboardService
