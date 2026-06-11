<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Search, ShoppingBag } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppTable, { type TableColumn } from '@/components/base/AppTable.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppPagination from '@/components/base/AppPagination.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import OrderService from '@/services/OrderService'
import { useBranches } from '@/composables/useBranches'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import type { PaginatedMeta } from '@/composables/usePaginated'
import type { Order, OrderStatus, OrderListFilters, StatusCounts } from '@/types/domain/Order'
import type { PaginatedMeta as ApiPaginatedMeta } from '@/types/api'

onMounted(() => {
    document.title = 'Pedidos — Eternova'
    void Promise.all([loadBranches(), fetchOrders()])
})

const router = useRouter()
const { branches, loadBranches } = useBranches()
const { formatCents } = useFormatCurrency()
const { formatDateTime } = useFormatDate()

// ── Status metadata ───────────────────────────────────────────────────────────

const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
    pending: 'Pendiente',
    preparing: 'Preparando',
    ready: 'Listo',
    dispatched: 'Despachado',
    delivered: 'Entregado',
    cancelled: 'Cancelado',
}

type BadgeVariant = 'warning' | 'info' | 'primary' | 'success' | 'error' | 'neutral'

const ORDER_STATUS_VARIANT: Record<OrderStatus, BadgeVariant> = {
    pending: 'warning',
    preparing: 'info',
    ready: 'primary',
    dispatched: 'info',
    delivered: 'success',
    cancelled: 'error',
}

const SOURCE_LABEL: Record<string, string> = {
    pos: 'POS',
    catalog: 'Catálogo',
    reservation: 'Reserva',
}

const ALL_STATUSES: OrderStatus[] = [
    'pending',
    'preparing',
    'ready',
    'dispatched',
    'delivered',
    'cancelled',
]

// ── Filters ───────────────────────────────────────────────────────────────────

const activeTab = ref<OrderStatus | ''>('')
const selectedBranchId = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const searchQuery = ref('')

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { void fetchOrders() }, 300)
})

watch([activeTab, selectedBranchId, dateFrom, dateTo], () => {
    void fetchOrders()
})

// ── Data ──────────────────────────────────────────────────────────────────────

const orders = ref<Order[]>([])
const statusCounts = ref<StatusCounts>({
    pending: 0,
    preparing: 0,
    ready: 0,
    dispatched: 0,
    delivered: 0,
    cancelled: 0,
})
const isLoading = ref(false)
const apiMeta = ref<ApiPaginatedMeta | null>(null)

async function fetchOrders(page = 1): Promise<void> {
    isLoading.value = true
    try {
        const filters: OrderListFilters = {
            per_page: 20,
            page,
            ...(activeTab.value ? { status: activeTab.value } : {}),
            ...(selectedBranchId.value ? { branch_id: selectedBranchId.value } : {}),
            ...(dateFrom.value ? { date_from: dateFrom.value } : {}),
            ...(dateTo.value ? { date_to: dateTo.value } : {}),
            ...(searchQuery.value.trim() ? { search: searchQuery.value.trim() } : {}),
        }
        const result = await OrderService.list(filters)
        orders.value = result.data
        apiMeta.value = result.meta
        statusCounts.value = result.status_counts
    } finally {
        isLoading.value = false
    }
}

// ── Pagination adapter ────────────────────────────────────────────────────────

// AppPagination expects PaginatedMeta from usePaginated (has from/to fields).
// The API meta from @/types/api does not have those. We adapt here — the
// component degrades gracefully when from/to are null (shows total only).
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

// ── Tab total (Todos) ─────────────────────────────────────────────────────────

const totalCount = computed(() =>
    ALL_STATUSES.reduce((sum, s) => sum + (statusCounts.value[s] ?? 0), 0),
)

// ── Table columns ─────────────────────────────────────────────────────────────

// AppTable is generic with T extends Record<string, unknown>.
// We cast domain Order to Row and recover the type in typed helpers.
type Row = Record<string, unknown>

const columns: TableColumn<Row>[] = [
    { key: 'order_number', label: 'Pedido', width: '110px' },
    { key: 'customer', label: 'Cliente' },
    { key: 'branch', label: 'Sucursal', width: '130px' },
    { key: 'created_at', label: 'Fecha', width: '140px' },
    { key: 'source', label: 'Origen', width: '100px', align: 'center' },
    { key: 'total_cents', label: 'Total', width: '100px', align: 'right' },
    { key: 'status', label: 'Estado', width: '130px', align: 'center' },
    { key: 'assignee', label: 'Asignado', width: '130px' },
]

