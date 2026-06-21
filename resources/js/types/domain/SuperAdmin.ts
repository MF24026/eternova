/**
 * SuperAdmin (platform operator) domain types — mirror the 7a API
 * (`/api/v1/super-admin/*`, gated by is_super_admin). These are NOT tenant-scoped.
 */

export interface BillingMetrics {
    mrr_cents: number
    arpu_cents: number
    total_tenants: number
    /** status => count, across all tenants */
    counts_by_status: Record<string, number>
    /** plan name => count of active subscriptions */
    plan_distribution: Record<string, number>
    /** 0..1 churn rate */
    churn_rate: number
}

export interface SuperAdminSubscriptionSummary {
    id: number
    status: string
    plan: string | null
    amount_cents: number
    currency: string
    trial_ends_at: string | null
    current_period_end: string | null
}

export interface SuperAdminTenantRow {
    id: string
    name: string
    slug: string
    status: string
    subscription: SuperAdminSubscriptionSummary | null
}

export interface SuperAdminAuditEntry {
    id: number
    event_type: string
    payload: Record<string, unknown> | null
    occurred_at: string | null
}

export interface SuperAdminTenantDetail {
    id: string
    name: string
    slug: string
    status: string
    email: string | null
    subscription: SuperAdminSubscriptionSummary | null
    audit_log: SuperAdminAuditEntry[]
}
