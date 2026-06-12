<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Calendar, List, LayoutDashboard, Search, CalendarX } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppTable, { type TableColumn } from '@/components/base/AppTable.vue'
import AppPagination from '@/components/base/AppPagination.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ReservationService from '@/services/ReservationService'
import { useBranches } from '@/composables/useBranches'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { useToast } from '@/composables/useToast'
import {
    RESERVATION_STATUS_LABELS,
    RESERVATION_STATUS_VARIANT,
    RESERVATION_STATUS_ORDER,
    ALL_RESERVATION_STATUSES,
} from '@/constants/reservations'
import type { PaginatedMeta } from '@/composables/usePaginated'
import type {
    Reservation,
    ReservationStatus,
    ReservationListFilters,
    ReservationStatusCounts,
} from '@/types/domain/Reservation'
import type { PaginatedMeta as ApiPaginatedMeta } from '@/types/api'

// ── View toggle ───────────────────────────────────────────────────────────────

type ViewMode = 'list' | 'calendar' | 'board'

const STORAGE_KEY = 'reservations_view_mode'

function readStoredView(): ViewMode {
    try {
        const stored = localStorage.getItem(STORAGE_KEY)
        if (stored === 'list' || stored === 'calendar' || stored === 'board') return stored
    } catch {
        // localStorage unavailable — fall back to default
    }
    return 'list'
}

const activeView = ref<ViewMode>(readStoredView())

function setView(mode: ViewMode): void {
    activeView.value = mode
    try {
        localStorage.setItem(STORAGE_KEY, mode)
    } catch {
        // ignore
    }
}

// ── Injected composables ──────────────────────────────────────────────────────

onMounted(() => {
    document.title = 'Reservas — Eternova'
    void Promise.all([loadBranches(), fetchReservations()])
})

const router = useRouter()
const { branches, loadBranches } = useBranches()
const { formatCents } = useFormatCurrency()
const { formatDate } = useFormatDate()
const toast = useToast()

// ── Shared filters (applied to all three views) ───────────────────────────────

const selectedBranchId = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const searchQuery = ref('')

// List-only filter: active status tab
const activeTab = ref<ReservationStatus | ''>('')

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { void fetchReservations() }, 300)
})

watch([selectedBranchId, dateFrom, dateTo], () => {
    void fetchReservations()
})

watch(activeTab, () => {
    void fetchReservations()
})

// Fetching the calendar view for the current calendar month also uses the
// shared dateFrom/dateTo fields (the calendar sets them itself for its range).
// We trigger a dedicated calendar fetch when the month changes.
const calendarYear = ref(new Date().getFullYear())
const calendarMonth = ref(new Date().getMonth()) // 0-indexed

watch([calendarYear, calendarMonth], () => {
    setCalendarDateRange()
    void fetchReservations()
})

function setCalendarDateRange(): void {
    const first = new Date(calendarYear.value, calendarMonth.value, 1)
    const last = new Date(calendarYear.value, calendarMonth.value + 1, 0)
    dateFrom.value = toDateString(first)
    dateTo.value = toDateString(last)
}

