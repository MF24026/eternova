import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type {
    Quotation,
    QuotationListFilters,
    CreateQuotationPayload,
    UpdateQuotationPayload,
    QuotationTransitionPayload,
    AcceptQuotationPayload,
} from '@/types/domain/Quotation'

// The index endpoint returns a standard paginated envelope PLUS a top-level
// `status_counts` field — a map of QuotationStatus → count for all items
// matching the current tenant (not just the current page). The backend controller
// uses ->additional(['status_counts' => ...]), which places it as a sibling of
// `data` / `meta` / `links` in the JSON response (not nested inside `meta`).
interface QuotationsIndexResponse extends Paginated<Quotation> {
    status_counts: Record<string, number>
}

export interface QuotationListResult extends Paginated<Quotation> {
    status_counts: Record<string, number>
}

// Strip undefined and empty-string values so we don't send spurious query params
// (same buildParams pattern used by ExpenseService and ReservationService).
function buildParams(
    filters: Record<string, string | number | boolean | undefined>,
): Record<string, string | number | boolean> {
    const params: Record<string, string | number | boolean> = {}
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '') {
            params[key] = value
        }
    }
    return params
}

const QuotationService = {
    /**
     * Paginated quotation list. Returns the standard Paginated<Quotation> envelope
     * with an extra `status_counts` map — the count per status across the full
     * filtered set (used for status tabs with count badges).
     */
    async list(filters: QuotationListFilters = {}): Promise<QuotationListResult> {
        const response = await api.get<QuotationsIndexResponse>('/quotations', {
            params: buildParams(filters as Record<string, string | number | boolean | undefined>),
        })
        const { data, links, meta, status_counts } = response.data
        return { data, links, meta, status_counts }
    },

    /**
     * Full quotation detail — includes `items`, `customer`, `status_history`,
     * and `allowed_transitions` loaded from GET /quotations/:id.
     */
    async get(id: number | string): Promise<Quotation> {
        const response = await api.get<Resource<Quotation>>(`/quotations/${id}`)
        return response.data.data
    },

    // ── Write operations (E7 builder consumes these) ──────────────────────────

    /** Create a new quotation. Returns the created quotation (201). */
    async create(payload: CreateQuotationPayload): Promise<Quotation> {
        const response = await api.post<Resource<Quotation>>('/quotations', payload)
        return response.data.data
    },

    /** Update an existing quotation. Items are fully replaced on update. */
    async update(id: number | string, payload: UpdateQuotationPayload): Promise<Quotation> {
        const response = await api.patch<Resource<Quotation>>(`/quotations/${id}`, payload)
        return response.data.data
    },

    /** Soft-delete a quotation (204 — no body). */
    async remove(id: number | string): Promise<void> {
        await api.delete(`/quotations/${id}`)
    },

    // ── Status transitions (E8 consumes these) ────────────────────────────────

    /** Transition draft|sent → sent. */
    async send(id: number | string, payload: QuotationTransitionPayload = {}): Promise<Quotation> {
        const response = await api.post<Resource<Quotation>>(`/quotations/${id}/send`, payload)
        return response.data.data
    },

    /**
     * Transition draft|sent → accepted.
     * Pass { convert_to_order: true } to simultaneously create an Order from the
     * quotation lines (sets converted_order_id on the returned resource).
     */
    async accept(id: number | string, payload: AcceptQuotationPayload = {}): Promise<Quotation> {
        const response = await api.post<Resource<Quotation>>(`/quotations/${id}/accept`, payload)
        return response.data.data
    },

    /** Transition draft|sent → rejected. */
    async reject(id: number | string, payload: QuotationTransitionPayload = {}): Promise<Quotation> {
        const response = await api.post<Resource<Quotation>>(`/quotations/${id}/reject`, payload)
        return response.data.data
    },

    // ── PDF (E8 builds the download button UI) ────────────────────────────────

    /**
     * Returns the relative URL for the PDF download endpoint.
     * The SPA is served from the tenant subdomain so a relative /api/v1/...
     * URL is same-origin — no need for an absolute URL.
     *
     * Usage: window.open(QuotationService.pdfUrl(id))
     */
    pdfUrl(id: number | string): string {
        return `/api/v1/quotations/${id}/pdf`
    },
}

export default QuotationService
