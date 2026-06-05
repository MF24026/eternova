export interface Tenant {
    id: string
    slug: string
    name: string
    business_name: string
    logo_url: string | null
    primary_color: string | null
    secondary_color: string | null
    favicon_url: string | null
    currency: string
    country_code: string
    language: string
    timezone: string
    trial_ends_at: string | null
    status: 'active' | 'suspended' | 'cancelled'
}
