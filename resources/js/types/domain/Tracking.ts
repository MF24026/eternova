import type { OrderStatus } from './Order'

// Public brand payload included with every tracking response.
// Contains only presentation data — no internal IDs or private config.
export interface TrackingBrand {
    slug: string
    business_name: string
    logo_url: string | null
    primary_color: string | null
    secondary_color: string | null
    favicon_url: string | null
    currency: string
    country_code: string
    language: string
    whatsapp_number: string | null
    tagline: string | null
}

// A single entry in the public timeline — internal notes and user IDs stripped.
export interface TrackingTimelineEntry {
    status: OrderStatus
    at: string       // ISO-8601
}

// Root shape returned by GET /api/v1/track/{token}.
// Intentionally minimal — no customer PII, no item prices.
export interface OrderTracking {
    order_number: string
    status: OrderStatus
    branch_name: string
    created_at: string  // ISO-8601
    timeline: TrackingTimelineEntry[]
    brand: TrackingBrand
}
