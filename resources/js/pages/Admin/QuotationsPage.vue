<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Plus, FileText, Search, Calendar, Pencil } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppTable, { type TableColumn } from '@/components/base/AppTable.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppPagination from '@/components/base/AppPagination.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import QuotationBuilderSlideover from '@/components/Admin/Quotations/QuotationBuilderSlideover.vue'
import QuotationService from '@/services/QuotationService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { useToast } from '@/composables/useToast'
import {
    QUOTATION_STATUS_LABELS,
    QUOTATION_STATUS_VARIANT,
    ALL_QUOTATION_STATUSES,
} from '@/constants/quotations'
import type { PaginatedMeta } from '@/composables/usePaginated'
import type { Quotation, QuotationListFilters, QuotationStatus } from '@/types/domain/Quotation'
import type { PaginatedMeta as ApiPaginatedMeta } from '@/types/api'
import { useRouter } from 'vue-router'

onMounted(() => {
    document.title = 'Cotizaciones — Eternova'
    void fetchQuotations()
})

const router = useRouter()
const { formatCents } = useFormatCurrency()
const { formatDate } = useFormatDate()
const toast = useToast()

// ── Status tabs ───────────────────────────────────────────────────────────────

type StatusTab = 'all' | QuotationStatus

const activeTab = ref<StatusTab>('all')
// Counts map from the API (status → n). Falls back to zero for each status.
const statusCounts = ref<Record<string, number>>({})

function tabCount(tab: QuotationStatus): number {
    return statusCounts.value[tab] ?? 0
}

function totalCount(): number {
    return ALL_QUOTATION_STATUSES.reduce((sum, s) => sum + tabCount(s), 0)
}

function selectTab(tab: StatusTab): void {
    activeTab.value = tab
}

// ── Filters ───────────────────────────────────────────────────────────────────

const searchQuery = ref('')
const dateFrom = ref('')
const dateTo = ref('')

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { void fetchQuotations() }, 300)
})

watch([activeTab, dateFrom, dateTo], () => {
    void fetchQuotations()
})

// ── Data ──────────────────────────────────────────────────────────────────────

const quotations = ref<Quotation[]>([])
const isLoading = ref(false)
const apiMeta = ref<ApiPaginatedMeta | null>(null)

