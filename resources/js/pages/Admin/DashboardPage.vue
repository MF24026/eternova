<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { TrendingUp, ClipboardList, Package, Receipt, ShoppingCart, Calendar, Check } from 'lucide-vue-next'
import KpiCard from '@/components/composite/KpiCard.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppAvatar from '@/components/base/AppAvatar.vue'

onMounted(() => { document.title = 'Panel — Eternova' })

// KPI mock data
const kpis = [
    { label: 'Ventas hoy', value: '$1,248.50', trend: '+12% vs ayer', trendPositive: true as const, variant: 'primary' as const, icon: TrendingUp },
    { label: 'Pedidos pendientes', value: '7', trend: '3 vencen hoy', trendPositive: null, variant: 'warning' as const, icon: ClipboardList },
    { label: 'Stock bajo', value: '4 articulos', trend: 'Rosas marfil agotandose', trendPositive: false as const, variant: 'error' as const, icon: Package },
    { label: 'Gastos del mes', value: '$3,420.00', trend: '82% del presupuesto', trendPositive: null, variant: 'info' as const, icon: Receipt },
]

// Sales chart (SVG)
const chartData = [12, 18, 14, 22, 19, 26, 24, 32, 28, 36, 30, 42, 38, 48]
const w = 600
const h = 200
const maxVal = Math.max(...chartData) * 1.1
const pts = computed(() =>
    chartData.map((v, i) => [i * (w / (chartData.length - 1)), h - (v / maxVal) * h])
)
const linePath = computed(() =>
    'M ' + pts.value.map(p => p.map(n => n.toFixed(1)).join(' ')).join(' L ')
)
const fillPath = computed(() =>
    linePath.value + ` L ${w} ${h} L 0 ${h} Z`
)
const chartTab = ref<'7d' | '14d' | '30d'>('14d')

// Today's deliveries
const deliveries = [
    { name: 'Maria G.', time: '11:00 AM', area: 'Colonia Escalon', status: 'ready' },
    { name: 'Ana L.', time: '2:30 PM', area: 'Santa Tecla', status: 'prep' },
    { name: 'Sofia R.', time: '4:00 PM', area: 'Antiguo Cuscatlan', status: 'prep' },
    { name: 'Lucia P.', time: '5:30 PM', area: 'San Benito', status: 'pending' },
]

// Top products mock
const topProducts = [
    { name: 'Rosa Eterna Carmesi', count: 38 },
    { name: 'Cartera Petalia', count: 24 },
    { name: 'Llavero Camelia', count: 19 },
    { name: 'Bouquet Aurora', count: 14 },
]

// Recent activity
const activity = [
    { icon: ShoppingCart, text: 'Nuevo pedido #CC-0143 por $89.00', when: 'hace 12 min', colorVar: 'var(--primary)' },
    { icon: Calendar, text: 'Reserva confirmada — boda Sofia R.', when: 'hace 1 h', colorVar: 'var(--secondary)' },
    { icon: Receipt, text: 'Gasto agregado: rosas naturales $124', when: 'hace 3 h', colorVar: 'var(--warning)' },
    { icon: Package, text: 'Stock bajo: Rosa Eterna Marfil', when: 'hace 5 h', colorVar: 'var(--error)' },
    { icon: Check, text: 'Pedido #CC-0140 entregado', when: 'hace 8 h', colorVar: 'var(--success)' },
]

const statusBadgeVariant = (s: string): 'primary' | 'success' | 'warning' | 'info' | 'neutral' =>
    ({ ready: 'success' as const, prep: 'info' as const, pending: 'warning' as const }[s] ?? 'neutral')
const statusLabel = (s: string): string =>
    ({ ready: 'Listo', prep: 'Preparando', pending: 'Pendiente' }[s] ?? s)
</script>

