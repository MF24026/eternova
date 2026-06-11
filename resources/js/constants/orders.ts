import type { OrderStatus } from '@/types/domain/Order'

// Spanish display labels for every order status value.
// Shared between OrdersPage (list) and OrderDetailPage (detail) so they
// cannot drift out of sync.
export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
    pending: 'Pendiente',
    preparing: 'Preparando',
    ready: 'Listo',
    dispatched: 'Despachado',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
}

// Maps each status to an AppBadge variant.
export type BadgeVariant = 'warning' | 'info' | 'primary' | 'success' | 'error' | 'neutral'

export const ORDER_STATUS_VARIANT: Record<OrderStatus, BadgeVariant> = {
    pending: 'warning',
    preparing: 'info',
    ready: 'primary',
    dispatched: 'info',
    delivered: 'success',
    cancelled: 'error',
}

// Spanish labels for source of origin.
export const ORDER_SOURCE_LABELS: Record<string, string> = {
    pos: 'POS',
    catalog: 'Catálogo',
    reservation: 'Reserva',
}

// Spanish labels for payment method.
export const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    other: 'Otro',
}

// Spanish labels for payment status.
export const PAYMENT_STATUS_LABELS: Record<string, string> = {
    pending: 'Sin pagar',
    partial: 'Parcial',
    paid: 'Pagado',
}

// AppBadge variant per payment status.
export const PAYMENT_STATUS_VARIANT: Record<string, BadgeVariant> = {
    pending: 'warning',
    partial: 'info',
    paid: 'success',
}

// Ordered list of statuses for visual rendering (stepper order).
export const STATUS_ORDER: OrderStatus[] = [
    'pending',
    'preparing',
    'ready',
    'dispatched',
    'delivered',
]
