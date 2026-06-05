import api from './api'
import type { Resource } from '@/types/api'
import type { Tenant } from '@/types/domain/Tenant'

export interface SlugCheckResult {
    available: boolean
    reason?: 'format' | 'reserved' | 'taken'
    slug: string
}

export interface CreateTenantPayload {
    name: string
    slug: string
    business_name: string
    country_code: string
    currency: string
    language: string
    timezone: string
    plan_slug?: string
    billing_cycle?: 'monthly' | 'yearly'
}

const TenantsService = {
    async checkSlug(slug: string): Promise<SlugCheckResult> {
        const response = await api.get<{ data: SlugCheckResult; meta: { request_id: string } }>(
            '/tenants/check-slug',
            { params: { slug } },
        )
        return response.data.data
    },

    async create(payload: CreateTenantPayload): Promise<Tenant> {
        const response = await api.post<Resource<Tenant>>('/tenants', payload)
        return response.data.data
    },
}

export default TenantsService
