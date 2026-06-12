// OCR processing statuses for a receipt-linked expense.
export type ExpenseOcrStatus = 'none' | 'pending' | 'processing' | 'done' | 'failed'

// Category taxonomy — matches the backend enum.
export type ExpenseCategoryType = 'operating' | 'products' | 'payroll' | 'rent' | 'other'

// Payment method options — matches the backend enum.
export type ExpensePaymentMethod = 'cash' | 'card' | 'transfer' | 'other'

// ── Lightweight relations ──────────────────────────────────────────────────

export interface ExpenseCategory {
    id: number
    name: string
    type: ExpenseCategoryType
    is_active: boolean
}

export interface ExpenseBranch {
    id: string
    name: string
}

export interface ExpenseCreator {
    id: number
    name: string
}

// ── Main Expense shape (list + detail) ────────────────────────────────────

export interface Expense {
    id: number
    description: string
    amount_cents: number
    expense_date: string           // ISO date YYYY-MM-DD
    vendor: string | null
    payment_method: ExpensePaymentMethod | null
    ocr_status: ExpenseOcrStatus
    is_verified: boolean
    ocr_data: Record<string, unknown> | null
    receipt_url: string | null
    notes: string | null
    created_at: string
    updated_at: string
    // Relations — always present on list/show endpoints (eager-loaded)
    category: ExpenseCategory | null
    branch: ExpenseBranch | null
    creator: ExpenseCreator | null
}

// ── Filters accepted by GET /api/v1/expenses ─────────────────────────────

export interface ExpenseListFilters {
    expense_category_id?: number | string
    branch_id?: string
    date_from?: string             // YYYY-MM-DD
    date_to?: string               // YYYY-MM-DD
    month?: string                 // YYYY-MM
    ocr_status?: ExpenseOcrStatus
    is_verified?: 0 | 1
    search?: string                // matches vendor or description
    per_page?: number
    page?: number
}

// ── Payloads for write operations ─────────────────────────────────────────

export interface CreateExpensePayload {
    description: string
    amount_cents: number
    expense_date: string
    expense_category_id?: number | null
    branch_id?: string | null
    vendor?: string | null
    payment_method?: ExpensePaymentMethod | null
    notes?: string | null
}

// PATCH accepts a partial subset of the create payload.
export type UpdateExpensePayload = Partial<CreateExpensePayload>

export interface CreateExpenseCategoryPayload {
    name: string
    type: ExpenseCategoryType
    is_active?: boolean
}

export type UpdateExpenseCategoryPayload = Partial<CreateExpenseCategoryPayload>