function toDateString(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function initCalendarRange(): void {
    const now = new Date()
    calendarYear.value = now.getFullYear()
    calendarMonth.value = now.getMonth()
    setCalendarDateRange()
}

// ── Data ──────────────────────────────────────────────────────────────────────

const reservations = ref<Reservation[]>([])
const statusCounts = ref<ReservationStatusCounts>({
    inquiry: 0, confirmed: 0, in_progress: 0, ready: 0, delivered: 0, cancelled: 0,
})
const isLoading = ref(false)
const apiMeta = ref<ApiPaginatedMeta | null>(null)

async function fetchReservations(page = 1): Promise<void> {
    isLoading.value = true
    try {
        const filters: ReservationListFilters = {
            per_page: activeView.value === 'board' ? 100 : 20,
            page,
            ...(activeTab.value ? { status: activeTab.value as ReservationStatus } : {}),
            ...(selectedBranchId.value ? { branch_id: selectedBranchId.value } : {}),
            ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
            ...(dateTo.value ? { date_to: dateTo.value } : {}),
            ...(searchQuery.value.trim() ? { search: searchQuery.value.trim() } : {}),
        }
        const result = await ReservationService.list(filters)
        reservations.value = result.data
        apiMeta.value = result.meta
        statusCounts.value = result.status_counts
    } finally {
        isLoading.value = false
    }
}

// When switching views we may need to re-fetch (different per_page, different
// date range for calendar). Board+List share data; Calendar uses its own range.
watch(activeView, (next) => {
    if (next === 'calendar') {
        initCalendarRange()
    } else {
        // Back to list/board: clear calendar-set date bounds if they were set by calendar
        dateFrom.value = ''
        dateTo.value = ''
        void fetchReservations()
    }
})

// ── Pagination adapter ────────────────────────────────────────────────────────

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

// ── Total count for "Todos" tab ───────────────────────────────────────────────

const totalCount = computed(() =>
    ALL_RESERVATION_STATUSES.reduce((sum, s) => sum + (statusCounts.value[s] ?? 0), 0),
)

// ── LIST view — table ─────────────────────────────────────────────────────────

type Row = Record<string, unknown>

const columns: TableColumn<Row>[] = [
    { key: 'reservation_number', label: 'Reserva', width: '130px' },
    { key: 'customer', label: 'Cliente' },
    { key: 'occasion', label: 'Ocasión', width: '140px' },
    { key: 'event_date', label: 'Fecha evento', width: '130px' },
    { key: 'total_cents', label: 'Total', width: '110px', align: 'right' },
    { key: 'balance_cents', label: 'Saldo', width: '110px', align: 'right' },
    { key: 'status', label: 'Estado', width: '130px', align: 'center' },
    { key: 'assignee', label: 'Asignado', width: '130px' },
]

const tableRows = computed<Row[]>(() => reservations.value as unknown as Row[])

function asReservation(row: Row): Reservation {
    return row as unknown as Reservation
}

function onRowClick(row: Row): void {
    const r = asReservation(row)
    void router.push({ name: 'admin.reservations.detail', params: { id: r.id } })
}

// ── CALENDAR view ─────────────────────────────────────────────────────────────

const MONTH_NAMES = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
]
const WEEKDAY_NAMES = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']

// Day cells for the calendar grid (nulls = padding before the 1st)
const calendarCells = computed<(number | null)[]>(() => {
    const firstDay = new Date(calendarYear.value, calendarMonth.value, 1).getDay() // 0=Sun
    const daysInMonth = new Date(calendarYear.value, calendarMonth.value + 1, 0).getDate()
    const cells: (number | null)[] = []
    for (let i = 0; i < firstDay; i++) cells.push(null)
    for (let d = 1; d <= daysInMonth; d++) cells.push(d)
    return cells
})

// Group reservations by day-of-month (only those with event_date in current month)
const reservationsByDay = computed<Map<number, Reservation[]>>(() => {
    const map = new Map<number, Reservation[]>()
    for (const r of reservations.value) {
        if (!r.event_date) continue
        const d = new Date(r.event_date + 'T00:00:00') // force local parse
        if (d.getFullYear() === calendarYear.value && d.getMonth() === calendarMonth.value) {
            const day = d.getDate()
            const existing = map.get(day) ?? []
            existing.push(r)
            map.set(day, existing)
        }
    }
    return map
})

// Reservations without event_date — shown in the "Sin fecha" tray
const undatedReservations = computed<Reservation[]>(() =>
    reservations.value.filter((r) => !r.event_date),
)

function prevMonth(): void {
    if (calendarMonth.value === 0) {
        calendarYear.value -= 1
        calendarMonth.value = 11
    } else {
        calendarMonth.value -= 1
    }
}

