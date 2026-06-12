// Reservation statuses match the backend enum (English ids, display via RESERVATION_STATUS_LABELS).
export type ReservationStatus =
    | 'inquiry'
    | 'confirmed'
    | 'in_progress'
    | 'ready'
    | 'delivered'
    | 'cancelled'

// Lightweight relations eager-loaded on list/show endpoints.
export interface ReservationBranch {
    id: string
    name: string
}

export interface ReservationCustomer {
    id: number
    name: string
    phone: string | null
}

export interface ReservationAssignee {
    id: number
    name: string
}

export interface ReservationCreator {
    id: number
    name: string
}

// Payment record returned on the show endpoint (whenLoaded).
export interface ReservationPayment {
    id: number
    amount_cents: number
    payment_method: 'cash' | 'card' | 'transfer' | 'other'
    reference: string | null
    paid_at: string       // ISO-8601
    recorded_by: { id: number; name: string } | null
}

// Immutable timeline entry returned on the show endpoint (whenLoaded).
export interface ReservationStatusHistory {
    id: number
    from_status: ReservationStatus | null  // null = creation row
    to_status: ReservationStatus
    note: string | null
    user: { id: number; name: string } | null
    created_at: string
}

// List & board shape (branch, customer, assignee always eager-loaded on list).
export interface Reservation {
    id: number
    reservation_number: string
    status: ReservationStatus
    occasion: string | null
    description: string | null
    event_date: string | null           // ISO date YYYY-MM-DD or null
    total_cents: number
    deposit_required_cents: number
    deposit_paid_cents: number
    balance_cents: number
    deposit_outstanding_cents: number
    special_instructions: string | null
    admin_notes: string | null
    allowed_transitions: ReservationStatus[]
    converted_order_id: string | null   // ULID when delivered+converted
    created_at: string
    updated_at: string
    // Relations (always present on list endpoint via eager load)
    branch: ReservationBranch | null
    customer: ReservationCustomer | null
    assignee: ReservationAssignee | null
    creator: ReservationCreator | null
}

// Full detail shape (adds whenLoaded relations for the detail page).
export interface ReservationDetail extends Reservation {
    payments: ReservationPayment[]
    status_history: ReservationStatusHistory[]
}

// Filters accepted by GET /api/v1/reservations.
export interface ReservationListFilters {
    branch_id?: string
    status?: ReservationStatus
    customer_id?: number
    date_from?: string                  // YYYY-MM-DD — filters on event_date
    date_to?: string                    // YYYY-MM-DD
    search?: string                     // matches reservation_number
    per_page?: number
    page?: number
}

// Top-level status_counts object alongside the paginated envelope.
// Keys are all possible ReservationStatus values; value is a count >= 0.
// Counts reflect ALL filters except `status` so tab counters stay accurate.
export type ReservationStatusCounts = Record<ReservationStatus, number>

// Payload for POST /api/v1/reservations.
export interface CreateReservationPayload {
    customer_id?: number | null
    branch_id?: string | null
    occasion?: string | null
    description?: string | null
    event_date?: string | null
    total_cents: number
    deposit_required_cents?: number | null
    special_instructions?: string | null
    admin_notes?: string | null
    assigned_to?: number | null
}
