import api from './api'
import type { BillingOverview, Invoice, SubscribeResult } from '@/types/domain/Billing'

/**
 * Tenant billing surface (Owner-only — the API enforces the billing.manage gate and 403s the
 * rest). Activation is webhook-driven: after the owner affiliates their card on the Wompi
 * hosted page, Wompi charges and the overview reflects `active` once the webhook lands.
 */
const BillingService = {
    async overview(): Promise<BillingOverview> {
        const res = await api.get<{ data: BillingOverview }>('/account/billing')
        return res.data.data
    },

    /** Create the Wompi recurring link for the plan; returns the hosted affiliation URL/QR. */
    async subscribe(planId: number): Promise<SubscribeResult> {
        const res = await api.post<{ data: SubscribeResult }>('/account/billing/subscribe', {
            plan_id: planId,
        })
        return res.data.data
    },

    /** Cancel at period end (the tenant keeps access until current_period_end). */
    async cancel(): Promise<void> {
        await api.post('/account/billing/cancel')
    },

    async invoices(): Promise<Invoice[]> {
        const res = await api.get<{ data: Invoice[] }>('/account/billing/invoices')
        return res.data.data
    },

    /** Authenticated download URL for an invoice PDF (opened in a new tab). */
    downloadInvoiceUrl(invoiceId: number): string {
        return `/api/v1/account/billing/invoices/${invoiceId}/download`
    },
}

export default BillingService