function nextMonth(): void {
    if (calendarMonth.value === 11) {
        calendarYear.value += 1
        calendarMonth.value = 0
    } else {
        calendarMonth.value += 1
    }
}

function isToday(day: number): boolean {
    const now = new Date()
    return (
        day === now.getDate() &&
        calendarMonth.value === now.getMonth() &&
        calendarYear.value === now.getFullYear()
    )
}

function navigateToReservation(id: number): void {
    void router.push({ name: 'admin.reservations.detail', params: { id } })
}

// Status color for calendar chips (uses primary CSS vars directly for compactness)
function chipColor(status: ReservationStatus): string {
    const map: Record<ReservationStatus, string> = {
        inquiry: 'var(--color-on-surface-variant, #6b5a5e)',
        confirmed: 'var(--color-info, #3b82f6)',
        in_progress: 'var(--color-warning, #f59e0b)',
        ready: 'var(--color-primary, #7c545d)',
        delivered: 'var(--color-success, #10b981)',
        cancelled: 'var(--color-error, #ef4444)',
    }
    return map[status]
}

// ── BOARD (kanban) view ───────────────────────────────────────────────────────

// Active workflow columns (cancelled shown separately at the end)
const boardColumns = RESERVATION_STATUS_ORDER

// Group reservations by status for board columns
const reservationsByStatus = computed<Map<ReservationStatus, Reservation[]>>(() => {
    const map = new Map<ReservationStatus, Reservation[]>()
    for (const status of [...RESERVATION_STATUS_ORDER, 'cancelled' as ReservationStatus]) {
        map.set(status, [])
    }
    for (const r of reservations.value) {
        const bucket = map.get(r.status) ?? []
        bucket.push(r)
        map.set(r.status, bucket)
    }
    return map
})

// Drag-and-drop state
const draggingId = ref<number | null>(null)
const draggingFromStatus = ref<ReservationStatus | null>(null)
const dragOverColumn = ref<ReservationStatus | null>(null)

function onDragStart(reservation: Reservation): void {
    draggingId.value = reservation.id
    draggingFromStatus.value = reservation.status
}

function onDragEnd(): void {
    draggingId.value = null
    draggingFromStatus.value = null
    dragOverColumn.value = null
}

function onDragOver(event: DragEvent, targetStatus: ReservationStatus): void {
    // Only highlight if the drop target is a valid transition for the dragged card
    if (!isValidDropTarget(targetStatus)) return
    event.preventDefault()
    dragOverColumn.value = targetStatus
}

function onDragLeave(targetStatus: ReservationStatus): void {
    if (dragOverColumn.value === targetStatus) {
        dragOverColumn.value = null
    }
}

function isValidDropTarget(targetStatus: ReservationStatus): boolean {
    if (draggingId.value === null || draggingFromStatus.value === null) return false
    if (targetStatus === draggingFromStatus.value) return false
    // Find the card's allowed_transitions from the local dataset
    const card = reservations.value.find((r) => r.id === draggingId.value)
    if (!card) return false
    return card.allowed_transitions.includes(targetStatus)
}

