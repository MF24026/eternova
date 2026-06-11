import api from './api'
import type { Resource } from '@/types/api'
import type { OrderTracking } from '@/types/domain/Tracking'

/**
 * Public tracking API — no auth required.
 *
 * Uses the shared axios instance (withCredentials, CSRF-exempt for GETs) but
 * the server-side route is unguarded (no auth:sanctum). A 404 propagates so
 * the calling page can distinguish "not found" from other errors.
 */
const PublicTrackingService = {
    async track(token: string): Promise<OrderTracking> {
        const response = await api.get<Resource<OrderTracking>>(`/track/${token}`)
        return response.data.data
    },
}

export default PublicTrackingService
