/**
 * Billing domain types — mirror the API resources:
 *  - SubscriptionResource (GET /account/billing → data.subscription)
 *  - InvoiceResource (GET /account/billing/invoices → data[])
 *  - SubscribeController (POST /account/billing/subscribe → data)
 */

export interface BillingPlanRef {
    id: number
    name: string
    slug: string
}

export interface Subscription {
    id: number
    status: string
    status_label: string
    plan?: BillingPlanRef
    amount_cents: number
    currency: string
    billing_period: string | null
    trial_ends_at: string | null
    current_period_end: string | null
    next_billing_at: string | null
    cancel_at_period_end: boolean
    card_last4: string | null
    card_brand: string | null
    affiliation_url: string | null
    affiliation_qr_url: string | null
}

export interface Invoice {
    id: number
    number: string
    status: string
    subtotal_cents: number
    tax_cents: number
    total_cents: number
    currency: string
    issued_at: string | null
    paid_at: string | null
    has_pdf: boolean
}

export interface BillingOverview {
    subscription: Subscription | null
    recent_invoices: Invoice[]
}

export interface SubscribeResult {
    affiliation_url: string | null
    affiliation_qr_url: string | null
    status: string
}
