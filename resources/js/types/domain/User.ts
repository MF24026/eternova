export interface UserTenantMembership {
    id: string
    slug: string
    business_name: string
    admin_theme: 'ethereal' | 'minimal'
    role: 'owner' | 'admin' | 'staff' | 'customer'
    joined_at: string
    is_current: boolean
}

export interface PlanInfo {
    slug: string
    name: string
    features: string[]
    // Enforceable entitlements: booleans (e.g. pdf_quotations, custom_domain) and
    // numeric caps (e.g. max_branches). null = unlimited.
    limits: Record<string, number | boolean | null>
}

export interface User {
    id: number
    name: string
    email: string
    avatar_url: string | null
    email_verified_at: string | null
    is_super_admin: boolean
    // Current tenant's plan entitlements (null = no active subscription).
    plan: PlanInfo | null
    tenants: UserTenantMembership[]
}
