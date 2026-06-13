// Tenant settings — shape returned by GET /api/v1/settings and accepted by
// POST /api/v1/settings/{group}. Mirrors SettingsService::resolveAll() on the backend.

export type SettingsGroup =
    | 'brand'
    | 'locale'
    | 'contact'
    | 'tax'
    | 'orders'
    | 'quotations'
    | 'reservations'
    | 'notifications'

export interface BrandSettings {
    business_name: string
    primary_color: string | null
    secondary_color: string | null
    logo_url: string | null
    favicon_url: string | null
}

export interface LocaleSettings {
    currency: string
    country_code: string
    language: string
    timezone: string
}

export interface ContactSettings {
    phone: string | null
    email: string | null
    website: string | null
    address: string | null
}

export interface TaxSettings {
    enabled: boolean
    rate_bps: number
    id_label: string | null
    id_number: string | null
}

export interface OrdersSettings {
    auto_confirm: boolean
    default_prep_minutes: number
    pending_alert_hours: number
}

export interface QuotationSettings {
    quotation_tax_rate_bps: number
    quotation_valid_days: number
    quotation_terms: string | null
}

export interface ReservationSettings {
    reservation_deposit_pct: number
    reservation_occasions: string[]
}

export interface NotificationSettings {
    new_order: boolean
    order_pending: boolean
    low_stock: boolean
    reservation_confirmed: boolean
    quotation_accepted: boolean
    payment_received: boolean
}

export interface TenantSettings {
    brand: BrandSettings
    locale: LocaleSettings
    contact: ContactSettings
    tax: TaxSettings
    orders: OrdersSettings
    quotations: QuotationSettings
    reservations: ReservationSettings
    notifications: NotificationSettings
}

// ── Catalog (option lists for the UI, country → currency/dial defaults) ─────────

export interface CountryCatalogEntry {
    name: string
    currency: string
    dial_code: string
    tax_id_label: string
}

export interface SettingsCatalog {
    countries: Record<string, CountryCatalogEntry>
    currencies: string[]
    languages: Record<string, string>
}

export interface SettingsMeta {
    groups: SettingsGroup[]
    catalog: SettingsCatalog
}