async function onDrop(targetStatus: ReservationStatus): Promise<void> {
    // Snapshot before clearing — isValidDropTarget reads draggingId/draggingFromStatus,
    // so we must validate before resetting those refs.
    const id = draggingId.value
    const fromStatus = draggingFromStatus.value

    if (id === null || fromStatus === null) return
    if (targetStatus === fromStatus) return
    if (!isValidDropTarget(targetStatus)) return

    // Safe to clear drag state now that validation passed
    dragOverColumn.value = null
    draggingId.value = null
    draggingFromStatus.value = null

    // Find the card in local state
    const cardIndex = reservations.value.findIndex((r) => r.id === id)
    if (cardIndex === -1) return

    // Optimistically update the card's status in the local array
    const original = { ...reservations.value[cardIndex] } as Reservation
    reservations.value[cardIndex] = { ...original, status: targetStatus }

    try {
        const updated = await ReservationService.transition(id, targetStatus)
        // Replace the card with the fresh server data
        const idx = reservations.value.findIndex((r) => r.id === id)
        if (idx !== -1) {
            // Map ReservationDetail back to Reservation shape (same fields + extras)
            reservations.value[idx] = updated as unknown as Reservation
        }
        // Refresh the status counts
        statusCounts.value[fromStatus] = Math.max(0, (statusCounts.value[fromStatus] ?? 0) - 1)
        statusCounts.value[targetStatus] = (statusCounts.value[targetStatus] ?? 0) + 1
    } catch (err: unknown) {
        // Revert the optimistic update
        const idx = reservations.value.findIndex((r) => r.id === id)
        if (idx !== -1) reservations.value[idx] = original

        // Surface the server's error message if available
        let message = 'No se pudo mover la reserva. Verifique las transiciones permitidas.'
        if (err && typeof err === 'object' && 'response' in err) {
            const axiosErr = err as { response?: { data?: { message?: string } } }
            const serverMsg = axiosErr.response?.data?.message
            if (serverMsg) message = serverMsg
        }
        toast.error(message)
    }
}

// Collapsed state for the cancelled board column
const cancelledCollapsed = ref(true)
</script>

