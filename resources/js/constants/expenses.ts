import type { BadgeVariant } from '@/constants/orders'
import type { ExpenseCategoryType, ExpenseOcrStatus, ExpensePaymentMethod } from '@/types/domain/Expense'

// Spanish display labels for expense category types.
export const EXPENSE_CATEGORY_TYPE_LABELS: Record<ExpenseCategoryType, string> = {
    operating: 'Operación',
    products: 'Productos',
    payroll: 'Nómina',
    rent: 'Renta',
    other: 'Otros',
}

// AppBadge variant for each category type.
export const EXPENSE_CATEGORY_TYPE_VARIANT: Record<ExpenseCategoryType, BadgeVariant> = {
    operating: 'info',
    products: 'primary',
    payroll: 'warning',
    rent: 'error',
    other: 'neutral',
}

// Spanish display labels for OCR statuses.
export const EXPENSE_OCR_STATUS_LABELS: Record<ExpenseOcrStatus, string> = {
    none: '',
    pending: 'Pendiente',
    processing: 'Procesando',
    done: 'Procesado',
    failed: 'Falló',
}

// AppBadge variant for each OCR status.
// 'none' has no badge — callers should skip rendering when status is 'none'.
export const EXPENSE_OCR_STATUS_VARIANT: Record<ExpenseOcrStatus, BadgeVariant> = {
    none: 'neutral',
    pending: 'warning',
    processing: 'info',
    done: 'success',
    failed: 'error',
}

// Spanish display labels for payment methods.
export const EXPENSE_PAYMENT_METHOD_LABELS: Record<ExpensePaymentMethod, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    other: 'Otro',
}

// All category types in display order (used for select options).
export const ALL_EXPENSE_CATEGORY_TYPES: ExpenseCategoryType[] = [
    'operating',
    'products',
    'payroll',
    'rent',
    'other',
]

// All payment methods for select options.
export const ALL_EXPENSE_PAYMENT_METHODS: ExpensePaymentMethod[] = [
    'cash',
    'card',
    'transfer',
    'other',
]
