import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type {
    Reservation,
    ReservationDetail,
    ReservationListFilters,
    ReservationStatus,
    ReservationStatusCounts,
    CreateReservationPayload,
} from '@/types/domain/Reservation'

// The index endpoint returns a standard paginated envelope PLUS a top-level
// `status_counts` field. We type the raw axios response so we can extract both
// without losing type safety. Mirrors the OrderService pattern exactly.
interface ReservationsIndexResponse extends Paginated<Reservation> {
    status_counts: ReservationStatusCounts
}

export interface ReservationListResult extends Paginated<Reservation> {
    status_counts: ReservationStatusCounts
}

// Reservation settings shape returned by GET /api/v1/reservations/settings.
export interface ReservationSettings {
    deposit_pct: number
    occasions: string[]
}

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

const ReservationService = {
    /**
     * Paginated reservation list. Returns the standard Paginated<Reservation> envelope
     * with an extra `status_counts` field that always reflects totals for ALL statuses,
     * ignoring the active `status` filter so tab and board counters stay accurate.
     */
    async list(filters: ReservationListFilters = {}): Promise<ReservationListResult> {
        const response = await api.get<ReservationsIndexResponse>('/reservations', {
            params: buildParams(filters as Record<string, string | number | boolean | undefined>),
        })
        const { data, links, meta, status_counts } = response.data
        return { data, links, meta, status_counts }
    },

    /** Full reservation detail including payments, status_history, and all relations. */
    async get(id: number | string): Promise<ReservationDetail> {
        const response = await api.get<Resource<ReservationDetail>>(`/reservations/${id}`)
        return response.data.data
    },

    /** Create a new reservation. */
    async create(payload: CreateReservationPayload): Promise<ReservationDetail> {
        const response = await api.post<Resource<ReservationDetail>>('/reservations', payload)
        return response.data.data
    },

    /**
     * Advance or change the reservation status.
     * PATCH /api/v1/reservations/{id}/status
     * Returns the full ReservationDetail so the caller can replace state in-place.
     * On invalid transition the API returns 422 with { message, error_code }.
     */
    async transition(
        id: number | string,
        status: ReservationStatus,
        note?: string,
        force?: boolean,
    ): Promise<ReservationDetail> {
        const response = await api.patch<Resource<ReservationDetail>>(
            `/reservations/${id}/status`,
            {
                status,
                ...(note !== undefined ? { note } : {}),
                ...(force ? { force: true } : {}),
            },
        )
        return response.data.data
    },

    /**
     * Record a payment against the reservation.
     * POST /api/v1/reservations/{id}/payments
     * Returns the updated detail so the caller can refresh in-place.
     */
    async recordPayment(
        id: number | string,
        payload: {
            amount_cents: number
            payment_method: 'cash' | 'card' | 'transfer' | 'other'
            reference?: string | null
            paid_at?: string
        },
    ): Promise<ReservationDetail> {
        const response = await api.post<Resource<ReservationDetail>>(
            `/reservations/${id}/payments`,
            payload,
        )
        return response.data.data
    },

    /**
     * Convert a delivered reservation to an Order.
     * POST /api/v1/reservations/{id}/convert
     * Idempotent — guard on backend via converted_order_id.
     */
    async convert(id: number | string): Promise<ReservationDetail> {
        const response = await api.post<Resource<ReservationDetail>>(
            `/reservations/${id}/convert`,
        )
        return response.data.data
    },

    /**
     * Cancel a reservation via the dedicated endpoint.
     * POST /api/v1/reservations/{id}/cancel
     * Returns the updated detail so the caller can replace state in-place.
     */
    async cancel(id: number | string): Promise<ReservationDetail> {
        const response = await api.post<Resource<ReservationDetail>>(
            `/reservations/${id}/cancel`,
        )
        return response.data.data
    },

    /**
     * Get the tenant's reservation settings (deposit_pct, occasions list).
     * GET /api/v1/reservations/settings
     * Used by E7 to populate the settings UI.
     */
    async getSettings(): Promise<ReservationSettings> {
        const response = await api.get<Resource<ReservationSettings>>('/reservations/settings')
        return response.data.data
    },

    /**
     * Update the tenant's reservation settings.
     * PUT /api/v1/reservations/settings
     * Returns the updated settings object.
     */
    async updateSettings(payload: Partial<ReservationSettings>): Promise<ReservationSettings> {
        const response = await api.put<Resource<ReservationSettings>>(
            '/reservations/settings',
            payload,
        )
        return response.data.data
    },
}

export default ReservationService
