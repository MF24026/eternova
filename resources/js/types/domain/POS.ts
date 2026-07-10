import type { Paginated } from '@/types/api'
import type { TaxConfig } from '@/components/Admin/Pos/computeTax'

export type { TaxConfig }

export type PosPaymentMethod = 'cash' | 'card' | 'transfer' | 'other'

export interface CashMovement {
    id: number
    type: 'in' | 'out'
    amount_cents: number
    reason: string
    created_at: string | null
}

export interface CashRegisterSession {
    id: number
    branch_id: string
    user_id: number
    session_number: number
    status: 'open' | 'closed'
    opening_amount_cents: number
    closing_amount_cents: number | null
    expected_amount_cents: number | null
    difference_cents: number | null
    cash_sales_cents: number
    card_sales_cents: number
    transfer_sales_cents: number
    total_sales_cents: number
    order_count: number
    cash_in_cents: number
    cash_out_cents: number
    expected_cash_cents: number
    movements: CashMovement[]
    opened_at: string | null
    closed_at: string | null
    opening_notes: string | null
    closing_notes: string | null
}

export interface PosBranch {
    id: string
    name: string
    slug: string
    address: string | null
    phone: string | null
    is_main: boolean
    is_active: boolean
}

export interface PosProductVariant {
    id: number
    sku: string
    price_cents: number
    options: Record<string, string>
    image_url: string | null
    available_quantity: number
    in_stock: boolean
}

export interface PosProductCategory {
    id: number
    name: string
    slug: string
}

export interface PosProduct {
    id: number
    name: string
    slug: string
    sku_root: string | null
    base_price_cents: number
    default_image_url: string | null
    is_active: boolean
    categories: PosProductCategory[]
    variants: PosProductVariant[]
}

// ── Cart ─────────────────────────────────────────────────────────────────────

export interface PosCartLine {
    variantId: number
    productId: number
    productName: string
    variantOptions: Record<string, string>
    priceCents: number
    imageUrl: string | null
    quantity: number
    availableQuantity: number
}

// ── Checkout ─────────────────────────────────────────────────────────────────

export interface PosCheckoutPayload {
    branch_id: string
    items: Array<{ product_variant_id: number; quantity: number }>
    payment_method: PosPaymentMethod
    /** Cash tendered by the customer (cents). Only sent for cash sales. */
    amount_received_cents?: number | null
    customer_id?: number
    notes?: string
}

export interface PosOrderItem {
    id: number
    product_variant_id: number
    quantity: number
    unit_price_cents: number
    subtotal_cents: number
}

export interface PosOrder {
    // Orders use a ULID primary key (human-readable, sortable by creation time).
    id: string
    order_number: string
    status: string
    payment_method: PosPaymentMethod
    subtotal_cents: number
    tax_cents: number
    discount_cents: number
    total_cents: number
    items: PosOrderItem[]
    customer: { id: number; name: string } | null
}

export interface PosCheckoutResponse {
    data: PosOrder
}

// ── Receipt ───────────────────────────────────────────────────────────────────

export interface PosReceiptBusiness {
    name: string
    logo_url: string | null
    primary_color: string | null
}

export interface PosReceiptBranch {
    name: string
    address: string | null
    phone: string | null
}

export interface PosReceiptCustomer {
    id: number
    name: string
}

export interface PosReceiptCashier {
    id: number
    name: string
}

export interface PosReceiptItem {
    name: string
    /** Key-value pairs of the selected variant options, e.g. { "Color": "Rojo" } */
    variant_options: Record<string, string>
    sku: string | null
    quantity: number
    unit_price_cents: number
    total_cents: number
}

export interface PosReceipt {
    order_number: string
    created_at: string          // ISO-8601
    status: string
    payment_method: PosPaymentMethod
    payment_status: string
    business: PosReceiptBusiness
    branch: PosReceiptBranch | null
    customer: PosReceiptCustomer | null
    cashier: PosReceiptCashier
    items: PosReceiptItem[]
    subtotal_cents: number
    tax_cents: number
    discount_cents: number
    total_cents: number
    amount_received_cents: number | null
    change_cents: number | null
}

export interface PosReceiptResponse {
    data: PosReceipt
}

// ── Products endpoint response (includes top-level tax config) ───────────────

export interface PosProductsResponse extends Paginated<PosProduct> {
    tax: TaxConfig
}