<template>
    <div style="padding-bottom: 32px">
        <!-- KPI grid -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <KpiCard
                v-for="(kpi, i) in kpis"
                :key="kpi.label"
                :title="kpi.label"
                :value="kpi.value"
                :trend="kpi.trend"
                :trend-positive="kpi.trendPositive"
                :variant="kpi.variant"
                class="fade-in"
                :style="{ animationDelay: `${i * 60}ms` }"
            >
                <template #icon>
                    <component :is="kpi.icon" :size="18" />
                </template>
            </KpiCard>
        </div>

        <!-- Chart + deliveries row -->
        <div class="grid grid-cols-1 lg:grid-cols-[1.6fr_1fr] gap-4 mb-6">
            <!-- Sales chart -->
            <div class="card" style="padding: 24px">
                <div class="flex justify-between items-start mb-6 flex-wrap gap-3">
                    <div>
                        <p class="label-gilt mb-1">Ventas · ultimos 14 dias</p>
                        <p class="serif text-2xl text-on-surface">
                            $8,420
                            <span class="text-success text-sm font-sans ml-1"> +18%</span>
                        </p>
                    </div>
                    <div class="tabs">
                        <button :class="['tab', { active: chartTab === '7d' }]" @click="chartTab = '7d'">7d</button>
                        <button :class="['tab', { active: chartTab === '14d' }]" @click="chartTab = '14d'">14d</button>
                        <button :class="['tab', { active: chartTab === '30d' }]" @click="chartTab = '30d'">30d</button>
                    </div>
                </div>
                <svg :viewBox="`0 0 ${w} ${h}`" style="width:100%;height:180px" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="dash-chart-fill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="var(--primary)" stop-opacity=".25" />
                            <stop offset="100%" stop-color="var(--primary)" stop-opacity="0" />
                        </linearGradient>
                    </defs>
                    <line v-for="pct in [0.25, 0.5, 0.75]" :key="pct" x1="0" :y1="h * pct" :x2="w" :y2="h * pct" class="chart-grid" />
                    <path :d="fillPath" class="chart-fill" />
                    <path :d="linePath" class="chart-area" />
                    <circle :cx="pts[pts.length - 1][0]" :cy="pts[pts.length - 1][1]" r="5" fill="var(--primary)" />
                </svg>
            </div>

            <!-- Today's deliveries -->
            <div class="card" style="padding: 24px; background: var(--surface-low)">
                <p class="label-gilt mb-4">Entregas de hoy</p>
                <div class="flex flex-col gap-2.5">
                    <div
                        v-for="d in deliveries"
                        :key="d.name"
                        class="flex items-center gap-3 rounded-xl p-2.5"
                        style="background: var(--surface-lowest)"
                    >
                        <AppAvatar :name="d.name" size="sm" />
                        <div class="grow min-w-0">
                            <p class="text-sm font-semibold text-on-surface">{{ d.name }}</p>
                            <p class="text-xs text-on-surface-variant truncate">{{ d.area }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span class="text-xs text-on-surface-variant">{{ d.time }}</span>
                            <AppBadge :variant="statusBadgeVariant(d.status)" size="sm">
                                {{ statusLabel(d.status) }}
                            </AppBadge>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top products + activity -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Top products -->
            <div class="card" style="padding: 24px">
                <p class="label-gilt mb-4">Mas vendidos del mes</p>
                <div class="flex flex-col">
                    <div
                        v-for="p in topProducts"
                        :key="p.name"
                        class="flex items-center gap-4 py-3 border-b border-outline-variant last:border-0"
                    >
                        <div class="grow">
                            <p class="serif text-base text-on-surface">{{ p.name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ p.count }} unidades</p>
                        </div>
                        <div class="w-20 h-1.5 rounded-full overflow-hidden shrink-0" style="background: var(--surface-mid)">
                            <div
                                :style="{ width: `${(p.count / 40) * 100}%`, background: 'var(--gradient)' }"
                                class="h-full rounded-full"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent activity -->
            <div class="card" style="padding: 24px">
                <p class="label-gilt mb-4">Actividad reciente</p>
                <div class="flex flex-col gap-4">
                    <div
                        v-for="(a, i) in activity"
                        :key="i"
                        class="flex items-start gap-3"
                    >
                        <span
                            class="w-8 h-8 rounded-full flex items-center justify-center shrink-0"
                            :style="{ background: 'var(--surface-low)', color: a.colorVar }"
                        >
                            <component :is="a.icon" :size="14" />
                        </span>
                        <div class="grow min-w-0">
                            <p class="text-sm text-on-surface">{{ a.text }}</p>
                            <p class="text-xs text-on-surface-variant mt-0.5">{{ a.when }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
