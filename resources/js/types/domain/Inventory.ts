export interface InventoryBranch {
    id: string
    name: string
    slug: string
    is_main: boolean
}

export interface InventoryProductVariant {
    id: number
    sku: string
    product_id: number
    options: Record<string, string>
    product?: {
        id: number
        name: string
        default_image_url: string | null
    }
}

export interface BranchInventory {
    id: number
    tenant_id: string
    branch_id: string
    product_variant_id: number
    quantity: number
    reserved: number
    available: number
    branch?: InventoryBranch
    product_variant?: InventoryProductVariant
    created_at: string
    updated_at: string
}

export type MovementType = 'entry' | 'exit' | 'adjustment' | 'transfer'

export interface InventoryMovement {
    id: number
    tenant_id: string
    branch_id: string
    product_variant_id: number
    type: MovementType
    quantity: number
    reference_type: string | null
    reference_id: string | null
    notes: string | null
    user_id: number | null
    user?: { id: number; name: string }
    branch?: { id: string; name: string }
    product_variant?: {
        id: number
        sku: string
        product?: { id: number; name: string }
    }
    created_at: string
}

export interface RecordMovementInput {
    branch_id: string
    product_variant_id: number
    type: 'entry' | 'exit' | 'adjustment'
    quantity: number
    notes?: string
}

export interface TransferStockInput {
    from_branch_id: string
    to_branch_id: string
    product_variant_id: number
    quantity: number
    notes?: string
}
