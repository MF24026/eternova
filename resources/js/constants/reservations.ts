import type { ReservationStatus } from '@/types/domain/Reservation'
import type { BadgeVariant } from '@/constants/orders'

// Spanish display labels for every reservation status value.
// Shared between all reservation views (list, calendar, board, detail).
export const RESERVATION_STATUS_LABELS: Record<ReservationStatus, string> = {
    inquiry: 'Consulta',
    confirmed: 'Confirmada',
    in_progress: 'En proceso',
    ready: 'Lista',
    delivered: 'Entregada',
    cancelled: 'Cancelada',
}

// Maps each status to an AppBadge variant.
export const RESERVATION_STATUS_VARIANT: Record<ReservationStatus, BadgeVariant> = {
    inquiry: 'neutral',
    confirmed: 'info',
    in_progress: 'warning',
    ready: 'primary',
    delivered: 'success',
    cancelled: 'error',
}

// Board column order (active workflow columns). Cancelled is a separate secondary column.
export const RESERVATION_STATUS_ORDER: ReservationStatus[] = [
    'inquiry',
    'confirmed',
    'in_progress',
    'ready',
    'delivered',
]

// All statuses for the list tab rail (includes cancelled).
export const ALL_RESERVATION_STATUSES: ReservationStatus[] = [
    'inquiry',
    'confirmed',
    'in_progress',
    'ready',
    'delivered',
    'cancelled',
]

// Common occasion labels used as quick-pick suggestions.
// The tenant may override this list via their settings (E7).
export const DEFAULT_OCCASIONS: string[] = [
    'Boda',
    'Cumpleaños',
    'Aniversario',
    'Baby shower',
    'Graduación',
    'Corporativo',
    'Quinceañera',
    'Día de la madre',
    'San Valentín',
    'Otro',
]
