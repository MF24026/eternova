import api from './api'
import type { Paginated, Resource } from '@/types/api'
import type {
    Expense,
    ExpenseCategory,
    ExpenseListFilters,
    CreateExpensePayload,
    UpdateExpensePayload,
    CreateExpenseCategoryPayload,
    UpdateExpenseCategoryPayload,
} from '@/types/domain/Expense'

// The index endpoint returns a standard paginated envelope PLUS a top-level
// `period_total_cents` field — the sum of all matching expenses for the filtered set.
interface ExpensesIndexResponse extends Paginated<Expense> {
    period_total_cents: number
}

export interface ExpenseListResult extends Paginated<Expense> {
    period_total_cents: number
}

// Shape of the OCR status poll endpoint.
export interface OcrStatusResult {
    id: number
    ocr_status: string
    ocr_data: Record<string, unknown> | null
    is_verified: boolean
}

// Shape of an expense report (E8 builds the view; we implement the service method now).
export interface ExpenseReportParams {
    month?: string          // YYYY-MM
    date_from?: string
    date_to?: string
    expense_category_id?: number | string
    branch_id?: string
}

export interface ExpenseCategoryReport {
    category_id: number | null
    category_name: string
    type: string
    total_cents: number
    count: number
}

export interface ExpenseReport {
    period: { from: string; to: string }
    total_cents: number
    by_category: ExpenseCategoryReport[]
}

// Strip undefined and empty-string values from filter objects so we don't
// send spurious query params (same pattern as ReservationService.buildParams).
function buildParams(
    filters: Record<string, string | number | boolean | undefined>,
): Record<string, string | number | boolean> {
    const params: Record<string, string | number | boolean> = {}
    for (const [key, value] of Object.entries(filters)) {
        if (value !== undefined && value !== '') {
            params[key] = value
        }
    }
    return params
}

const ExpenseService = {
    /**
     * Paginated expense list. Returns the standard Paginated<Expense> envelope
     * with an extra `period_total_cents` field representing the sum of all
     * expenses matching the current filter set (used for the period total display).
     */
    async list(filters: ExpenseListFilters = {}): Promise<ExpenseListResult> {
        const response = await api.get<ExpensesIndexResponse>('/expenses', {
            params: buildParams(filters as Record<string, string | number | boolean | undefined>),
        })
        const { data, links, meta, period_total_cents } = response.data
        return { data, links, meta, period_total_cents }
    },

    /** Full expense detail. */
    async get(id: number | string): Promise<Expense> {
        const response = await api.get<Resource<Expense>>(`/expenses/${id}`)
        return response.data.data
    },

    /** Create a new expense manually. Returns the created expense (201). */
    async create(payload: CreateExpensePayload): Promise<Expense> {
        const response = await api.post<Resource<Expense>>('/expenses', payload)
        return response.data.data
    },

    /** Update an existing expense. Returns the updated expense. */
    async update(id: number | string, payload: UpdateExpensePayload): Promise<Expense> {
        const response = await api.patch<Resource<Expense>>(`/expenses/${id}`, payload)
        return response.data.data
    },

    /** Delete an expense (204 — no body). */
    async remove(id: number | string): Promise<void> {
        await api.delete(`/expenses/${id}`)
    },

    // ── Receipt / OCR ──────────────────────────────────────────────────────

    /**
     * Upload a receipt image/PDF for OCR processing.
     * POST /api/v1/expenses/receipt (multipart/form-data)
     * Returns the newly created draft Expense with ocr_status = 'pending'.
     * E7 builds the UI that calls this; implementing now so E7 only needs the UI.
     */
    async uploadReceipt(file: File, branchId?: string | null): Promise<Expense> {
        const form = new FormData()
        form.append('receipt', file)
        if (branchId) {
            form.append('branch_id', branchId)
        }

        const response = await api.post<Resource<Expense>>('/expenses/receipt', form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        return response.data.data
    },

    /**
     * Poll the OCR processing status for a specific expense.
     * GET /api/v1/expenses/{id}/ocr-status
     * E7 polls this until ocr_status transitions to 'done' or 'failed'.
     */
    async ocrStatus(id: number | string): Promise<OcrStatusResult> {
        const response = await api.get<{ data: OcrStatusResult }>(`/expenses/${id}/ocr-status`)
        return response.data.data
    },

    // ── Categories ─────────────────────────────────────────────────────────

    /** List all expense categories for the current tenant. */
    async listCategories(): Promise<ExpenseCategory[]> {
        const response = await api.get<{ data: ExpenseCategory[] }>('/expenses/categories')
        return response.data.data
    },

    /** Create a new expense category. */
    async createCategory(payload: CreateExpenseCategoryPayload): Promise<ExpenseCategory> {
        const response = await api.post<Resource<ExpenseCategory>>('/expenses/categories', payload)
        return response.data.data
    },

    /** Update an existing expense category. */
    async updateCategory(
        id: number | string,
        payload: UpdateExpenseCategoryPayload,
    ): Promise<ExpenseCategory> {
        const response = await api.patch<Resource<ExpenseCategory>>(
            `/expenses/categories/${id}`,
            payload,
        )
        return response.data.data
    },

    /**
     * Delete an expense category.
     * The API returns 422 with { error_code: 'expenses.category_in_use' }
     * when the category still has associated expenses. The UI handles this
     * by showing a toast that guides the user to deactivate instead.
     */
    async removeCategory(id: number | string): Promise<void> {
        await api.delete(`/expenses/categories/${id}`)
    },

    // ── Report (E8 consumes the view; the service method is ready now) ──────

    /**
     * Fetch the expense report for a given period.
     * GET /api/v1/expenses/report
     * E8 builds the report page; this method is here so E8 only adds a Vue page.
     */
    async report(params: ExpenseReportParams = {}): Promise<ExpenseReport> {
        const response = await api.get<{ data: ExpenseReport }>('/expenses/report', {
            params: buildParams(params as Record<string, string | number | boolean | undefined>),
        })
        return response.data.data
    },
}

export default ExpenseService
