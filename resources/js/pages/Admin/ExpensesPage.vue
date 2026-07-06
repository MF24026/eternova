<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Plus, Settings, Receipt, Search, Upload, CheckCircle, BarChart2 } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppTable, { type TableColumn } from '@/components/base/AppTable.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppPagination from '@/components/base/AppPagination.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import ExpenseFormSlideover from '@/components/Admin/Expenses/ExpenseFormSlideover.vue'
import ExpenseCategoriesSlideover from '@/components/Admin/Expenses/ExpenseCategoriesSlideover.vue'
import ReceiptUploadSlideover from '@/components/Admin/Expenses/ReceiptUploadSlideover.vue'
import ReceiptVerificationSlideover from '@/components/Admin/Expenses/ReceiptVerificationSlideover.vue'
import ExpenseService from '@/services/ExpenseService'
import { useBranches } from '@/composables/useBranches'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import {
    EXPENSE_CATEGORY_TYPE_VARIANT,
    EXPENSE_OCR_STATUS_LABELS,
    EXPENSE_OCR_STATUS_VARIANT,
    EXPENSE_PAYMENT_METHOD_LABELS,
} from '@/constants/expenses'
import type { PaginatedMeta } from '@/composables/usePaginated'
import type { Expense, ExpenseCategory, ExpenseListFilters } from '@/types/domain/Expense'
import type { PaginatedMeta as ApiPaginatedMeta } from '@/types/api'

onMounted(() => {
    document.title = 'Gastos — Eternova'
    void Promise.all([loadBranches(), loadCategories(), fetchExpenses()])
})

const { branches, loadBranches } = useBranches()
const { formatCents } = useFormatCurrency()
const { formatDate } = useFormatDate()
const toast = useToast()
const { confirm } = useConfirm()

// ── Slideoveres ────────────────────────────────────────────────────────────────

const formSlideoverOpen = ref(false)
const editingExpense = ref<Expense | null>(null)
const categoriesSlideoverOpen = ref(false)

// Receipt upload + verification slideoveres (S6-E7)
const uploadSlideoverOpen = ref(false)
const verificationSlideoverOpen = ref(false)
const verifyingExpense = ref<Expense | null>(null)

function openCreateForm(): void {
    editingExpense.value = null
    formSlideoverOpen.value = true
}

function openEditForm(expense: Expense): void {
    editingExpense.value = expense
    formSlideoverOpen.value = true
}

function openUploadSlideover(): void {
    uploadSlideoverOpen.value = true
}

/** Called when the upload flow has a draft ready for OCR verification. */
function onReadyToVerify(expense: Expense): void {
    verifyingExpense.value = expense
    verificationSlideoverOpen.value = true
}

/** Called from a draft row's "Verificar" action button. */
function openVerifyDraft(expense: Expense): void {
    verifyingExpense.value = expense
    verificationSlideoverOpen.value = true
}

function onVerified(): void {
    verifyingExpense.value = null
    void fetchExpenses()
}

function onFormSuccess(): void {
    void fetchExpenses()
}

function onCategoriesChanged(): void {
    // Reload category select options whenever categories change
    void loadCategories()
}

// ── Categories (for the filter select) ───────────────────────────────────────

const categories = ref<ExpenseCategory[]>([])

async function loadCategories(): Promise<void> {
    try {
        categories.value = await ExpenseService.listCategories()
    } catch {
        categories.value = []
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────

// Default to the current month (YYYY-MM)
function currentMonthValue(): string {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

const selectedMonth = ref(currentMonthValue())
const selectedCategoryId = ref<number | ''>('')
const selectedBranchId = ref('')
const verifiedFilter = ref<'all' | 'verified' | 'draft'>('all')
const searchQuery = ref('')

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { void fetchExpenses() }, 300)
})

watch([selectedMonth, selectedCategoryId, selectedBranchId, verifiedFilter], () => {
    void fetchExpenses()
})

// ── Data ──────────────────────────────────────────────────────────────────────

const expenses = ref<Expense[]>([])
const periodTotalCents = ref(0)
const isLoading = ref(false)
const apiMeta = ref<ApiPaginatedMeta | null>(null)

