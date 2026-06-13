import type { BadgeVariant } from '@/constants/orders'
import type { QuotationStatus } from '@/types/domain/Quotation'

// Spanish display labels for each quotation status value.
export const QUOTATION_STATUS_LABELS: Record<QuotationStatus, string> = {
    draft: 'Borrador',
    sent: 'Enviada',
    accepted: 'Aceptada',
    rejected: 'Rechazada',
    expired: 'Vencida',
}

// Maps each status to an AppBadge variant.
export const QUOTATION_STATUS_VARIANT: Record<QuotationStatus, BadgeVariant> = {
    draft: 'neutral',
    sent: 'info',
    accepted: 'success',
    rejected: 'error',
    expired: 'warning',
}

// Ordered list of statuses for status-tab rendering (Todas precedes this array).
export const ALL_QUOTATION_STATUSES: QuotationStatus[] = [
    'draft',
    'sent',
    'accepted',
    'rejected',
    'expired',
]