<template>
    <div class="flex flex-col gap-4">

        <!-- Page header -->
        <div class="mb-1">
            <p class="label-gilt">Gestión</p>
            <h1 class="serif text-2xl text-on-surface tracking-tighter">Reservas</h1>
        </div>

        <!-- View toggle + filters row -->
        <div class="flex flex-wrap items-center gap-3">

            <!-- Segmented view toggle -->
            <div
                class="inline-flex rounded-xl bg-surface-low dark:bg-surface-mid p-1 gap-0.5"
                role="group"
                aria-label="Modo de vista"
            >
                <button
                    :class="[
                        'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        activeView === 'list'
                            ? 'bg-surface-lowest dark:bg-surface-high text-on-surface shadow-[var(--shadow-ambient)]'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :aria-pressed="activeView === 'list'"
                    @click="setView('list')"
                >
                    <List :size="14" aria-hidden="true" />
                    Lista
                </button>
                <button
                    :class="[
                        'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        activeView === 'calendar'
                            ? 'bg-surface-lowest dark:bg-surface-high text-on-surface shadow-[var(--shadow-ambient)]'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :aria-pressed="activeView === 'calendar'"
                    @click="setView('calendar')"
                >
                    <Calendar :size="14" aria-hidden="true" />
                    Calendario
                </button>
                <button
                    :class="[
                        'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
                        activeView === 'board'
                            ? 'bg-surface-lowest dark:bg-surface-high text-on-surface shadow-[var(--shadow-ambient)]'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :aria-pressed="activeView === 'board'"
                    @click="setView('board')"
                >
                    <LayoutDashboard :size="14" aria-hidden="true" />
                    Tablero
                </button>
            </div>

            <!-- Search -->
            <div class="relative flex-1 min-w-48">
                <AppInput
                    v-model="searchQuery"
                    placeholder="Buscar número de reserva..."
                    aria-label="Buscar reserva"
                >
                    <template #icon>
                        <Search :size="14" class="text-on-surface-variant" />
                    </template>
                </AppInput>
            </div>

            <!-- Branch filter -->
            <select
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

            <!-- Date range (hidden in calendar view since calendar sets its own range) -->
            <template v-if="activeView !== 'calendar'">
                <input
                    v-model="dateFrom"
                    type="date"
                    class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                    aria-label="Desde"
                />
                <input
                    v-model="dateTo"
                    type="date"
                    class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                    aria-label="Hasta"
                />
            </template>
        </div>

        <!-- ═══════════════════ LIST VIEW ════════════════════════════════════ -->
        <template v-if="activeView === 'list'">

            <!-- Status tabs -->
            <div class="overflow-x-auto -mx-1 px-1">
                <div class="tabs inline-flex min-w-max" role="tablist" aria-label="Filtrar por estado">
                    <button
                        role="tab"
                        :aria-selected="activeTab === ''"
                        :class="['tab', { active: activeTab === '' }]"
                        @click="activeTab = ''"
                    >
                        Todos
                        <span class="ml-1 text-xs opacity-70">({{ totalCount }})</span>
                    </button>
                    <button
                        v-for="status in ALL_RESERVATION_STATUSES"
                        :key="status"
                        role="tab"
                        :aria-selected="activeTab === status"
                        :class="['tab', { active: activeTab === status }]"
                        @click="activeTab = status"
                    >
                        {{ RESERVATION_STATUS_LABELS[status] }}
                        <span class="ml-1 text-xs opacity-70">({{ statusCounts[status] ?? 0 }})</span>
                    </button>
                </div>
            </div>

            <!-- Table -->
            <AppTable
                :columns="columns"
                :rows="tableRows"
                row-key="id"
                :loading="isLoading"
                @row-click="onRowClick"
            >
                <template #cell-reservation_number="{ row }">
                    <span class="font-mono text-sm font-semibold text-on-surface">
                        {{ asReservation(row).reservation_number }}
                    </span>
                </template>

                <template #cell-customer="{ row }">
                    <span class="text-sm text-on-surface">
                        {{ asReservation(row).customer?.name ?? '—' }}
                    </span>
                </template>

                <template #cell-occasion="{ row }">
                    <span class="text-sm text-on-surface-variant">
                        {{ asReservation(row).occasion ?? '—' }}
                    </span>
                </template>

                <template #cell-event_date="{ row }">
                    <span class="text-sm text-on-surface-variant whitespace-nowrap">
                        {{ asReservation(row).event_date ? formatDate(asReservation(row).event_date!) : '—' }}
                    </span>
                </template>

                <template #cell-total_cents="{ row }">
                    <span class="text-sm font-bold text-primary">
                        {{ formatCents(asReservation(row).total_cents) }}
                    </span>
                </template>

                <template #cell-balance_cents="{ row }">
                    <span
                        :class="[
                            'text-sm font-semibold',
                            asReservation(row).balance_cents > 0 ? 'text-warning' : 'text-success',
                        ]"
                    >
                        {{ formatCents(asReservation(row).balance_cents) }}
                    </span>
                </template>

                <template #cell-status="{ row }">
                    <AppBadge :variant="RESERVATION_STATUS_VARIANT[asReservation(row).status]" size="sm">
                        {{ RESERVATION_STATUS_LABELS[asReservation(row).status] }}
                    </AppBadge>
                </template>

                <template #cell-assignee="{ row }">
                    <span class="text-sm text-on-surface-variant">
                        {{ asReservation(row).assignee?.name ?? '—' }}
                    </span>
                </template>

                <template #empty>
                    <AppEmptyState
                        title="Sin reservas"
                        :description="activeTab
                            ? `No hay reservas en estado '${RESERVATION_STATUS_LABELS[activeTab as ReservationStatus]}'.`
                            : 'Las reservas aparecerán aquí una vez que se capturen desde el panel.'"
                    >
                        <template #illustration>
                            <CalendarX :size="40" class="text-on-surface-variant opacity-40" />
                        </template>
                    </AppEmptyState>
                </template>
            </AppTable>

            <!-- Pagination -->
            <AppPagination
                v-if="paginationMeta && paginationMeta.last_page > 1"
                :meta="paginationMeta"
                @page-change="fetchReservations"
            />
        </template>

        <!-- ═══════════════════ CALENDAR VIEW ═══════════════════════════════ -->
        <template v-else-if="activeView === 'calendar'">

            <!-- Month navigation header -->
            <div class="flex items-center justify-between">
                <p class="serif text-xl text-on-surface tracking-tighter">
                    {{ MONTH_NAMES[calendarMonth] }} {{ calendarYear }}
                </p>
                <div class="flex items-center gap-1">
                    <button class="btn-icon" aria-label="Mes anterior" @click="prevMonth">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button class="btn-icon" aria-label="Mes siguiente" @click="nextMonth">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            </div>

            <!-- Loading overlay -->
            <div v-if="isLoading" class="flex justify-center py-8">
                <AppSpinner size="md" />
            </div>

            <template v-else>
                <!-- Weekday header row -->
                <div class="grid grid-cols-7 mb-1">
                    <div
                        v-for="day in WEEKDAY_NAMES"
                        :key="day"
                        class="py-1.5 text-center text-[10px] font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                    >
                        {{ day }}
                    </div>
                </div>

                <!-- Day cells grid -->
                <!-- No-Line: cells separated by bg tiers, no borders -->
                <div class="grid grid-cols-7 gap-px bg-surface-low dark:bg-surface-mid rounded-xl overflow-hidden">
                    <div
                        v-for="(day, idx) in calendarCells"
                        :key="idx"
                        :class="[
                            'min-h-[80px] p-1.5 flex flex-col gap-1',
                            day === null
                                ? 'bg-surface-low dark:bg-surface-mid'
                                : 'bg-surface-lowest dark:bg-surface-low',
                            isToday(day!) && day !== null ? 'ring-2 ring-inset ring-primary/30' : '',
                        ]"
                    >
                        <!-- Day number -->
                        <span
                            v-if="day !== null"
                            :class="[
                                'text-xs font-semibold self-start w-5 h-5 flex items-center justify-center rounded-full',
                                isToday(day) ? 'bg-primary text-on-primary' : 'text-on-surface-variant',
                            ]"
                        >
                            {{ day }}
                        </span>

                        <!-- Reservation chips -->
                        <template v-if="day !== null && reservationsByDay.get(day)">
                            <button
                                v-for="r in (reservationsByDay.get(day) ?? []).slice(0, 3)"
                                :key="r.id"
                                class="w-full text-left rounded px-1 py-0.5 text-[9px] leading-tight truncate font-medium transition-opacity hover:opacity-80"
                                :style="{ background: chipColor(r.status) + '22', color: chipColor(r.status) }"
                                :title="`${r.reservation_number} · ${r.customer?.name ?? 'Sin cliente'} · ${r.occasion ?? 'Sin ocasión'}`"
                                @click="navigateToReservation(r.id)"
                            >
                                {{ r.customer?.name ?? r.occasion ?? r.reservation_number }}
                            </button>

                            <!-- +N more indicator -->
                            <span
                                v-if="(reservationsByDay.get(day) ?? []).length > 3"
                                class="text-[9px] text-on-surface-variant px-1"
                            >
                                +{{ (reservationsByDay.get(day) ?? []).length - 3 }} más
                            </span>
                        </template>
                    </div>
                </div>

                <!-- "Sin fecha" tray -->
                <div v-if="undatedReservations.length > 0" class="mt-4">
                    <p class="label-gilt mb-2">Sin fecha de evento</p>
                    <div class="flex flex-col gap-2">
                        <button
                            v-for="r in undatedReservations"
                            :key="r.id"
                            class="flex items-center gap-3 rounded-xl px-4 py-3 text-left transition-colors
                                   bg-surface-low dark:bg-surface-mid hover:bg-surface-mid dark:hover:bg-surface-high"
                            @click="navigateToReservation(r.id)"
                        >
                            <div
                                class="w-2 h-2 rounded-full shrink-0"
                                :style="{ background: chipColor(r.status) }"
                            />
                            <span class="text-sm font-mono text-on-surface-variant">{{ r.reservation_number }}</span>
                            <span class="text-sm text-on-surface flex-1 truncate">{{ r.customer?.name ?? r.occasion ?? '—' }}</span>
                            <AppBadge :variant="RESERVATION_STATUS_VARIANT[r.status]" size="sm">
                                {{ RESERVATION_STATUS_LABELS[r.status] }}
                            </AppBadge>
                        </button>
                    </div>
                </div>

                <!-- Empty month state -->
                <div v-if="reservations.length === 0 && undatedReservations.length === 0">
                    <AppEmptyState
                        title="Sin reservas este mes"
                        description="No hay reservas con fecha de evento en este mes."
                    >
                        <template #illustration>
                            <Calendar :size="40" class="text-on-surface-variant opacity-40" />
                        </template>
                    </AppEmptyState>
                </div>
            </template>
        </template>

        <!-- ═══════════════════ BOARD VIEW ═══════════════════════════════════ -->
        <template v-else-if="activeView === 'board'">

            <!-- Loading overlay -->
            <div v-if="isLoading" class="flex justify-center py-8">
                <AppSpinner size="md" />
            </div>

            <template v-else>
                <!-- Kanban columns (horizontally scrollable on mobile) -->
                <div
                    class="flex gap-3 overflow-x-auto pb-4 -mx-1 px-1"
                    data-testid="board-columns"
                >
                    <!-- Active workflow columns -->
                    <div
                        v-for="status in boardColumns"
                        :key="status"
                        class="flex-shrink-0 w-64 flex flex-col gap-2"
                        :data-testid="`board-column-${status}`"
                    >
                        <!-- Column header -->
                        <div class="flex items-center justify-between px-1 mb-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="w-2.5 h-2.5 rounded-full"
                                    :style="{ background: chipColor(status) }"
                                />
                                <span class="text-xs font-semibold text-on-surface uppercase tracking-[0.05em]">
                                    {{ RESERVATION_STATUS_LABELS[status] }}
                                </span>
                            </div>
                            <span class="text-xs text-on-surface-variant font-medium">
                                {{ (reservationsByStatus.get(status) ?? []).length }}
                            </span>
                        </div>

                        <!-- Drop zone -->
                        <div
                            class="flex flex-col gap-2 min-h-[120px] rounded-xl p-2 transition-colors"
                            :class="[
                                dragOverColumn === status && draggingFromStatus !== status
                                    ? 'bg-primary/10 ring-2 ring-primary/30'
                                    : 'bg-surface-low dark:bg-surface-mid',
                            ]"
                            :data-testid="`board-dropzone-${status}`"
                            @dragover.prevent="(e: DragEvent) => onDragOver(e, status)"
                            @dragleave="onDragLeave(status)"
                            @drop.prevent="onDrop(status)"
                        >
                            <!-- Reservation cards -->
                            <div
                                v-for="r in reservationsByStatus.get(status) ?? []"
                                :key="r.id"
                                draggable="true"
                                :data-reservation-id="r.id"
                                :data-status="r.status"
                                :class="[
                                    'rounded-lg p-3 flex flex-col gap-2 cursor-grab active:cursor-grabbing transition-all',
                                    'bg-surface-lowest dark:bg-surface-low',
                                    'shadow-[var(--shadow-ambient)] hover:shadow-[var(--shadow-rest)]',
                                    draggingId === r.id ? 'opacity-40 scale-95' : '',
                                ]"
                                :aria-label="`Reserva ${r.reservation_number}, arrastrar para cambiar estado`"
                                @dragstart="onDragStart(r)"
                                @dragend="onDragEnd"
                            >
                                <!-- Number + status -->
                                <div class="flex items-start justify-between gap-2">
                                    <span class="font-mono text-xs font-semibold text-on-surface">
                                        {{ r.reservation_number }}
                                    </span>
                                </div>

                                <!-- Customer + occasion -->
                                <div>
                                    <p class="text-sm font-medium text-on-surface truncate">
                                        {{ r.customer?.name ?? '—' }}
                                    </p>
                                    <p v-if="r.occasion" class="text-xs text-on-surface-variant truncate">
                                        {{ r.occasion }}
                                    </p>
                                </div>

                                <!-- Event date -->
                                <p v-if="r.event_date" class="text-xs text-on-surface-variant">
                                    {{ formatDate(r.event_date) }}
                                </p>

                                <!-- Amounts row -->
                                <div class="flex items-center justify-between gap-1 pt-1">
                                    <span class="text-xs font-bold text-primary">
                                        {{ formatCents(r.total_cents) }}
                                    </span>
                                    <span
                                        v-if="r.balance_cents > 0"
                                        class="text-[10px] text-warning font-medium"
                                    >
                                        Saldo: {{ formatCents(r.balance_cents) }}
                                    </span>
                                </div>

                                <!-- Assignee -->
                                <p v-if="r.assignee" class="text-[10px] text-on-surface-variant">
                                    {{ r.assignee.name }}
                                </p>

                                <!-- Detail link -->
                                <button
                                    class="mt-1 text-[10px] text-primary hover:underline text-left"
                                    @click.stop="navigateToReservation(r.id)"
                                >
                                    Ver detalle
                                </button>
                            </div>

                            <!-- Empty column placeholder -->
                            <div
                                v-if="(reservationsByStatus.get(status) ?? []).length === 0"
                                class="flex-1 flex items-center justify-center py-6 text-xs text-on-surface-variant opacity-50"
                            >
                                Sin reservas
                            </div>
                        </div>
                    </div>

                    <!-- Cancelled column (collapsible) -->
                    <div class="flex-shrink-0 w-64 flex flex-col gap-2">
                        <!-- Column header -->
                        <button
                            class="flex items-center justify-between px-1 mb-1 w-full group"
                            :aria-expanded="!cancelledCollapsed"
                            aria-controls="cancelled-column"
                            @click="cancelledCollapsed = !cancelledCollapsed"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="w-2.5 h-2.5 rounded-full"
                                    :style="{ background: chipColor('cancelled') }"
                                />
                                <span class="text-xs font-semibold text-on-surface-variant uppercase tracking-[0.05em] group-hover:text-on-surface transition-colors">
                                    {{ RESERVATION_STATUS_LABELS['cancelled'] }}
                                </span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs text-on-surface-variant font-medium">
                                    {{ (reservationsByStatus.get('cancelled') ?? []).length }}
                                </span>
                                <svg
                                    :class="['w-3 h-3 text-on-surface-variant transition-transform', cancelledCollapsed ? '' : 'rotate-180']"
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="m6 9 6 6 6-6"/>
                                </svg>
                            </div>
                        </button>

                        <div
                            id="cancelled-column"
                            :class="[
                                'flex flex-col gap-2 rounded-xl p-2 transition-colors',
                                'bg-surface-low dark:bg-surface-mid',
                                cancelledCollapsed ? 'opacity-50' : '',
                            ]"
                        >
                            <template v-if="!cancelledCollapsed">
                                <div
                                    v-for="r in reservationsByStatus.get('cancelled') ?? []"
                                    :key="r.id"
                                    class="rounded-lg p-3 flex flex-col gap-1
                                           bg-surface-lowest dark:bg-surface-low opacity-70"
                                >
                                    <span class="font-mono text-xs font-semibold text-on-surface">
                                        {{ r.reservation_number }}
                                    </span>
                                    <p class="text-sm text-on-surface truncate">
                                        {{ r.customer?.name ?? '—' }}
                                    </p>
                                    <button
                                        class="text-[10px] text-primary hover:underline text-left"
                                        @click="navigateToReservation(r.id)"
                                    >
                                        Ver detalle
                                    </button>
                                </div>

                                <div
                                    v-if="(reservationsByStatus.get('cancelled') ?? []).length === 0"
                                    class="py-6 text-center text-xs text-on-surface-variant opacity-50"
                                >
                                    Sin reservas
                                </div>
                            </template>

                            <template v-else>
                                <div class="py-4 text-center text-xs text-on-surface-variant">
                                    {{ (reservationsByStatus.get('cancelled') ?? []).length }} canceladas
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </template>

    </div>
</template>
