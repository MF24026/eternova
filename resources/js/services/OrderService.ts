import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type {
    Order,
    OrderDetail,
    OrderListFilters,
    OrderStatus,
    StatusCounts,
} from '@/types/domain/Order'

// The index endpoint returns a standard paginated envelope PLUS a top-level
// `status_counts` field. We type the raw axios response explicitly so we can
// extract both pieces without losing typing.
interface OrdersIndexResponse extends Paginated<Order> {
    status_counts: StatusCounts
}

export interface OrderListResult extends Paginated<Order> {
    status_counts: StatusCounts
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

const OrderService = {
    /**
     * Paginated order list. Returns the standard Paginated<Order> envelope
     * with an extra `status_counts` field that always reflects totals for
     * ALL statuses, ignoring the active `status` filter so tab counters stay accurate.
     */
    async list(filters: OrderListFilters = {}): Promise<OrderListResult> {
        const response = await api.get<OrdersIndexResponse>('/orders', {
            params: buildParams(filters as Record<string, string | number | boolean | undefined>),
        })
        const { data, links, meta, status_counts } = response.data
        return { data, links, meta, status_counts }
    },

    /** Full order detail including items, history, customer, branch, assignee. */
    async get(id: string): Promise<OrderDetail> {
        const response = await api.get<Resource<OrderDetail>>(`/orders/${id}`)
        return response.data.data
    },

    /**
     * Advance or change the order status.
     * Corresponds to PATCH /api/v1/orders/{id}/status.
     * Returns the full OrderDetail so the detail page can replace its state in-place.
     */
    async transition(id: string, status: OrderStatus, note?: string): Promise<OrderDetail> {
        const response = await api.patch<Resource<OrderDetail>>(`/orders/${id}/status`, {
            status,
            ...(note !== undefined ? { note } : {}),
        })
        return response.data.data
    },

    /**
     * Assign (or un-assign) a staff member to an order.
     * Corresponds to PATCH /api/v1/orders/{id}/assignee
     * Pass null to un-assign.
     * Returns the full OrderDetail so the detail page can replace its state in-place.
     */
    async assign(id: string, assignedTo: number | null): Promise<OrderDetail> {
        const response = await api.patch<Resource<OrderDetail>>(`/orders/${id}/assignee`, {
            assigned_to: assignedTo,
        })
        return response.data.data
    },

    /**
     * Cancel an order via the dedicated endpoint.
     * Corresponds to POST /api/v1/orders/{id}/cancel — the backend records the
     * cancellation in the timeline and enforces the "cannot cancel delivered" guard.
     * Returns the full OrderDetail so the detail page can replace its state in-place.
     */
    async cancel(id: string): Promise<OrderDetail> {
        const response = await api.post<Resource<OrderDetail>>(`/orders/${id}/cancel`)
        return response.data.data
    },
}

export default OrderService
