<script setup lang="ts">
/**
 * DashboardPage (S9-E2) — real KPIs + Chart.js sales chart, wired to
 * GET /api/v1/dashboard. Replaces the previous static mock.
 */
import { ref, computed, onMounted } from 'vue'
import {
    TrendingUp, ClipboardList, Package, Receipt, ShoppingCart, AlertTriangle,
} from 'lucide-vue-next'
import KpiCard from '@/components/composite/KpiCard.vue'
import SalesLineChart from '@/components/composite/SalesLineChart.vue'
import LowStockCard from '@/components/composite/LowStockCard.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppButton from '@/components/base/AppButton.vue'
import DashboardService from '@/services/DashboardService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import type { DashboardSummary, DashboardRange } from '@/types/domain/Dashboard'

const { formatCents } = useFormatCurrency()
const { formatDateTime } = useFormatDate()

onMounted(() => {
    document.title = 'Panel — Eternova'
    void load()
})

const pageState = ref<'loading' | 'loaded' | 'error'>('loading')
const summary = ref<DashboardSummary | null>(null)
const range = ref<DashboardRange>(14)

async function load(): Promise<void> {
    pageState.value = 'loading'
    try {
        summary.value = await DashboardService.get(range.value)
        pageState.value = 'loaded'
    } catch {
        pageState.value = 'error'
    }
}

function setRange(r: DashboardRange): void {
    if (range.value === r) return
    range.value = r
    void load()
}

const kpis = computed(() => {
    const k = summary.value?.kpis
    if (!k) return []
    return [
        { label: 'Ventas hoy', value: formatCents(k.today_sales_cents), trend: 'al día de hoy', variant: 'primary' as const, icon: TrendingUp },
        { label: 'Pedidos pendientes', value: String(k.pending_orders), trend: 'activos', variant: 'warning' as const, icon: ClipboardList },
        { label: 'Stock bajo', value: `${k.low_stock_count} ${k.low_stock_count === 1 ? 'artículo' : 'artículos'}`, trend: 'bajo el mínimo', variant: 'error' as const, icon: Package },
        { label: 'Gastos del mes', value: formatCents(k.month_expenses_cents), trend: 'mes actual', variant: 'info' as const, icon: Receipt },
    ]
})

const salesTotal = computed(() =>
    (summary.value?.sales_series ?? []).reduce((acc, p) => acc + p.total_cents, 0),
)

const maxUnits = computed(() =>
    Math.max(1, ...(summary.value?.top_products ?? []).map((p) => p.units)),
)

const orderStatusVariant = (s: string): 'primary' | 'success' | 'warning' | 'info' | 'neutral' =>
    (({ pending: 'warning', preparing: 'info', ready: 'info', dispatched: 'primary', delivered: 'success', cancelled: 'neutral' } as const)[s] ?? 'neutral')

const orderStatusLabel = (s: string): string =>
    (({ pending: 'Pendiente', preparing: 'Preparando', ready: 'Listo', dispatched: 'Despachado', delivered: 'Entregado', cancelled: 'Cancelado' } as const)[s] ?? s)
</script>