const tableRows = computed<Row[]>(() => orders.value as unknown as Row[])

function asOrder(row: Row): Order {
    return row as unknown as Order
}

// ── Row click ─────────────────────────────────────────────────────────────────

function onRowClick(row: Row): void {
    const order = asOrder(row)
    void router.push({ name: 'admin.orders.detail', params: { id: order.id } })
}
</script>

<template>
    <div class="flex flex-col gap-4">

        <!-- Page header -->
        <div class="mb-1">
            <p class="label-gilt">Gestión</p>
            <h1 class="serif text-2xl text-on-surface tracking-tighter">Pedidos</h1>
        </div>

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
                    v-for="status in ALL_STATUSES"
                    :key="status"
                    role="tab"
                    :aria-selected="activeTab === status"
                    :class="['tab', { active: activeTab === status }]"
                    @click="activeTab = status"
                >
                    {{ ORDER_STATUS_LABELS[status] }}
                    <span class="ml-1 text-xs opacity-70">({{ statusCounts[status] ?? 0 }})</span>
                </button>
            </div>
        </div>

        <!-- Filters toolbar -->
        <div class="flex flex-wrap items-center gap-3">
            <!-- Search -->
            <div class="relative flex-1 min-w-52">
                <AppInput
                    v-model="searchQuery"
                    placeholder="Buscar número de pedido..."
                    aria-label="Buscar pedido"
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

            <!-- Date range -->
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
        </div>

        <!-- Table -->
        <AppTable
            :columns="columns"
            :rows="tableRows"
            row-key="id"
            :loading="isLoading"
            @row-click="onRowClick"
        >
            <!-- order_number -->
            <template #cell-order_number="{ row }">
                <span class="font-mono text-sm font-semibold text-on-surface">
                    {{ asOrder(row).order_number }}
                </span>
            </template>

            <!-- customer -->
            <template #cell-customer="{ row }">
                <span class="text-sm text-on-surface">
                    {{ asOrder(row).customer?.name ?? '—' }}
                </span>
            </template>

            <!-- branch -->
            <template #cell-branch="{ row }">
                <span class="text-sm text-on-surface-variant">
                    {{ asOrder(row).branch?.name ?? '—' }}
                </span>
            </template>

            <!-- created_at -->
            <template #cell-created_at="{ row }">
                <span class="text-sm text-on-surface-variant whitespace-nowrap">
                    {{ formatDateTime(asOrder(row).created_at) }}
                </span>
            </template>

            <!-- source -->
            <template #cell-source="{ row }">
                <AppBadge variant="neutral" size="sm">
                    {{ SOURCE_LABEL[asOrder(row).source] ?? asOrder(row).source }}
                </AppBadge>
            </template>

            <!-- total_cents -->
            <template #cell-total_cents="{ row }">
                <span class="text-sm font-bold text-primary">
                    {{ formatCents(asOrder(row).total_cents) }}
                </span>
            </template>

            <!-- status -->
            <template #cell-status="{ row }">
                <AppBadge :variant="ORDER_STATUS_VARIANT[asOrder(row).status]" size="sm">
                    {{ ORDER_STATUS_LABELS[asOrder(row).status] }}
                </AppBadge>
            </template>

            <!-- assignee -->
            <template #cell-assignee="{ row }">
                <span class="text-sm text-on-surface-variant">
                    {{ asOrder(row).assignee?.name ?? '—' }}
                </span>
            </template>

            <!-- Empty state -->
            <template #empty>
                <AppEmptyState
                    title="Sin pedidos"
                    :description="activeTab
                        ? `No hay pedidos en estado '${ORDER_STATUS_LABELS[activeTab as OrderStatus]}'.`
                        : 'Los pedidos aparecerán aquí una vez que se creen desde el POS, catálogo o reservas.'"
                >
                    <template #illustration>
                        <ShoppingBag :size="40" class="text-on-surface-variant opacity-40" />
                    </template>
                </AppEmptyState>
            </template>
        </AppTable>

        <!-- Pagination -->
        <AppPagination
            v-if="paginationMeta && paginationMeta.last_page > 1"
            :meta="paginationMeta"
            @page-change="fetchOrders"
        />

    </div>
</template>
