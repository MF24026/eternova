import api from './api'
import type { Resource } from '@/types/api'
import type { Tenant } from '@/types/domain/Tenant'

interface CreateTenantPayload {
    name: string
    slug: string
    business_name: string
}

const TenantsService = {
    async create(payload: CreateTenantPayload): Promise<Tenant> {
        const response = await api.post<Resource<Tenant>>('/tenants', payload)
        return response.data.data
    },
}

export default TenantsService
