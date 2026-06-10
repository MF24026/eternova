import api from './api'
import type { Paginated } from '@/types/api'

export interface CustomerSummary {
    id: number
    name: string
    email: string | null
    phone: string | null
    whatsapp: string | null
}

const CustomerService = {
    /**
     * Search customers by name or phone.
     * Returns the first page of up to `per_page` matches.
     */
    async search(query: string, perPage = 20): Promise<CustomerSummary[]> {
        const response = await api.get<Paginated<CustomerSummary>>('/customers', {
            params: {
                search: query.trim() || undefined,
                per_page: perPage,
            },
        })
        return response.data.data
    },
}

export default CustomerService
