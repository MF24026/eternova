import api from './api'
import type { Plan } from '@/types/domain/Plan'

const PlansService = {
    async list(): Promise<Plan[]> {
        const response = await api.get<{ data: Plan[]; meta: { request_id: string } }>('/plans')
        return response.data.data
    },
}

export default PlansService
