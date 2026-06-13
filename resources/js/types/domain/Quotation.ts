// Lifecycle states that a Quotation can occupy — matches the backend enum.
export type QuotationStatus = 'draft' | 'sent' | 'accepted' | 'rejected' | 'expired'

// ── Relations ──────────────────────────────────────────────────────────────────

export interface QuotationCustomer {
    id: number
    name: string
    phone?: string | null
    email?: string | null
}

export interface QuotationUser {
    id: number
    name: string
}

// ── Line items ─────────────────────────────────────────────────────────────────

export interface QuotationItem {
    id: number
    quotation_id: number
    product_id: number | null
    description: string
    quantity: number
    unit_price_cents: number
    line_total_cents: number
    sort_order: number
}

// ── Status history (append-only log, one entry per transition) ─────────────────

export interface QuotationStatusHistoryEntry {
    id: number
    quotation_id: number
    from_status: QuotationStatus | null   // null = initial creation entry
    to_status: QuotationStatus
    user: QuotationUser | null             // null = system (expiry job)
    note: string | null
    created_at: string                     // ISO 8601 timestamp
}

// ── Main Quotation shape (list + detail) ───────────────────────────────────────

export interface Quotation {
    id: number
    quotation_number: string           // e.g. COT-2026-0001
    status: QuotationStatus
    issue_date: string                 // YYYY-MM-DD
    valid_until: string | null         // YYYY-MM-DD or null (no expiry)
    subtotal_cents: number
    discount_cents: number
    tax_rate_bps: number               // 1300 = 13 %
    tax_cents: number
    total_cents: number
    notes: string | null               // visible to the customer on the PDF
    terms: string | null               // terms & conditions section in the PDF
    branch_id: string | null
    converted_order_id: string | null  // set when accepted + converted to an Order
    created_at: string
    updated_at: string
    // Relations — eager-loaded on list and detail endpoints
    customer: QuotationCustomer | null
    // Detail-only fields (present on GET /quotations/:id, not on the list)
    items?: QuotationItem[]
    status_history?: QuotationStatusHistoryEntry[]
    allowed_transitions?: QuotationStatus[]
}

// ── Filters accepted by GET /api/v1/quotations ────────────────────────────────

export interface QuotationListFilters {
    status?: QuotationStatus
    customer_id?: number | string
    date_from?: string                 // YYYY-MM-DD (issue_date range start)
    date_to?: string                   // YYYY-MM-DD (issue_date range end)
    search?: string                    // matches quotation_number or customer name
    per_page?: number
    page?: number
}

// ── Payloads for write operations ─────────────────────────────────────────────

export interface QuotationItemPayload {
    product_id?: number | null
    description: string
    quantity: number
    unit_price_cents: number
    sort_order?: number
}

export interface CreateQuotationPayload {
    customer_id?: number | null
    branch_id?: string | null
    issue_date: string                 // YYYY-MM-DD
    valid_until?: string | null        // YYYY-MM-DD
    discount_cents?: number
    tax_rate_bps?: number
    notes?: string | null
    terms?: string | null
    items: QuotationItemPayload[]
}

// PATCH accepts a partial subset of the create payload — items replace the
// full set (backend drops all existing items and re-inserts).
export type UpdateQuotationPayload = Partial<Omit<CreateQuotationPayload, 'items'>> & {
    items?: QuotationItemPayload[]
}

// Payload for the send/accept/reject transition endpoints (note is optional).
export interface QuotationTransitionPayload {
    note?: string | null
}

// accept() can additionally request immediate Order conversion.
export interface AcceptQuotationPayload extends QuotationTransitionPayload {
    convert_to_order?: boolean
}
