<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { ArrowLeft, ArrowUpCircle, ArrowDownCircle, SlidersHorizontal, ArrowLeftRight, Search } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppTable, { type TableColumn } from '@/components/base/AppTable.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppPagination from '@/components/base/AppPagination.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import { useInventoryStore } from '@/stores/inventory'
import { useBranches } from '@/composables/useBranches'
import type { PaginatedMeta } from '@/composables/usePaginated'
import type { InventoryMovement, MovementType } from '@/types/domain/Inventory'

onMounted(() => {
    document.title = 'Movimientos de Inventario — Eternova'
    void Promise.all([loadBranches(), loadMovements()])
})

const store = useInventoryStore()
const { branches, loadBranches } = useBranches()

// ── Filters ───────────────────────────────────────────────────────────────────

const searchQuery = ref('')
const selectedBranchId = ref('')
const selectedType = ref<MovementType | ''>('')
const fromDate = ref('')
const toDate = ref('')

let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => { void loadMovements() }, 300)
})

watch([selectedBranchId, selectedType, fromDate, toDate], () => { void loadMovements() })

async function loadMovements(page = 1): Promise<void> {
    await store.fetchMovements({
        branch_id: selectedBranchId.value || undefined,
        type: selectedType.value || undefined,
        from_date: fromDate.value || undefined,
        to_date: toDate.value || undefined,
        per_page: 25,
        page,
    })
}

// ── Table ─────────────────────────────────────────────────────────────────────

// AppTable uses T extends Record<string, unknown> — cast domain types to Row.
type Row = Record<string, unknown>

const columns: TableColumn<Row>[] = [
    { key: 'created_at', label: 'Fecha', width: '140px' },
    { key: 'type_badge', label: 'Tipo', width: '110px' },
    { key: 'product_info', label: 'Producto / Variante' },
    { key: 'branch', label: 'Sucursal', width: '140px' },
    { key: 'quantity', label: 'Cantidad', align: 'center', width: '90px' },
    { key: 'user', label: 'Usuario', width: '140px' },
    { key: 'notes', label: 'Notas' },
]

const tableRows = computed<Row[]>(() => store.movements as unknown as Row[])

function asMovement(row: Row): InventoryMovement {
    return row as unknown as InventoryMovement
}

// ── Type metadata ─────────────────────────────────────────────────────────────

interface MovementTypeMeta {
    label: string
    variant: 'success' | 'error' | 'info' | 'primary' | 'neutral'
    icon: typeof ArrowUpCircle
}

const movementMeta: Record<MovementType, MovementTypeMeta> = {
    entry: { label: 'Entrada', variant: 'success', icon: ArrowUpCircle },
    exit: { label: 'Salida', variant: 'error', icon: ArrowDownCircle },
    adjustment: { label: 'Ajuste', variant: 'info', icon: SlidersHorizontal },
    transfer: { label: 'Transferencia', variant: 'primary', icon: ArrowLeftRight },
}

