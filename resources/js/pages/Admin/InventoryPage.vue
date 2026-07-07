<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Search, Plus, ArrowLeftRight, ChevronRight, Package } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppTable, { type TableColumn } from '@/components/base/AppTable.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppPagination from '@/components/base/AppPagination.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AdjustStockSlideover from '@/components/composite/AdjustStockSlideover.vue'
import TransferStockSlideover from '@/components/composite/TransferStockSlideover.vue'
import { useInventoryStore } from '@/stores/inventory'
import { useBranches } from '@/composables/useBranches'
import { useStockBadge } from '@/composables/useStockBadge'
import type { PaginatedMeta } from '@/composables/usePaginated'
import type { BranchInventory } from '@/types/domain/Inventory'

onMounted(() => {
    document.title = 'Inventario — Eternova'
    void Promise.all([loadBranches(), loadInventory()])
})

const store = useInventoryStore()
const { branches, loadBranches } = useBranches()
const { forAvailable } = useStockBadge()

// ── Filters ───────────────────────────────────────────────────────────────────

const searchQuery = ref('')
const selectedBranchId = ref('')
const filterLowStock = ref(false)
const filterNoStock = ref(false)

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { void loadInventory() }, 300)
})

watch([selectedBranchId, filterLowStock, filterNoStock], () => { void loadInventory() })

async function loadInventory(page = 1): Promise<void> {
    await store.fetchList({
        search: searchQuery.value.trim() || undefined,
        branch_id: selectedBranchId.value || undefined,
        low_stock: filterNoStock.value ? undefined : (filterLowStock.value ? true : undefined),
        per_page: 20,
        page,
    })
}

// ── Table columns ─────────────────────────────────────────────────────────────

// AppTable uses T extends Record<string, unknown>. We cast columns + rows to that
// generic shape and recover the concrete type via typed slot params below.
type Row = Record<string, unknown>

const columns: TableColumn<Row>[] = [
    { key: 'product_info', label: 'Producto' },
    { key: 'branch', label: 'Sucursal', width: '140px' },
    { key: 'available', label: 'Disponible', align: 'center', width: '110px', sortable: true },
    { key: 'reserved', label: 'Reservado', align: 'center', width: '100px' },
    { key: 'stock_status', label: 'Estado', align: 'center', width: '120px' },
    { key: 'actions', label: '', width: '80px' },
]

// Typed table rows — cast to Row for the component, recovered in typed helpers.
const tableRows = computed<Row[]>(() => {
    const base: BranchInventory[] = filterNoStock.value
        ? store.items.filter((item) => item.available <= 0)
        : store.items
    return base as unknown as Row[]
})

// These helpers accept the slot row (which is Record<string, unknown> from AppTable)
// and recover the domain type for type-safe access.
function asInventory(row: Row): BranchInventory {
    return row as unknown as BranchInventory
}

function variantLabel(row: Row): string {
    const item = asInventory(row)
    const opts = item.product_variant?.options ?? {}
    const parts = Object.values(opts)
    return parts.length > 0 ? parts.join(' · ') : ''
}

// ── Slideovers ────────────────────────────────────────────────────────────────

const adjustOpen = ref(false)
const transferOpen = ref(false)
const activeInventory = ref<BranchInventory | undefined>(undefined)

function openAdjust(row?: Row): void {
    activeInventory.value = row !== undefined ? asInventory(row) : undefined
    adjustOpen.value = true
}

function openTransfer(row?: Row): void {
    activeInventory.value = row !== undefined ? asInventory(row) : undefined
    transferOpen.value = true
}

function onSlideverSaved(): void {
    void loadInventory(store.pagination.meta?.current_page ?? 1)
}

// ── Pagination ────────────────────────────────────────────────────────────────

