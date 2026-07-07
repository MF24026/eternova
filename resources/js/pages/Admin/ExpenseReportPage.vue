<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { ChevronLeft, ChevronRight, ArrowLeft, BarChart2, Receipt } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ExpenseService from '@/services/ExpenseService'
import type { ExpenseReport, ExpenseCategoryReport } from '@/services/ExpenseService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useToast } from '@/composables/useToast'
import {
    EXPENSE_CATEGORY_TYPE_LABELS,
    EXPENSE_CATEGORY_TYPE_VARIANT,
} from '@/constants/expenses'
import type { ExpenseCategoryType } from '@/types/domain/Expense'

onMounted(() => {
    document.title = 'Reporte de gastos — Eternova'
    void fetchReport()
})

const { formatCents } = useFormatCurrency()
const toast = useToast()

// ── Month navigation ────────────────────────────────────────────────────────

function currentMonthValue(): string {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

const selectedMonth = ref(currentMonthValue())
const verifiedOnly = ref(false)

/** Display label for the active month: "Junio 2026". */
const monthLabel = computed(() => {
    const [year, month] = selectedMonth.value.split('-')
    const date = new Date(Number(year), Number(month) - 1, 1)
    return date.toLocaleDateString('es-SV', { month: 'long', year: 'numeric' })
})

function navigateMonth(delta: number): void {
    const [year, month] = selectedMonth.value.split('-').map(Number)
    const date = new Date(year, month - 1 + delta, 1)
    const y = date.getFullYear()
    const m = String(date.getMonth() + 1).padStart(2, '0')
    selectedMonth.value = `${y}-${m}`
}

watch([selectedMonth, verifiedOnly], () => void fetchReport())

// ── Data ────────────────────────────────────────────────────────────────────

const report = ref<ExpenseReport | null>(null)
const isLoading = ref(false)
const hasError = ref(false)

async function fetchReport(): Promise<void> {
    isLoading.value = true
    hasError.value = false
    try {
        report.value = await ExpenseService.report({
            month: selectedMonth.value,
            ...(verifiedOnly.value ? { verified_only: true } : {}),
        })
    } catch {
        hasError.value = true
        toast.error('No se pudo cargar el reporte. Intenta de nuevo.')
    } finally {
        isLoading.value = false
    }
}

// ── Derived report data ─────────────────────────────────────────────────────

/** Categories with expenses, sorted by total descending, then the uncategorised bucket. */
const sortedRows = computed<ExpenseCategoryReport[]>(() => {
    if (!report.value) return []
    return [...report.value.by_category].sort((a, b) => b.total_cents - a.total_cents)
})

/** Only rows with a non-zero total (used for the chart). */
const chartRows = computed<ExpenseCategoryReport[]>(() =>
    sortedRows.value.filter((r) => r.total_cents > 0),
)

const grandTotal = computed(() => report.value?.total_cents ?? 0)

const isEmpty = computed(() => !isLoading.value && grandTotal.value === 0)

function pct(cents: number): number {
    if (grandTotal.value === 0) return 0
    return Math.round((cents / grandTotal.value) * 100)
}

// ── Category colour palette (derived from category_type) ────────────────────
//
// We resolve colors through CSS vars so dark mode just works.
// Each type maps to a design-token color name; we provide a hex fallback for
// SVG `fill` (which cannot consume CSS vars directly on some browsers).

interface ColorSet {
    /** CSS var string — safe for non-SVG elements (bg, text). */
    cssVar: string
    /** Hex fallback for elements that cannot use CSS vars (SVG fill). */
    hex: string
}

const TYPE_COLORS: Record<string, ColorSet> = {
    operating: { cssVar: 'var(--info)',    hex: '#4a5a7c' },
    products:  { cssVar: 'var(--primary)', hex: '#7c545d' },
    payroll:   { cssVar: 'var(--warning)', hex: '#7c6b4a' },
    rent:      { cssVar: 'var(--error)',   hex: '#7c4a4a' },
    other:     { cssVar: 'var(--success)', hex: '#4a7c5a' },
}

const FALLBACK_COLOR: ColorSet = { cssVar: 'var(--on-surface-variant)', hex: '#5d4a4e' }

function colorFor(row: ExpenseCategoryReport): ColorSet {
    return TYPE_COLORS[row.category_type ?? ''] ?? FALLBACK_COLOR
}

// ── SVG chart helpers ───────────────────────────────────────────────────────
//
// Horizontal bar chart: one bar per category with expenses.
// Chart viewport: 320 wide × (rows × rowHeight) tall.
// Bar starts at LABEL_WIDTH and runs to LABEL_WIDTH + barWidth.

const CHART_W = 320
const ROW_H   = 36     // height of each bar row
const BAR_H   = 18     // bar height (centred inside ROW_H)
const LABEL_W = 90     // left label area width

/** Bar pixel width for a given row (0–BAR_MAX). */
const BAR_MAX = CHART_W - LABEL_W - 4   // 4px right padding

function barWidth(cents: number): number {
    if (grandTotal.value === 0) return 0
    return Math.max(2, Math.round((cents / grandTotal.value) * BAR_MAX))
}

const chartHeight = computed(() => Math.max(ROW_H, chartRows.value.length * ROW_H))

/** Y centre of bar for row index i. */
function barY(i: number): number {
    return i * ROW_H + ROW_H / 2
}
</script>

<template>
    <div class="flex flex-col gap-6 pb-8">

        <!-- Page header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="label-gilt mb-1">Gestión</p>
                <h1 class="serif text-2xl text-on-surface tracking-tighter">
                    Reporte de gastos
                </h1>
            </div>

            <AppButton
                variant="secondary"
                size="sm"
                :icon="ArrowLeft"
                to="/admin/expenses"
                class="self-start sm:mt-1"
                data-testid="btn-back-to-expenses"
            >
                Gastos
            </AppButton>
        </div>

        <!-- Controls bar: month navigation + verified toggle -->
        <div class="flex flex-wrap items-center gap-3">

            <!-- Month navigator -->
            <div
                class="inline-flex items-center gap-1 rounded-xl bg-surface-low dark:bg-surface-mid p-1"
                role="group"
                aria-label="Navegar mes"
            >
                <button
                    type="button"
                    class="btn-icon"
                    aria-label="Mes anterior"
                    data-testid="btn-prev-month"
                    @click="navigateMonth(-1)"
                >
                    <ChevronLeft :size="16" />
                </button>

                <span
                    class="px-3 py-1 min-w-36 text-center text-sm font-semibold text-on-surface capitalize"
                    data-testid="month-label"
                    aria-live="polite"
                >
                    {{ monthLabel }}
                </span>

                <button
                    type="button"
                    class="btn-icon"
                    aria-label="Mes siguiente"
                    data-testid="btn-next-month"
                    @click="navigateMonth(1)"
                >
                    <ChevronRight :size="16" />
                </button>
            </div>

            <!-- Hidden month input for programmatic tests -->
            <input
                v-model="selectedMonth"
                type="month"
                class="sr-only"
                aria-label="Mes del reporte"
                data-testid="month-input"
            />

            <!-- Verified-only toggle -->
            <label
                class="inline-flex items-center gap-2 cursor-pointer select-none"
                data-testid="verified-only-toggle"
            >
                <span
                    role="switch"
                    :aria-checked="verifiedOnly"
                    class="relative inline-flex h-5 w-9 shrink-0 rounded-full transition-colors duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    :class="verifiedOnly ? 'bg-primary' : 'bg-surface-high dark:bg-surface-mid'"
                    @click="verifiedOnly = !verifiedOnly"
                    @keydown.space.prevent="verifiedOnly = !verifiedOnly"
                    tabindex="0"
                >
                    <span
                        class="pointer-events-none inline-block h-4 w-4 translate-y-0.5 rounded-full bg-on-primary shadow-[var(--shadow-rest)] transition-transform duration-200"
                        :class="verifiedOnly ? 'translate-x-4.5' : 'translate-x-0.5'"
                    />
                </span>
                <span class="text-sm text-on-surface">Solo verificados</span>
            </label>
        </div>

        <!-- Loading state -->
        <div
            v-if="isLoading"
            class="flex items-center justify-center py-20"
            data-testid="report-loading"
        >
            <AppSpinner size="lg" />
        </div>

        <!-- Error state -->
        <div
            v-else-if="hasError"
            class="card flex flex-col items-center gap-3 py-12 text-center"
            style="background: var(--surface-low)"
        >
            <BarChart2 :size="40" class="text-on-surface-variant opacity-40" />
            <p class="serif text-lg text-on-surface">No se pudo cargar el reporte</p>
            <AppButton variant="secondary" size="sm" @click="fetchReport">
                Reintentar
            </AppButton>
        </div>

        <!-- Empty state: month with zero expenses -->
        <div
            v-else-if="isEmpty"
            class="card flex flex-col items-center gap-3 py-12 text-center"
            style="background: var(--surface-low)"
            data-testid="report-empty"
        >
            <Receipt :size="40" class="text-on-surface-variant opacity-40" />
            <p class="serif text-lg text-on-surface">Sin gastos en este período</p>
            <p class="text-sm text-on-surface-variant">
                No se registraron gastos en {{ monthLabel }}.
            </p>
        </div>

        <!-- Report content -->
        <template v-else-if="report">

            <!-- Grand total card -->
            <div
                class="rounded-xl px-6 py-5"
                style="background: var(--surface-low)"
                data-testid="grand-total"
            >
                <p class="label-gilt mb-1">Total del mes</p>
                <p
                    class="serif text-3xl text-primary tracking-tighter leading-none"
                    data-testid="grand-total-amount"
                >
                    {{ formatCents(grandTotal) }}
                </p>
                <p class="text-xs text-on-surface-variant mt-1.5 capitalize">
                    {{ monthLabel }}
                    <span v-if="verifiedOnly"> · solo verificados</span>
                </p>
            </div>

            <!-- Horizontal bar chart -->
            <div
                class="card"
                style="padding: 24px"
                data-testid="expense-chart"
                aria-label="Distribución de gastos por categoría"
            >
                <p class="label-gilt mb-5">Distribución por categoría</p>

                <div class="overflow-x-auto">
                    <svg
                        :viewBox="`0 0 ${CHART_W} ${chartHeight}`"
                        :height="chartHeight"
                        style="width: 100%; min-width: 280px"
                        aria-hidden="true"
                        role="img"
                    >
                        <g
                            v-for="(row, i) in chartRows"
                            :key="row.category_id ?? 'uncategorised'"
                        >
                            <!-- Category name label (left) -->
                            <text
                                :x="LABEL_W - 8"
                                :y="barY(i) + 5"
                                text-anchor="end"
                                dominant-baseline="middle"
                                font-size="11"
                                font-family="var(--font-sans)"
                                fill="var(--on-surface-variant)"
                            >
                                {{ row.category_name.length > 10
                                    ? row.category_name.slice(0, 9) + '…'
                                    : row.category_name }}
                            </text>

                            <!-- Bar background track -->
                            <rect
                                :x="LABEL_W"
                                :y="barY(i) - BAR_H / 2"
                                :width="BAR_MAX"
                                :height="BAR_H"
                                rx="9"
                                fill="var(--surface-mid)"
                            />

                            <!-- Bar fill (animated via CSS transition) -->
                            <rect
                                class="chart-bar-fill"
                                :x="LABEL_W"
                                :y="barY(i) - BAR_H / 2"
                                :width="barWidth(row.total_cents)"
                                :height="BAR_H"
                                rx="9"
                                :fill="colorFor(row).hex"
                                :data-full-width="barWidth(row.total_cents)"
                                :style="`opacity: 0.85; transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1) ${i * 60}ms`"
                            />

                            <!-- Percentage label (right of bar) -->
                            <text
                                :x="LABEL_W + barWidth(row.total_cents) + 6"
                                :y="barY(i) + 5"
                                dominant-baseline="middle"
                                font-size="10"
                                font-family="var(--font-sans)"
                                fill="var(--on-surface-variant)"
                            >
                                {{ pct(row.total_cents) }}%
                            </text>
                        </g>
                    </svg>
                </div>
            </div>

            <!-- Breakdown table/list -->
            <div
                class="rounded-xl overflow-hidden"
                style="background: var(--surface-low)"
                data-testid="breakdown-table"
            >
                <div class="px-6 py-4">
                    <p class="label-gilt">Desglose por categoría</p>
                </div>

                <!-- Mobile cards + desktop rows — same markup, responsive layout -->
                <div class="flex flex-col">
                    <div
                        v-for="row in sortedRows"
                        :key="row.category_id ?? 'uncategorised'"
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 px-6 py-3.5"
                        style="background: var(--surface-lowest)"
                        :class="{ 'opacity-50': row.total_cents === 0 }"
                        :data-testid="`breakdown-row-${row.category_id ?? 'uncategorised'}`"
                    >
                        <!-- Name + type badge -->
                        <div class="flex items-center gap-2 min-w-0 flex-1 basis-40">
                            <span
                                class="w-2 h-2 rounded-full shrink-0"
                                :style="{ background: colorFor(row).cssVar }"
                                aria-hidden="true"
                            />
                            <span class="text-sm font-semibold text-on-surface truncate">
                                {{ row.category_name }}
                            </span>
                        </div>

                        <!-- Type badge -->
                        <AppBadge
                            v-if="row.category_type"
                            :variant="EXPENSE_CATEGORY_TYPE_VARIANT[row.category_type as ExpenseCategoryType]"
                            size="sm"
                            :data-testid="`type-badge-${row.category_id ?? 'uncategorised'}`"
                        >
                            {{ EXPENSE_CATEGORY_TYPE_LABELS[row.category_type as ExpenseCategoryType] }}
                        </AppBadge>
                        <span
                            v-else
                            class="text-xs text-on-surface-variant"
                        >
                            Sin tipo
                        </span>

                        <!-- Count -->
                        <span class="text-xs text-on-surface-variant shrink-0">
                            {{ row.count }} {{ row.count === 1 ? 'gasto' : 'gastos' }}
                        </span>

                        <!-- Percentage -->
                        <span
                            class="text-xs text-on-surface-variant shrink-0 tabular-nums w-10 text-right"
                            :data-testid="`pct-${row.category_id ?? 'uncategorised'}`"
                        >
                            {{ pct(row.total_cents) }}%
                        </span>

                        <!-- Amount -->
                        <span
                            class="text-sm font-bold text-primary shrink-0 ml-auto tabular-nums"
                            :data-testid="`amount-${row.category_id ?? 'uncategorised'}`"
                        >
                            {{ formatCents(row.total_cents) }}
                        </span>
                    </div>

                    <!-- Divider row for total -->
                    <div
                        class="flex items-center justify-between px-6 py-3.5"
                        style="background: var(--surface-mid)"
                    >
                        <span class="text-sm font-semibold text-on-surface">Total</span>
                        <span
                            class="serif text-lg text-primary font-semibold tabular-nums"
                            data-testid="breakdown-total"
                        >
                            {{ formatCents(grandTotal) }}
                        </span>
                    </div>
                </div>
            </div>

        </template>

    </div>
</template>
