<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import { TrendingUp, ClipboardList, Package, Receipt, ShoppingCart, Calendar, Check } from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

const kpis = [
    { label: 'Ventas hoy', value: '$1,248.50', delta: '+12% vs ayer', icon: TrendingUp, tone: 'primary' },
    { label: 'Pedidos pendientes', value: '7', delta: '3 vencen hoy', icon: ClipboardList, tone: 'warning' },
    { label: 'Stock bajo', value: '4 artículos', delta: 'Rosas marfil agotándose', icon: Package, tone: 'error' },
    { label: 'Gastos del mes', value: '$3,420.00', delta: '82% del presupuesto', icon: Receipt, tone: 'info' },
]

const toneBg: Record<string, string> = {
    primary: 'var(--primary-container)',
    warning: 'var(--warning-container)',
    error: 'var(--error-container)',
    info: 'var(--info-container)',
}
const toneText: Record<string, string> = {
    primary: 'var(--primary)',
    warning: 'var(--warning)',
    error: 'var(--error)',
    info: 'var(--info)',
}

// Sales chart
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

const deliveries = [
    { name: 'María G.', initials: 'MG', time: '11:00 AM', area: 'Colonia Escalón', status: 'ready' },
    { name: 'Ana L.', initials: 'AL', time: '2:30 PM', area: 'Santa Tecla', status: 'prep' },
    { name: 'Sofía R.', initials: 'SR', time: '4:00 PM', area: 'Antiguo Cuscatlán', status: 'prep' },
    { name: 'Lucía P.', initials: 'LP', time: '5:30 PM', area: 'San Benito', status: 'pending' },
]

const topProducts = [
    { name: 'Rosa Eterna Carmesí', kind: 'rose', tone: 'rose', count: 38 },
    { name: 'Cartera Petalia', kind: 'bolso', tone: 'rose', count: 24 },
    { name: 'Llavero Camelia', kind: 'llavero', tone: 'cream', count: 19 },
    { name: 'Bouquet Aurora', kind: 'rose', tone: 'lilac', count: 14 },
]

const activity = [
    { icon: ShoppingCart, text: 'Nuevo pedido #CC-0143 por $89.00', when: 'hace 12 min', color: 'var(--primary)' },
    { icon: Calendar, text: 'Reserva confirmada — boda Sofía R.', when: 'hace 1 h', color: 'var(--secondary)' },
    { icon: Receipt, text: 'Gasto agregado: rosas naturales $124', when: 'hace 3 h', color: 'var(--warning)' },
    { icon: Package, text: 'Stock bajo: Rosa Eterna Marfil', when: 'hace 5 h', color: 'var(--error)' },
    { icon: Check, text: 'Pedido #CC-0140 entregado', when: 'hace 8 h', color: 'var(--success)' },
]
</script>