// AppPagination expects PaginatedMeta from usePaginated (which adds from/to).
// The store's meta comes from api.ts PaginatedMeta which lacks from/to.
// We adapt it here — the display degrades gracefully (shows total only).
const paginationMeta = computed<PaginatedMeta | null>(() => {
    const m = store.pagination.meta
    if (m === null) return null
    return {
        current_page: m.current_page,
        last_page: m.last_page,
        per_page: m.per_page,
        total: m.total,
        from: null,
        to: null,
    }
})
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-3">
            <!-- Search -->
            <div class="relative flex-1 min-w-52">
                <AppInput
                    v-model="searchQuery"
                    placeholder="Buscar producto o SKU..."
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
                       focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
                <option value="">Todas las sucursales</option>
                <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                    {{ branch.name }}
                </option>
            </select>

            <!-- Filter chips -->
            <div class="flex gap-1.5">
                <button
                    :class="[
                        'px-3.5 py-2 rounded-full text-xs font-medium transition-colors',
                        filterLowStock
                            ? 'bg-warning-container text-warning'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :style="!filterLowStock ? 'background: var(--surface-low)' : ''"
                    @click="filterLowStock = !filterLowStock; filterNoStock = false"
                >
                    Stock bajo
                </button>
                <button
                    :class="[
                        'px-3.5 py-2 rounded-full text-xs font-medium transition-colors',
                        filterNoStock
                            ? 'bg-error-container text-error'
                            : 'text-on-surface-variant hover:text-on-surface',
                    ]"
                    :style="!filterNoStock ? 'background: var(--surface-low)' : ''"
                    @click="filterNoStock = !filterNoStock; filterLowStock = false"
                >
                    Sin stock
                </button>
            </div>

            <div class="flex gap-2 ml-auto">
                <AppButton
                    :icon="ArrowLeftRight"
                    variant="secondary"
                    size="sm"
                    @click="openTransfer()"
                >
                    Transferencia
                </AppButton>
                <AppButton
                    :icon="Plus"
                    size="sm"
                    @click="openAdjust()"
                >
                    Registrar movimiento
                </AppButton>
            </div>
        </div>

        <!-- KPI strip -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="card p-4">
                <p class="label-gilt mb-1">Total registros</p>
                <p class="serif text-2xl text-on-surface">{{ store.pagination.meta?.total ?? '—' }}</p>
            </div>
            <div class="card p-4">
                <p class="label-gilt mb-1">Sin stock</p>
                <p class="serif text-2xl text-error">
                    {{ store.items.filter((i) => i.available <= 0).length }}
                </p>
            </div>
            <div class="card p-4">
                <p class="label-gilt mb-1">Stock bajo</p>
                <p class="serif text-2xl text-warning">
                    {{ store.items.filter((i) => i.available > 0 && i.available <= 10).length }}
                </p>
            </div>
            <div class="card p-4 flex items-center justify-between">
                <div>
                    <p class="label-gilt mb-1">Movimientos</p>
                    <p class="serif text-sm text-primary">Ver historial</p>
                </div>
                <router-link
                    to="/admin/inventory/movements"
                    class="btn-icon"
                    aria-label="Ver historial de movimientos"
                >
                    <ChevronRight :size="16" />
                </router-link>
            </div>
        </div>

        <!-- Table -->
        <div v-if="store.isLoading" class="flex justify-center py-12">
            <AppSpinner />
        </div>

        <template v-else>
            <AppTable
                :columns="columns"
                :rows="tableRows"
                row-key="id"
                @row-click="(row) => openAdjust(row)"
            >
                <!-- Product info cell -->
                <template #cell-product_info="{ row }">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-9 h-9 rounded-lg shrink-0"
                            style="background: var(--gradient-soft)"
                        />
                        <div class="min-w-0">
                            <p class="text-sm text-on-surface truncate">
                                {{ asInventory(row).product_variant?.product?.name ?? 'Producto desconocido' }}
                            </p>
                            <p class="text-xs text-on-surface-variant font-mono">
                                {{ asInventory(row).product_variant?.sku ?? '' }}
                                <span v-if="variantLabel(row)" class="ml-1 font-sans">
                                    · {{ variantLabel(row) }}
                                </span>
                            </p>
                        </div>
                    </div>
                </template>

                <!-- Branch cell -->
                <template #cell-branch="{ row }">
                    <span class="text-sm text-on-surface-variant">
                        {{ asInventory(row).branch?.name ?? asInventory(row).branch_id }}
                    </span>
                </template>

                <!-- Available cell -->
                <template #cell-available="{ row }">
                    <span
                        class="font-bold text-base"
                        :class="asInventory(row).available <= 0 ? 'text-error' : 'text-on-surface'"
                    >
                        {{ asInventory(row).available }}
                    </span>
                </template>

                <!-- Reserved cell -->
                <template #cell-reserved="{ row }">
                    <span class="text-sm text-on-surface-variant">{{ asInventory(row).reserved }}</span>
                </template>

                <!-- Stock status badge -->
                <template #cell-stock_status="{ row }">
                    <AppBadge :variant="forAvailable(asInventory(row).available).variant" size="sm">
                        {{ forAvailable(asInventory(row).available).label }}
                    </AppBadge>
                </template>

                <!-- Actions cell -->
                <template #cell-actions="{ row }">
                    <div class="flex items-center gap-1.5" @click.stop>
                        <button
                            class="btn-icon w-8 h-8"
                            aria-label="Ajustar stock"
                            @click="openAdjust(row)"
                        >
                            <Plus :size="14" />
                        </button>
                        <button
                            class="btn-icon w-8 h-8"
                            aria-label="Transferir stock"
                            @click="openTransfer(row)"
                        >
                            <ArrowLeftRight :size="14" />
                        </button>
                    </div>
                </template>

                <!-- Empty state -->
                <template #empty>
                    <AppEmptyState
                        title="Sin registros de inventario"
                        description="El inventario aparece aquí una vez que se registren movimientos o se importen productos."
                    >
                        <template #illustration>
                            <Package :size="40" class="text-on-surface-variant opacity-40" />
                        </template>
                        <template #cta>
                            <AppButton :icon="Plus" size="sm" @click="openAdjust()">
                                Registrar movimiento
                            </AppButton>
                        </template>
                    </AppEmptyState>
                </template>
            </AppTable>

            <!-- Pagination -->
            <AppPagination
                v-if="paginationMeta && paginationMeta.last_page > 1"
                :meta="paginationMeta"
                @page-change="loadInventory"
            />
        </template>
    </div>

    <!-- Slideovers -->
    <AdjustStockSlideover
        v-model="adjustOpen"
        :initial-inventory="activeInventory"
        @saved="onSlideverSaved"
    />
    <TransferStockSlideover
        v-model="transferOpen"
        :initial-inventory="activeInventory"
        @saved="onSlideverSaved"
    />
</template>
