export interface UserTenantMembership {
    id: string
    slug: string
    business_name: string
    role: 'owner' | 'admin' | 'staff' | 'customer'
    joined_at: string
    is_current: boolean
}

export interface User {
    id: number
    name: string
    email: string
    avatar_url: string | null
    email_verified_at: string | null
    is_super_admin: boolean
    tenants: UserTenantMembership[]
}