<template>
    <AdminLayout title="Bienvenida, Carolina" breadcrumb="Panel">
        <div class="scroll" style="padding-bottom: 32px">
            <!-- KPI row -->
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px"
                 class="lg-grid-4">
                <div
                    v-for="(k, i) in kpis"
                    :key="k.label"
                    class="kpi fade-in"
                    :style="{ animationDelay: `${i * 60}ms` }"
                >
                    <div class="kpi-bloom" :style="{ background: toneBg[k.tone] }"/>
                    <div :style="{
                        width: '36px', height: '36px',
                        borderRadius: 'var(--r-md)',
                        background: toneBg[k.tone],
                        color: toneText[k.tone],
                        display: 'grid', placeItems: 'center',
                        position: 'relative',
                    }">
                        <component :is="k.icon" :size="18"/>
                    </div>
                    <div class="label" style="position: relative">{{ k.label }}</div>
                    <div class="serif" style="font-size: 26px; line-height: 1; position: relative">{{ k.value }}</div>
                    <div style="font-size: 12px; color: var(--on-surface-variant); position: relative">{{ k.delta }}</div>
                </div>
            </div>

            <!-- Chart + Deliveries -->
            <div style="display: grid; gap: 16px; margin-bottom: 24px" class="lg-grid-chart">
                <!-- Sales chart -->
                <div class="card" style="padding: 24px">
                    <div class="row" style="justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px">
                        <div>
                            <div class="label-gilt" style="margin-bottom: 4px">Ventas · últimos 14 días</div>
                            <div class="serif" style="font-size: 24px">
                                $8,420
                                <span style="color: var(--success); font-size: 14px; font-family: var(--font-sans)"> +18%</span>
                            </div>
                        </div>
                        <div class="tabs">
                            <button :class="['tab', { active: chartTab === '7d' }]" @click="chartTab = '7d'">7d</button>
                            <button :class="['tab', { active: chartTab === '14d' }]" @click="chartTab = '14d'">14d</button>
                            <button :class="['tab', { active: chartTab === '30d' }]" @click="chartTab = '30d'">30d</button>
                        </div>
                    </div>
                    <svg :viewBox="`0 0 ${w} ${h}`" style="width: 100%; height: 180px" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="chart-fill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="var(--primary)" stop-opacity=".25"/>
                                <stop offset="100%" stop-color="var(--primary)" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <line v-for="p in [0.25, 0.5, 0.75]" :key="p"
                            x1="0" :y1="h * p" :x2="w" :y2="h * p"
                            class="chart-grid"/>
                        <path :d="fillPath" class="chart-fill"/>
                        <path :d="linePath" class="chart-area"/>
                        <circle
                            :cx="pts[pts.length - 1][0]"
                            :cy="pts[pts.length - 1][1]"
                            r="5"
                            fill="var(--primary)"
                        />
                    </svg>
                </div>

                <!-- Today's deliveries -->
                <div class="card" style="padding: 24px; background: var(--surface-low)">
                    <div class="label-gilt" style="margin-bottom: 16px">Entregas de hoy</div>
                    <div class="stack" style="gap: 10px">
                        <div
                            v-for="d in deliveries"
                            :key="d.name"
                            class="row"
                            style="gap: 12px; padding: 10px; border-radius: var(--r-md); background: var(--surface-lowest)"
                        >
                            <span style="width: 36px; height: 36px; border-radius: 50%; background: var(--gradient-soft);
                                display: grid; place-items: center; color: var(--primary-dim); font-weight: 600;
                                font-size: 13px; flex-shrink: 0">
                                {{ d.initials }}
                            </span>
                            <div class="grow" style="min-width: 0">
                                <div style="font-size: 13px; font-weight: 600">{{ d.name }}</div>
                                <div style="font-size: 11px; color: var(--on-surface-variant);
                                    overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                                    {{ d.area }}
                                </div>
                            </div>
                            <div style="font-size: 11px; color: var(--on-surface-variant); flex-shrink: 0">{{ d.time }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top products + Activity -->
            <div style="display: grid; gap: 16px" class="lg-grid-2">
                <div class="card" style="padding: 24px">
                    <div class="label-gilt" style="margin-bottom: 16px">Más vendidos del mes</div>
                    <div
                        v-for="p in topProducts"
                        :key="p.name"
                        class="row"
                        style="gap: 14px; padding: 12px 0"
                    >
                        <div style="width: 48px; height: 48px; flex-shrink: 0">
                            <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%"/>
                        </div>
                        <div class="grow">
                            <div class="serif" style="font-size: 16px">{{ p.name }}</div>
                            <div style="font-size: 12px; color: var(--on-surface-variant)">{{ p.count }} unidades</div>
                        </div>
                        <div style="width: 80px; height: 4px; background: var(--surface-mid);
                            border-radius: 99px; overflow: hidden; flex-shrink: 0">
                            <div :style="{ width: `${(p.count / 40) * 100}%`, height: '100%', background: 'var(--gradient)' }"/>
                        </div>
                    </div>
                </div>

                <div class="card" style="padding: 24px">
                    <div class="label-gilt" style="margin-bottom: 16px">Actividad reciente</div>
                    <div class="stack" style="gap: 14px">
                        <div
                            v-for="(a, i) in activity"
                            :key="i"
                            class="row"
                            style="gap: 12px"
                        >
                            <span :style="{
                                width: '32px', height: '32px', borderRadius: '50%',
                                background: 'var(--surface-low)',
                                display: 'grid', placeItems: 'center',
                                color: a.color, flexShrink: 0,
                            }">
                                <component :is="a.icon" :size="14"/>
                            </span>
                            <div class="grow" style="min-width: 0">
                                <div style="font-size: 13px; color: var(--on-surface)">{{ a.text }}</div>
                                <div style="font-size: 11px; color: var(--on-surface-variant)">{{ a.when }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.lg-grid-4 {
    grid-template-columns: repeat(2, 1fr);
}
.lg-grid-chart {
    grid-template-columns: 1fr;
}
.lg-grid-2 {
    grid-template-columns: 1fr;
}

@media (min-width: 1024px) {
    .lg-grid-4 {
        grid-template-columns: repeat(4, 1fr);
    }
    .lg-grid-chart {
        grid-template-columns: 1.6fr 1fr;
    }
    .lg-grid-2 {
        grid-template-columns: 1fr 1fr;
    }
}
</style>