<template>
    <!-- Loading -->
    <div v-if="pageState === 'loading'" class="flex flex-col items-center justify-center py-32 gap-4" aria-busy="true">
        <AppSpinner size="lg" />
        <p class="text-sm text-on-surface-variant">Cargando panel…</p>
    </div>

    <!-- Error -->
    <div v-else-if="pageState === 'error'" class="flex flex-col items-center justify-center py-32 gap-5 text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center bg-error-container">
            <AlertTriangle :size="28" class="text-error" aria-hidden="true" />
        </div>
        <p class="serif text-2xl text-on-surface tracking-tighter">No se pudo cargar el panel</p>
        <AppButton variant="secondary" @click="load">Reintentar</AppButton>
    </div>

    <!-- Loaded -->
    <div v-else-if="summary" style="padding-bottom: 32px" data-testid="dashboard-root">
        <!-- KPI grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <KpiCard
                v-for="(kpi, i) in kpis"
                :key="kpi.label"
                :title="kpi.label"
                :value="kpi.value"
                :trend="kpi.trend"
                :variant="kpi.variant"
                class="fade-in"
                data-testid="kpi-card"
                :style="{ animationDelay: `${i * 60}ms` }"
            >
                <template #icon>
                    <component :is="kpi.icon" :size="18" />
                </template>
            </KpiCard>
        </div>

        <!-- Chart + top products row -->
        <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-4 mb-6">
            <!-- Sales chart -->
            <div class="card" style="padding: 24px" data-testid="sales-chart">
                <div class="flex justify-between items-start mb-6 flex-wrap gap-3">
                    <div>
                        <p class="label-gilt mb-1">Ventas · últimos {{ summary.range_days }} días</p>
                        <p class="serif text-2xl text-on-surface">{{ formatCents(salesTotal) }}</p>
                    </div>
                    <div class="tabs">
                        <button :class="['tab', { active: range === 7 }]" data-testid="range-7" @click="setRange(7)">7d</button>
                        <button :class="['tab', { active: range === 14 }]" data-testid="range-14" @click="setRange(14)">14d</button>
                        <button :class="['tab', { active: range === 30 }]" data-testid="range-30" @click="setRange(30)">30d</button>
                    </div>
                </div>
                <SalesLineChart :points="summary.sales_series" :format="formatCents" />
            </div>

            <!-- Top products -->
            <div class="card" style="padding: 24px; background: var(--surface-low)" data-testid="top-products">
                <p class="label-gilt mb-4">Más vendidos del mes</p>
                <div v-if="summary.top_products.length === 0" class="text-sm text-on-surface-variant text-center py-8">
                    Sin ventas registradas este mes.
                </div>
                <div v-else class="flex flex-col gap-3">
                    <div v-for="p in summary.top_products" :key="p.name" class="flex items-center gap-4">
                        <div class="grow min-w-0">
                            <p class="serif text-base text-on-surface truncate">{{ p.name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ p.units }} unidades</p>
                        </div>
                        <div class="w-20 h-1.5 rounded-full overflow-hidden shrink-0" style="background: var(--surface-mid)">
                            <div :style="{ width: `${(p.units / maxUnits) * 100}%`, background: 'var(--gradient)' }" class="h-full rounded-full" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent activity + restock needs -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Recent orders -->
        <div class="card" style="padding: 24px" data-testid="recent-orders">
            <p class="label-gilt mb-4">Actividad reciente</p>
            <div v-if="summary.recent_orders.length === 0" class="text-sm text-on-surface-variant text-center py-8">
                Aún no hay pedidos.
            </div>
            <div v-else class="flex flex-col gap-3">
                <div v-for="o in summary.recent_orders" :key="o.id" class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-full flex items-center justify-center shrink-0" style="background: var(--surface-low); color: var(--primary)">
                        <ShoppingCart :size="14" />
                    </span>
                    <div class="grow min-w-0">
                        <p class="text-sm text-on-surface truncate">
                            {{ o.order_number }}
                            <span class="text-on-surface-variant">· {{ o.customer_name ?? 'Cliente de mostrador' }}</span>
                        </p>
                        <p class="text-xs text-on-surface-variant mt-0.5">{{ o.created_at ? formatDateTime(o.created_at) : '' }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        <span class="text-sm font-semibold text-on-surface tabular-nums">{{ formatCents(o.total_cents) }}</span>
                        <AppBadge :variant="orderStatusVariant(o.status)" size="sm">{{ orderStatusLabel(o.status) }}</AppBadge>
                    </div>
                </div>
            </div>
        </div>

        <LowStockCard :items="summary.low_stock_items" />
        </div>
    </div>
</template>