async function fetchExpenses(page = 1): Promise<void> {
    isLoading.value = true
    try {
        const filters: ExpenseListFilters = {
            per_page: 20,
            page,
            ...(selectedMonth.value ? { month: selectedMonth.value } : {}),
            ...(selectedCategoryId.value !== '' ? { expense_category_id: selectedCategoryId.value } : {}),
            ...(selectedBranchId.value ? { branch_id: selectedBranchId.value } : {}),
            ...(verifiedFilter.value === 'verified' ? { is_verified: 1 as const } : {}),
            ...(verifiedFilter.value === 'draft' ? { is_verified: 0 as const } : {}),
            ...(searchQuery.value.trim() ? { search: searchQuery.value.trim() } : {}),
        }
        const result = await ExpenseService.list(filters)
        expenses.value = result.data
        apiMeta.value = result.meta
        periodTotalCents.value = result.period_total_cents
    } finally {
        isLoading.value = false
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────

async function deleteExpense(expense: Expense): Promise<void> {
    const confirmed = await confirm({
        title: 'Eliminar gasto',
        message: `"${expense.description}" se eliminara permanentemente. Esta acción no se puede deshacer.`,
        confirmLabel: 'Eliminar',
        variant: 'danger',
    })
    if (!confirmed) return

    try {
        await ExpenseService.remove(expense.id)
        toast.success('Gasto eliminado.')
        void fetchExpenses()
    } catch (err: unknown) {
        const apiErr = err as { response?: { data?: { message?: string } } }
        const msg = apiErr.response?.data?.message ?? 'No se pudo eliminar el gasto.'
        toast.error(msg)
    }
}

// ── Pagination adapter ────────────────────────────────────────────────────────

// AppPagination expects PaginatedMeta from usePaginated (has from/to fields).
// The API meta from @/types/api does not — adapt here (same pattern as OrdersPage).
const paginationMeta = computed<PaginatedMeta | null>(() => {
    const m = apiMeta.value
    if (!m) return null
    return {
        current_page: m.current_page,
        last_page: m.last_page,
        per_page: m.per_page,
        total: m.total,
        from: null,
        to: null,
    }
})

// ── Table ─────────────────────────────────────────────────────────────────────

// AppTable is generic with T extends Record<string, unknown>.
// Cast Expense → Row and recover type in typed cell helpers (same pattern as OrdersPage).
type Row = Record<string, unknown>

const columns: TableColumn<Row>[] = [
    { key: 'expense_date', label: 'Fecha', width: '110px' },
    { key: 'description', label: 'Descripción' },
    { key: 'vendor', label: 'Proveedor', width: '160px' },
    { key: 'category', label: 'Categoría', width: '140px', align: 'center' },
    { key: 'amount_cents', label: 'Monto', width: '110px', align: 'right' },
    { key: 'payment_method', label: 'Pago', width: '110px', align: 'center' },
    { key: 'status', label: 'Estado', width: '130px', align: 'center' },
    { key: 'actions', label: '', width: '80px', align: 'center' },
]

const tableRows = computed<Row[]>(() => expenses.value as unknown as Row[])

function asExpense(row: Row): Expense {
    return row as unknown as Expense
}
</script>

<template>
    <div class="flex flex-col gap-4">

        <!-- Slideoveres -->
        <ExpenseFormSlideover
            v-model="formSlideoverOpen"
            :expense="editingExpense"
            @success="onFormSuccess"
        />
        <ExpenseCategoriesSlideover
            v-model="categoriesSlideoverOpen"
            @changed="onCategoriesChanged"
        />
        <ReceiptUploadSlideover
            v-model="uploadSlideoverOpen"
            @ready-to-verify="onReadyToVerify"
        />
        <ReceiptVerificationSlideover
            v-model="verificationSlideoverOpen"
            :expense="verifyingExpense"
            @verified="onVerified"
        />

        <!-- Page header -->
        <div class="mb-1 flex items-start justify-between gap-4">
            <div>
                <p class="label-gilt">Gestión</p>
                <h1 class="serif text-2xl text-on-surface tracking-tighter">Gastos</h1>
            </div>

            <!-- Header action buttons -->
            <div class="flex items-center gap-2 shrink-0 pt-1">
                <AppButton
                    variant="secondary"
                    size="sm"
                    :icon="BarChart2"
                    to="/admin/expenses/report"
                    data-testid="btn-reporte"
                >
                    Reporte
                </AppButton>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :icon="Settings"
                    aria-label="Gestionar categorías de gastos"
                    data-testid="btn-categorias"
                    @click="categoriesSlideoverOpen = true"
                >
                    Categorías
                </AppButton>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :icon="Upload"
                    data-testid="btn-subir-factura"
                    @click="openUploadSlideover"
                >
                    Subir factura
                </AppButton>
                <AppButton
                    variant="primary"
                    size="sm"
                    :icon="Plus"
                    data-testid="btn-nuevo-gasto"
                    @click="openCreateForm"
                >
                    Nuevo gasto
                </AppButton>
            </div>
        </div>

        <!-- Period total -->
        <div
            class="flex items-center gap-3 px-5 py-4 rounded-xl
                   bg-surface-low dark:bg-surface-mid"
            data-testid="period-total"
        >
            <div class="flex-1">
                <p class="label-gilt">Total del período</p>
                <p class="serif text-2xl text-primary tracking-tighter leading-none mt-0.5">
                    {{ formatCents(periodTotalCents) }}
                </p>
            </div>
            <p
                v-if="selectedMonth"
                class="text-xs text-on-surface-variant text-right"
            >
                {{ selectedMonth }}
            </p>
        </div>

        <!-- Filters bar -->
        <div class="flex flex-wrap items-center gap-3">

            <!-- Month picker -->
            <input
                v-model="selectedMonth"
                type="month"
                class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                       focus:outline-none focus:ring-2 focus:ring-primary/30
                       dark:bg-surface-mid dark:text-on-surface"
                aria-label="Filtrar por mes"
            />

            <!-- Category filter -->
            <select
                v-model="selectedCategoryId"
                class="px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                       focus:outline-none focus:ring-2 focus:ring-primary/30
                       dark:bg-surface-mid dark:text-on-surface"
                aria-label="Filtrar por categoría"
            >
                <option value="">Todas las categorías</option>
                <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                    {{ cat.name }}
                </option>
            </select>

            <!-- Branch filter -->
            <select
                v-if="branches.length > 0"
                v-model="selectedBranchId"
                class="px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                       focus:outline-none focus:ring-2 focus:ring-primary/30
                       dark:bg-surface-mid dark:text-on-surface"
                aria-label="Filtrar por sucursal"
            >
                <option value="">Todas las sucursales</option>
                <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                    {{ branch.name }}
                </option>
            </select>

            <!-- Verified toggle (tabs-style pill group) -->
            <div
                class="inline-flex rounded-xl bg-surface-low dark:bg-surface-mid p-1 gap-0.5"
                role="group"
                aria-label="Filtrar por estado de verificación"
            >
                <button
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        verifiedFilter === 'all'
                            ? 'bg-surface-lowest dark:bg-surface-high text-on-surface shadow-[var(--shadow-ambient)]'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :aria-pressed="verifiedFilter === 'all'"
                    @click="verifiedFilter = 'all'"
                >
                    Todos
                </button>
                <button
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        verifiedFilter === 'verified'
                            ? 'bg-surface-lowest dark:bg-surface-high text-on-surface shadow-[var(--shadow-ambient)]'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :aria-pressed="verifiedFilter === 'verified'"
                    @click="verifiedFilter = 'verified'"
                >
                    Verificados
                </button>
                <button
                    :class="[
                        'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        verifiedFilter === 'draft'
                            ? 'bg-surface-lowest dark:bg-surface-high text-on-surface shadow-[var(--shadow-ambient)]'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :aria-pressed="verifiedFilter === 'draft'"
                    @click="verifiedFilter = 'draft'"
                >
                    Borradores
                </button>
            </div>

            <!-- Search -->
            <div class="relative flex-1 min-w-48">
                <AppInput
                    v-model="searchQuery"
                    placeholder="Buscar proveedor o descripción..."
                    aria-label="Buscar gasto"
                >
                    <template #icon>
                        <Search :size="14" class="text-on-surface-variant" />
                    </template>
                </AppInput>
            </div>
        </div>

        <!-- Table -->
        <AppTable
            :columns="columns"
            :rows="tableRows"
            row-key="id"
            :loading="isLoading"
        >
            <!-- expense_date -->
            <template #cell-expense_date="{ row }">
                <span class="text-sm text-on-surface-variant whitespace-nowrap">
                    {{ formatDate(asExpense(row).expense_date) }}
                </span>
            </template>

            <!-- description -->
            <template #cell-description="{ row }">
                <div class="flex flex-col gap-0.5">
                    <span class="text-sm text-on-surface">
                        {{ asExpense(row).description }}
                    </span>
                    <span
                        v-if="asExpense(row).notes"
                        class="text-xs text-on-surface-variant truncate max-w-xs"
                    >
                        {{ asExpense(row).notes }}
                    </span>
                </div>
            </template>

            <!-- vendor -->
            <template #cell-vendor="{ row }">
                <span class="text-sm text-on-surface-variant">
                    {{ asExpense(row).vendor ?? '—' }}
                </span>
            </template>

            <!-- category -->
            <template #cell-category="{ row }">
                <AppBadge
                    v-if="asExpense(row).category"
                    :variant="EXPENSE_CATEGORY_TYPE_VARIANT[asExpense(row).category!.type]"
                    size="sm"
                >
                    {{ asExpense(row).category!.name }}
                </AppBadge>
                <span v-else class="text-sm text-on-surface-variant">—</span>
            </template>

            <!-- amount_cents -->
            <template #cell-amount_cents="{ row }">
                <span class="text-sm font-bold text-primary">
                    {{ formatCents(asExpense(row).amount_cents) }}
                </span>
            </template>

            <!-- payment_method -->
            <template #cell-payment_method="{ row }">
                <span
                    v-if="asExpense(row).payment_method"
                    class="text-sm text-on-surface-variant"
                >
                    {{ EXPENSE_PAYMENT_METHOD_LABELS[asExpense(row).payment_method!] }}
                </span>
                <span v-else class="text-sm text-on-surface-variant">—</span>
            </template>

            <!-- status: verified + ocr_status -->
            <template #cell-status="{ row }">
                <div class="flex flex-col items-center gap-1">
                    <!-- Verified / draft badge -->
                    <AppBadge
                        :variant="asExpense(row).is_verified ? 'success' : 'neutral'"
                        size="sm"
                        :data-testid="asExpense(row).is_verified ? 'badge-verified' : 'badge-draft'"
                    >
                        {{ asExpense(row).is_verified ? 'Verificado' : 'Borrador' }}
                    </AppBadge>

                    <!-- OCR status badge (only when not 'none') -->
                    <AppBadge
                        v-if="asExpense(row).ocr_status !== 'none'"
                        :variant="EXPENSE_OCR_STATUS_VARIANT[asExpense(row).ocr_status]"
                        size="sm"
                    >
                        {{ EXPENSE_OCR_STATUS_LABELS[asExpense(row).ocr_status] }}
                    </AppBadge>
                </div>
            </template>

            <!-- actions -->
            <template #cell-actions="{ row }">
                <div class="flex items-center justify-center gap-1">
                    <!-- Verificar (only on draft expenses with a receipt) -->
                    <button
                        v-if="!asExpense(row).is_verified"
                        type="button"
                        class="btn-icon text-primary hover:bg-primary/10"
                        :aria-label="`Verificar gasto ${asExpense(row).description}`"
                        data-testid="btn-verificar-draft"
                        @click.stop="openVerifyDraft(asExpense(row))"
                    >
                        <CheckCircle :size="14" />
                    </button>
                    <button
                        type="button"
                        class="btn-icon"
                        :aria-label="`Editar gasto ${asExpense(row).description}`"
                        @click.stop="openEditForm(asExpense(row))"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button
                        type="button"
                        class="btn-icon text-error hover:bg-error/10"
                        :aria-label="`Eliminar gasto ${asExpense(row).description}`"
                        @click.stop="deleteExpense(asExpense(row))"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                    </button>
                </div>
            </template>

            <!-- Empty state -->
            <template #empty>
                <AppEmptyState
                    title="Sin gastos"
                    description="Los gastos aparecerán aquí una vez que los registres o subas una factura."
                >
                    <template #illustration>
                        <Receipt :size="40" class="text-on-surface-variant opacity-40" />
                    </template>
                </AppEmptyState>
            </template>
        </AppTable>

        <!-- Pagination -->
        <AppPagination
            v-if="paginationMeta && paginationMeta.last_page > 1"
            :meta="paginationMeta"
            @page-change="fetchExpenses"
        />

    </div>
</template>