async function fetchQuotations(page = 1): Promise<void> {
    isLoading.value = true
    try {
        const filters: QuotationListFilters = {
            per_page: 20,
            page,
            ...(activeTab.value !== 'all' ? { status: activeTab.value } : {}),
            ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
            ...(dateTo.value ? { date_to: dateTo.value } : {}),
            ...(searchQuery.value.trim() ? { search: searchQuery.value.trim() } : {}),
        }
        const result = await QuotationService.list(filters)
        quotations.value = result.data
        apiMeta.value = result.meta
        // status_counts is returned by every request regardless of the active status
        // filter — always refresh it so the tab badges stay accurate.
        statusCounts.value = result.status_counts
    } catch {
        toast.error('No se pudieron cargar las cotizaciones.')
    } finally {
        isLoading.value = false
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
// Cast Quotation → Row and recover type in typed cell helpers (same pattern as OrdersPage).
type Row = Record<string, unknown>

const columns: TableColumn<Row>[] = [
    { key: 'quotation_number', label: 'Número', width: '140px' },
    { key: 'customer', label: 'Cliente' },
    { key: 'issue_date', label: 'Emisión', width: '110px' },
    { key: 'valid_until', label: 'Válida hasta', width: '120px' },
    { key: 'total_cents', label: 'Total', width: '120px', align: 'right' },
    { key: 'status', label: 'Estado', width: '130px', align: 'center' },
    { key: '_actions', label: '', width: '48px', align: 'center' },
]

const tableRows = computed<Row[]>(() => quotations.value as unknown as Row[])

function asQuotation(row: Row): Quotation {
    return row as unknown as Quotation
}

function navigateToDetail(quotation: Quotation): void {
    void router.push({ name: 'admin.quotations.detail', params: { id: quotation.id } })
}

// ── Builder slideover ─────────────────────────────────────────────────────────

const builderOpen = ref(false)
const builderQuotation = ref<Quotation | null>(null)

function openNewQuotation(): void {
    builderQuotation.value = null
    builderOpen.value = true
}

function openEditDraft(quotation: Quotation): void {
    builderQuotation.value = quotation
    builderOpen.value = true
}

function onBuilderSaved(saved: Quotation): void {
    // Refresh the list so the newly created/updated quotation appears.
    void fetchQuotations()
    // Also update the local row if we edited a draft — avoids a full reload lag.
    const idx = quotations.value.findIndex((q) => q.id === saved.id)
    if (idx !== -1) {
        quotations.value[idx] = saved
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">

        <!-- Builder slideover -->
        <QuotationBuilderSlideover
            v-model="builderOpen"
            :quotation="builderQuotation"
            @saved="onBuilderSaved"
        />

        <!-- Page header -->
        <div class="mb-1 flex items-start justify-between gap-4">
            <div>
                <p class="label-gilt">Gestión</p>
                <h1 class="serif text-2xl text-on-surface tracking-tighter">Cotizaciones</h1>
            </div>

            <div class="flex items-center gap-2 shrink-0 pt-1">
                <AppButton
                    variant="primary"
                    size="sm"
                    :icon="Plus"
                    data-testid="btn-nueva-cotizacion"
                    @click="openNewQuotation"
                >
                    Nueva cotización
                </AppButton>
            </div>
        </div>

        <!-- Status tabs -->
        <div
            class="flex items-center gap-1 flex-wrap"
            role="tablist"
            aria-label="Filtrar por estado de cotización"
            data-testid="status-tabs"
        >
            <!-- Todas -->
            <button
                role="tab"
                :aria-selected="activeTab === 'all'"
                :class="[
                    'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
                    activeTab === 'all'
                        ? 'bg-primary text-on-primary shadow-[var(--shadow-ambient)]'
                        : 'bg-surface-low text-on-surface-variant hover:text-on-surface dark:bg-surface-mid',
                ]"
                data-testid="tab-all"
                @click="selectTab('all')"
            >
                Todas
                <span
                    v-if="apiMeta"
                    :class="[
                        'inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full text-[10px] font-bold',
                        activeTab === 'all' ? 'bg-on-primary/20 text-on-primary' : 'bg-surface-high text-on-surface-variant dark:bg-surface-high',
                    ]"
                >
                    {{ totalCount() }}
                </span>
            </button>

            <!-- Per-status tabs -->
            <button
                v-for="status in ALL_QUOTATION_STATUSES"
                :key="status"
                role="tab"
                :aria-selected="activeTab === status"
                :class="[
                    'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
                    activeTab === status
                        ? 'bg-primary text-on-primary shadow-[var(--shadow-ambient)]'
                        : 'bg-surface-low text-on-surface-variant hover:text-on-surface dark:bg-surface-mid',
                ]"
                :data-testid="`tab-${status}`"
                @click="selectTab(status)"
            >
                {{ QUOTATION_STATUS_LABELS[status] }}
                <span
                    :class="[
                        'inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full text-[10px] font-bold',
                        activeTab === status ? 'bg-on-primary/20 text-on-primary' : 'bg-surface-high text-on-surface-variant dark:bg-surface-high',
                    ]"
                >
                    {{ tabCount(status) }}
                </span>
            </button>
        </div>

        <!-- Filters bar -->
        <div class="flex flex-wrap items-center gap-3" data-testid="filters-bar">

            <!-- Date from -->
            <div class="flex items-center gap-2">
                <label
                    for="filter-date-from"
                    class="text-xs text-on-surface-variant whitespace-nowrap"
                >
                    Desde
                </label>
                <input
                    id="filter-date-from"
                    v-model="dateFrom"
                    type="date"
                    class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                    aria-label="Fecha de emisión desde"
                />
            </div>

            <!-- Date to -->
            <div class="flex items-center gap-2">
                <label
                    for="filter-date-to"
                    class="text-xs text-on-surface-variant whitespace-nowrap"
                >
                    Hasta
                </label>
                <input
                    id="filter-date-to"
                    v-model="dateTo"
                    type="date"
                    class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                    aria-label="Fecha de emisión hasta"
                />
            </div>

            <!-- Search -->
            <div class="relative flex-1 min-w-48">
                <AppInput
                    v-model="searchQuery"
                    placeholder="Buscar número o cliente..."
                    aria-label="Buscar cotización"
                    data-testid="search-input"
                >
                    <template #icon>
                        <Search :size="14" class="text-on-surface-variant" />
                    </template>
                </AppInput>
            </div>
        </div>

        <!-- Total count line -->
        <p
            v-if="apiMeta && !isLoading"
            class="text-xs text-on-surface-variant"
            data-testid="result-count"
        >
            {{ apiMeta.total }} cotización{{ apiMeta.total !== 1 ? 'es' : '' }}
        </p>

        <!-- Desktop: table / Mobile: cards -->

        <!-- Table (md and up) -->
        <div class="hidden md:block">
            <AppTable
                :columns="columns"
                :rows="tableRows"
                row-key="id"
                :loading="isLoading"
                @row-click="(row) => navigateToDetail(asQuotation(row))"
            >
                <!-- quotation_number -->
                <template #cell-quotation_number="{ row }">
                    <span class="text-sm font-mono text-on-surface">
                        {{ asQuotation(row).quotation_number }}
                    </span>
                </template>

                <!-- customer -->
                <template #cell-customer="{ row }">
                    <span class="text-sm text-on-surface">
                        {{ asQuotation(row).customer?.name ?? '—' }}
                    </span>
                </template>

                <!-- issue_date -->
                <template #cell-issue_date="{ row }">
                    <span class="text-sm text-on-surface-variant whitespace-nowrap">
                        {{ formatDate(asQuotation(row).issue_date) }}
                    </span>
                </template>

                <!-- valid_until -->
                <template #cell-valid_until="{ row }">
                    <span
                        v-if="asQuotation(row).valid_until"
                        class="text-sm text-on-surface-variant whitespace-nowrap"
                    >
                        {{ formatDate(asQuotation(row).valid_until!) }}
                    </span>
                    <span v-else class="text-sm text-on-surface-variant">—</span>
                </template>

                <!-- total_cents -->
                <template #cell-total_cents="{ row }">
                    <span class="text-sm font-bold text-primary">
                        {{ formatCents(asQuotation(row).total_cents) }}
                    </span>
                </template>

                <!-- status -->
                <template #cell-status="{ row }">
                    <AppBadge
                        :variant="QUOTATION_STATUS_VARIANT[asQuotation(row).status]"
                        size="sm"
                    >
                        {{ QUOTATION_STATUS_LABELS[asQuotation(row).status] }}
                    </AppBadge>
                </template>

                <!-- actions (edit draft only) -->
                <template #cell-_actions="{ row }">
                    <button
                        v-if="asQuotation(row).status === 'draft'"
                        type="button"
                        class="btn-icon"
                        :aria-label="`Editar cotización ${asQuotation(row).quotation_number}`"
                        :data-testid="`btn-edit-draft-${asQuotation(row).id}`"
                        @click.stop="openEditDraft(asQuotation(row))"
                    >
                        <Pencil :size="14" />
                    </button>
                </template>

                <!-- Empty state -->
                <template #empty>
                    <AppEmptyState
                        title="No hay cotizaciones"
                        description="Las cotizaciones que crees aparecerán aquí."
                    >
                        <template #illustration>
                            <FileText :size="40" class="text-on-surface-variant opacity-40" />
                        </template>
                    </AppEmptyState>
                </template>
            </AppTable>
        </div>

        <!-- Mobile: card list (below md) -->
        <div class="md:hidden flex flex-col gap-2" data-testid="mobile-card-list">

            <!-- Loading skeleton -->
            <template v-if="isLoading">
                <div
                    v-for="i in 5"
                    :key="i"
                    class="rounded-xl p-4 bg-surface-low dark:bg-surface-mid animate-pulse"
                    style="min-height: 76px"
                />
            </template>

            <!-- Empty state -->
            <div
                v-else-if="quotations.length === 0"
                class="rounded-xl py-12 flex flex-col items-center gap-3 bg-surface-low dark:bg-surface-mid"
            >
                <FileText :size="36" class="text-on-surface-variant opacity-40" aria-hidden="true" />
                <p class="text-sm text-on-surface-variant text-center">No hay cotizaciones</p>
            </div>

            <!-- Quotation cards -->
            <template v-else>
                <div
                    v-for="q in quotations"
                    :key="q.id"
                    class="rounded-xl p-4 w-full bg-surface-low transition-colors
                           dark:bg-surface-mid"
                    :data-testid="`quotation-card-${q.id}`"
                >
                    <!-- Clickable row area -->
                    <button
                        type="button"
                        class="w-full text-left flex items-center gap-3
                               hover:bg-surface-mid active:bg-surface-mid transition-colors rounded-lg
                               dark:hover:bg-surface-high"
                        :aria-label="`Ver cotización ${q.quotation_number}`"
                        @click="navigateToDetail(q)"
                    >
                        <!-- Icon -->
                        <div
                            class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                            style="background: var(--primary-container); color: var(--primary)"
                        >
                            <FileText :size="16" aria-hidden="true" />
                        </div>

                        <!-- Main content -->
                        <div class="grow min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-sm font-mono text-on-surface">
                                    {{ q.quotation_number }}
                                </span>
                                <AppBadge
                                    :variant="QUOTATION_STATUS_VARIANT[q.status]"
                                    size="sm"
                                >
                                    {{ QUOTATION_STATUS_LABELS[q.status] }}
                                </AppBadge>
                            </div>
                            <p class="text-xs text-on-surface-variant truncate">
                                {{ q.customer?.name ?? 'Sin cliente' }}
                            </p>
                            <p class="text-xs text-on-surface-variant">
                                <Calendar :size="10" class="inline mr-0.5" aria-hidden="true" />
                                {{ formatDate(q.issue_date) }}
                                <template v-if="q.valid_until">
                                    · Válida hasta {{ formatDate(q.valid_until) }}
                                </template>
                            </p>
                        </div>

                        <!-- Total -->
                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-primary">
                                {{ formatCents(q.total_cents) }}
                            </p>
                        </div>
                    </button>

                    <!-- Edit affordance for drafts (below the row so tap target stays large) -->
                    <div v-if="q.status === 'draft'" class="mt-2 flex justify-end">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium
                                   bg-surface-high text-on-surface-variant hover:text-on-surface transition-colors"
                            :aria-label="`Editar borrador ${q.quotation_number}`"
                            :data-testid="`btn-edit-draft-mobile-${q.id}`"
                            @click="openEditDraft(q)"
                        >
                            <Pencil :size="12" aria-hidden="true" />
                            Editar borrador
                        </button>
                    </div>
                </div>
            </template>
        </div>

        <!-- Pagination -->
        <AppPagination
            v-if="paginationMeta && paginationMeta.last_page > 1"
            :meta="paginationMeta"
            @page-change="fetchQuotations"
        />

    </div>
</template>