function metaFor(type: MovementType): MovementTypeMeta {
    return movementMeta[type]
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('es', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

function quantityClass(movement: InventoryMovement): string {
    return movement.quantity > 0 ? 'text-success font-bold' : 'text-error font-bold'
}

function quantityLabel(movement: InventoryMovement): string {
    return movement.quantity > 0 ? `+${movement.quantity}` : String(movement.quantity)
}

// Adapt api.ts PaginatedMeta (no from/to) → usePaginated PaginatedMeta (with from/to).
const paginationMeta = computed<PaginatedMeta | null>(() => {
    const m = store.movementsPagination.meta
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

const typeOptions: { value: MovementType | ''; label: string }[] = [
    { value: '', label: 'Todos los tipos' },
    { value: 'entry', label: 'Entradas' },
    { value: 'exit', label: 'Salidas' },
    { value: 'adjustment', label: 'Ajustes' },
    { value: 'transfer', label: 'Transferencias' },
]
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Page header -->
        <div class="flex items-center gap-3">
            <AppButton
                variant="ghost"
                size="sm"
                :icon="ArrowLeft"
                to="/admin/inventory"
            >
                Inventario
            </AppButton>
            <div class="grow">
                <h1 class="serif text-2xl text-on-surface">Historial de movimientos</h1>
                <p class="text-xs text-on-surface-variant mt-0.5">
                    Entradas, salidas, ajustes y transferencias de stock
                </p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-48">
                <AppInput
                    v-model="searchQuery"
                    placeholder="Buscar SKU o producto..."
                >
                    <template #icon>
                        <Search :size="14" class="text-on-surface-variant" />
                    </template>
                </AppInput>
            </div>

            <!-- Branch -->
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

            <!-- Type -->
            <select
                v-model="selectedType"
                class="px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                       focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
                <option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>

            <!-- Date range -->
            <div class="flex items-center gap-2">
                <input
                    v-model="fromDate"
                    type="date"
                    class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30"
                    aria-label="Desde fecha"
                />
                <span class="text-on-surface-variant text-xs">hasta</span>
                <input
                    v-model="toDate"
                    type="date"
                    class="px-3 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30"
                    aria-label="Hasta fecha"
                />
            </div>
        </div>

        <!-- Loading -->
        <div v-if="store.isLoading" class="flex justify-center py-12">
            <AppSpinner />
        </div>

        <template v-else>
            <AppTable
                :columns="columns"
                :rows="tableRows"
                row-key="id"
            >
                <!-- Date cell -->
                <template #cell-created_at="{ row }">
                    <span class="text-xs text-on-surface-variant tabular-nums">
                        {{ formatDate(asMovement(row).created_at) }}
                    </span>
                </template>

                <!-- Type badge -->
                <template #cell-type_badge="{ row }">
                    <AppBadge :variant="metaFor(asMovement(row).type).variant" size="sm">
                        <component :is="metaFor(asMovement(row).type).icon" :size="10" class="mr-0.5" />
                        {{ metaFor(asMovement(row).type).label }}
                    </AppBadge>
                </template>

                <!-- Product info -->
                <template #cell-product_info="{ row }">
                    <div class="min-w-0">
                        <p class="text-sm text-on-surface truncate">
                            {{ asMovement(row).product_variant?.product?.name ?? 'Producto desconocido' }}
                        </p>
                        <p class="text-xs text-on-surface-variant font-mono">
                            {{ asMovement(row).product_variant?.sku ?? `Variante ${asMovement(row).product_variant_id}` }}
                        </p>
                    </div>
                </template>

                <!-- Branch -->
                <template #cell-branch="{ row }">
                    <span class="text-sm text-on-surface-variant">
                        {{ asMovement(row).branch?.name ?? asMovement(row).branch_id }}
                    </span>
                </template>

                <!-- Quantity -->
                <template #cell-quantity="{ row }">
                    <span :class="quantityClass(asMovement(row))" class="tabular-nums text-sm">
                        {{ quantityLabel(asMovement(row)) }}
                    </span>
                </template>

                <!-- User -->
                <template #cell-user="{ row }">
                    <span class="text-sm text-on-surface-variant">
                        {{ asMovement(row).user?.name ?? '—' }}
                    </span>
                </template>

                <!-- Notes -->
                <template #cell-notes="{ row }">
                    <span class="text-xs text-on-surface-variant truncate max-w-[180px] block">
                        {{ asMovement(row).notes ?? '—' }}
                    </span>
                </template>

                <!-- Empty state -->
                <template #empty>
                    <div class="py-10 text-center text-sm text-on-surface-variant">
                        No hay movimientos con los filtros seleccionados.
                    </div>
                </template>
            </AppTable>

            <AppPagination
                v-if="paginationMeta && paginationMeta.last_page > 1"
                :meta="paginationMeta"
                @page-change="loadMovements"
            />
        </template>
    </div>
</template>
