import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type {
    BranchInventory,
    InventoryMovement,
    RecordMovementInput,
    TransferStockInput,
} from '@/types/domain/Inventory'

export interface InventoryFilters {
    branch_id?: string
    product_variant_id?: number
    low_stock?: boolean
    search?: string
    per_page?: number
    page?: number
}

export interface MovementFilters {
    branch_id?: string
    product_variant_id?: number
    type?: 'entry' | 'exit' | 'adjustment' | 'transfer'
    from_date?: string
    to_date?: string
    reference_type?: string
    per_page?: number
    page?: number
}

function buildParams(filters: Record<string, string | number | boolean | undefined>): Record<string, string | number | boolean> {
    const params: Record<string, string | number | boolean> = {}
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '') {
            params[key] = value
        }
    }
    return params
}

const InventoryService = {
    async list(filters: InventoryFilters = {}): Promise<Paginated<BranchInventory>> {
        const response = await api.get<Paginated<BranchInventory>>('/inventory', {
            params: buildParams(filters as Record<string, string | number | boolean | undefined>),
        })
        return response.data
    },

    async get(id: number): Promise<BranchInventory> {
        const response = await api.get<Resource<BranchInventory>>(`/inventory/${id}`)
        return response.data.data
    },

    async recordMovement(payload: RecordMovementInput): Promise<InventoryMovement> {
        const response = await api.post<Resource<InventoryMovement>>('/inventory/movements', payload)
        return response.data.data
    },

    async transfer(payload: TransferStockInput): Promise<{ exit_movement: InventoryMovement; entry_movement: InventoryMovement }> {
        const response = await api.post<{ exit_movement: InventoryMovement; entry_movement: InventoryMovement }>(
            '/inventory/transfers',
            payload,
        )
        return response.data
    },

    async listMovements(filters: MovementFilters = {}): Promise<Paginated<InventoryMovement>> {
        const response = await api.get<Paginated<InventoryMovement>>('/inventory/movements', {
            params: buildParams(filters as Record<string, string | number | boolean | undefined>),
        })
        return response.data
    },
}

export default InventoryService
