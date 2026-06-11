// Order statuses match the backend enum (English ids, display in Spanish via ORDER_STATUS_LABELS).
export type OrderStatus =
    | 'pending'
    | 'preparing'
    | 'ready'
    | 'dispatched'
    | 'delivered'
    | 'cancelled'

// Source of origin for the order.
export type OrderSource = 'pos' | 'catalog' | 'reservation'

export type PaymentMethod = 'cash' | 'card' | 'transfer' | 'other' | null

export type PaymentStatus = 'pending' | 'partial' | 'paid'

// Lightweight relations eager-loaded on the list endpoint.
export interface OrderBranch {
    id: string
    name: string
}

export interface OrderCustomer {
    id: number
    name: string
    phone: string | null
}

export interface OrderAssignee {
    id: number
    name: string
}

export interface Order {
    id: string                       // ULID
    order_number: string
    status: OrderStatus
    source: OrderSource
    payment_method: PaymentMethod
    payment_status: PaymentStatus
    subtotal_cents: number
    tax_cents: number
    discount_cents: number
    total_cents: number
    notes: string | null
    // Public share link token — present when the backend has S4-E4 deployed.
    // null when not yet generated or for pre-migration rows.
    tracking_token: string | null
    allowed_transitions: OrderStatus[]
    created_at: string               // ISO-8601
    updated_at: string
    // whenLoaded relations (always present on list endpoint)
    branch: OrderBranch | null
    customer: OrderCustomer | null
    assignee: OrderAssignee | null
}

// Full order returned by the show endpoint (GET /api/v1/orders/{id}).
// Extends the base Order with relations that are only loaded on the detail view.
export interface OrderDetail extends Order {
    items: OrderItem[]
    status_history: OrderStatusHistory[]
}

// Used on the detail page (E6) — line items. Shape matches OrderItemResource.
export interface OrderItem {
    id: number
    product_variant_id: number | null
    quantity: number
    unit_price_cents: number
    total_cents: number
    product_snapshot: {
        name: string | null
        variant_options: Record<string, string>
        sku: string | null
    }
}

// Used on the detail page (E6) — immutable timeline entry.
// Shape matches OrderStatusHistoryResource (user eager-loaded on show).
export interface OrderStatusHistory {
    id: number
    from_status: OrderStatus | null   // null = initial creation row
    to_status: OrderStatus
    note: string | null
    user: { id: number; name: string } | null
    created_at: string
}

// Filters accepted by the list endpoint.
export interface OrderListFilters {
    branch_id?: string
    status?: OrderStatus
    customer_id?: number
    date_from?: string               // YYYY-MM-DD
    date_to?: string                 // YYYY-MM-DD
    search?: string                  // matches order_number
    per_page?: number
    page?: number
}

// Top-level object alongside the paginated envelope from GET /api/v1/orders.
// Keys are all possible OrderStatus values; value is a count ≥ 0.
export type StatusCounts = Record<OrderStatus, number>
