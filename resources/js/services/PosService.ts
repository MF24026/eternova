import api from './api'
import type {
    PosBranch,
    PosProductsResponse,
    PosCheckoutPayload,
    PosCheckoutResponse,
    PosReceiptResponse,
} from '@/types/domain/POS'

interface BranchesApiResponse {
    data: PosBranch[]
}


export interface PosProductFilters {
    branch_id: string
    search?: string
    category_slug?: string
    per_page?: number
    page?: number
}

function buildParams(
    filters: Record<string, string | number | undefined>,
): Record<string, string | number> {
    const params: Record<string, string | number> = {}
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '') {
            params[key] = value
        }
    }
    return params
}

const PosService = {
    async branches(): Promise<PosBranch[]> {
        const response = await api.get<BranchesApiResponse>('/branches')
        return response.data.data
    },

    async products(filters: PosProductFilters): Promise<PosProductsResponse> {
        const response = await api.get<PosProductsResponse>('/pos/products', {
            params: buildParams(
                filters as unknown as Record<string, string | number | undefined>,
            ),
        })
        return response.data
    },

    async checkout(payload: PosCheckoutPayload): Promise<PosCheckoutResponse> {
        const response = await api.post<PosCheckoutResponse>('/pos/checkout', payload)
        return response.data
    },

    async receipt(orderId: string): Promise<PosReceiptResponse> {
        const response = await api.get<PosReceiptResponse>(`/pos/orders/${orderId}/receipt`)
        return response.data
    },
}

export default PosService
