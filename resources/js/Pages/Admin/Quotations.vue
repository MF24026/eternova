<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import Slideover from '@/Components/Slideover.vue'
import { Plus, FileText, ArrowRight } from 'lucide-vue-next'


interface Quote {
    id: string
    customer: string
    date: string
    validUntil: string
    total: number
    status: keyof typeof statusInfo
    items: number
}

const statusInfo = {
    borrador: { label: 'Borrador', tone: 'soft' },
    enviada: { label: 'Enviada', tone: 'info' },
    aceptada: { label: 'Aceptada', tone: 'success' },
    rechazada: { label: 'Rechazada', tone: 'error' },
} as const

const quotes = ref<Quote[]>([
    { id: 'Q-0042', customer: 'Hotel Sheraton', date: 'Hoy', total: 1240.00, status: 'enviada', items: 8, validUntil: '22 mayo' },
    { id: 'Q-0041', customer: 'Boda Sofía R.', date: 'Ayer', total: 845.00, status: 'aceptada', items: 12, validUntil: '20 mayo' },
    { id: 'Q-0040', customer: 'Empresa Davivienda', date: '12 mayo', total: 2100.00, status: 'borrador', items: 25, validUntil: '30 mayo' },
    { id: 'Q-0039', customer: 'Lucía Pérez', date: '10 mayo', total: 220.00, status: 'rechazada', items: 3, validUntil: '17 mayo' },
    { id: 'Q-0038', customer: 'Día de la madre — Café El Olivar', date: '8 mayo', total: 580.00, status: 'aceptada', items: 14, validUntil: '15 mayo' },
    { id: 'Q-0037', customer: 'Boutique Rosal', date: '5 mayo', total: 960.00, status: 'enviada', items: 10, validUntil: '19 mayo' },
    { id: 'Q-0036', customer: 'Fundación Caminos', date: '3 mayo', total: 450.00, status: 'borrador', items: 6, validUntil: '17 mayo' },
    { id: 'Q-0035', customer: 'Restaurante La Paloma', date: '1 mayo', total: 1800.00, status: 'aceptada', items: 20, validUntil: '15 mayo' },
])

const filterStatus = ref<'all' | string>('all')
const selected = ref<Quote | null>(null)

const shown = computed(() =>
    filterStatus.value === 'all'
        ? quotes.value
        : quotes.value.filter(q => q.status === filterStatus.value)
)

const MOCK_LINE_ITEMS = [
    { kind: 'rose', tone: 'rose', name: 'Rosa Eterna Carmesí', price: 65.00, qty: 2 },
    { kind: 'rose', tone: 'lilac', name: 'Bouquet Aurora', price: 89.00, qty: 1 },
    { kind: 'peluche', tone: 'rose', name: 'Peluche Olivia', price: 32.00, qty: 3 },
]

function openNew() {
    selected.value = { id: 'Nueva', customer: '', date: 'Hoy', validUntil: '', total: 0, status: 'borrador', items: 0 }
}

function getStatusInfo(s: string) {
    return statusInfo[s as keyof typeof statusInfo] ?? { label: s, tone: 'soft' }
}
</script>

<template>
    <AdminLayout title="Cotizaciones" breadcrumb="Ventas">
        <div style="height: calc(100vh - 120px); overflow: hidden">
            <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
                <!-- Toolbar -->
                <div class="row" style="margin-bottom: 16px; gap: 12px; flex-wrap: wrap">
                    <div class="scroll" style="max-width: 100%">
                        <div class="tabs" style="display: inline-flex">
                            <button :class="['tab', { active: filterStatus === 'all' }]" @click="filterStatus = 'all'">
                                Todas ({{ quotes.length }})
                            </button>
                            <button
                                v-for="[k, v] in Object.entries(statusInfo)"
                                :key="k"
                                :class="['tab', { active: filterStatus === k }]"
                                @click="filterStatus = k"
                            >
                                {{ v.label }}
                            </button>
                        </div>
                    </div>
                    <div class="grow"/>
                    <button class="btn btn-primary" @click="openNew">
                        <Plus :size="14" style="margin-right: 4px"/> Nueva cotización
                    </button>
                </div>

                <!-- Table header (desktop) -->
                <div class="quot-header">
                    <div v-for="h in ['Número', 'Cliente', 'Fecha', 'Items', 'Vence', 'Estado', 'Total']" :key="h" class="label" style="font-size: 10px">{{ h }}</div>
                </div>

                <div class="scroll" style="flex: 1; min-height: 0">
                    <div class="stack" style="gap: 6px">
                        <button
                            v-for="q in shown"
                            :key="q.id"
                            style="background: var(--surface-low); border-radius: var(--r-lg); padding: 14px 16px; text-align: left; display: block; width: 100%"
                            @click="selected = q"
                        >
                            <!-- Mobile -->
                            <div class="quot-row-mobile">
                                <div class="row" style="justify-content: space-between; margin-bottom: 6px">
                                    <span class="serif" style="font-size: 16px">{{ q.customer }}</span>
                                    <span class="serif" style="color: var(--primary); font-weight: 600">${{ q.total.toFixed(2) }}</span>
                                </div>
                                <div class="row" style="justify-content: space-between; font-size: 12px; color: var(--on-surface-variant)">
                                    <span>{{ q.id }} · {{ q.date }}</span>
                                    <span :class="`bloom bloom-${getStatusInfo(q.status).tone}`" style="font-size: 10px; padding: 3px 8px">
                                        {{ getStatusInfo(q.status).label }}
                                    </span>
                                </div>
                            </div>
                            <!-- Desktop -->
                            <div class="quot-row-desktop">
                                <div style="font-weight: 600; font-size: 13px">{{ q.id }}</div>
                                <div class="serif" style="font-size: 15px">{{ q.customer }}</div>
                                <div style="font-size: 12px; color: var(--on-surface-variant)">{{ q.date }}</div>
                                <div style="font-size: 12px; color: var(--on-surface-variant)">{{ q.items }} items</div>
                                <div style="font-size: 12px; color: var(--on-surface-variant)">{{ q.validUntil }}</div>
                                <div>
                                    <span :class="`bloom bloom-${getStatusInfo(q.status).tone}`" style="font-size: 11px">
                                        {{ getStatusInfo(q.status).label }}
                                    </span>
                                </div>
                                <div style="text-align: right; color: var(--primary); font-weight: 700; font-size: 15px">
                                    ${{ q.total.toFixed(2) }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quote detail / editor slideover -->
        <Slideover
            :open="!!selected"
            :title="selected?.customer || 'Sin asignar'"
            :subtitle="`Cotización ${selected?.id ?? ''}`"
            @close="selected = null"
        >
            <template v-if="selected">
                <div class="stack" style="gap: 18px">
                    <!-- Status badges -->
                    <div class="row" style="gap: 10px; flex-wrap: wrap">
                        <span v-if="selected.status" :class="`bloom bloom-${getStatusInfo(selected.status).tone}`">
                            {{ getStatusInfo(selected.status).label }}
                        </span>
                        <span class="bloom bloom-soft">Vence {{ selected.validUntil || '—' }}</span>
                    </div>

                    <div>
                        <label class="field-label">Cliente</label>
                        <input v-model="selected.customer" class="field"/>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div>
                            <label class="field-label">Válida hasta</label>
                            <input v-model="selected.validUntil" class="field"/>
                        </div>
                        <div>
                            <label class="field-label">Términos</label>
                            <select class="field">
                                <option>50% anticipo, 50% al entregar</option>
                                <option>Pago contra entrega</option>
                                <option>30 días</option>
                            </select>
                        </div>
                    </div>

                    <!-- Line items -->
                    <div>
                        <div class="row" style="justify-content: space-between; margin-bottom: 10px">
                            <span class="label-gilt">Líneas</span>
                            <button class="btn-secondary" style="font-size: 12px">+ Agregar</button>
                        </div>
                        <div class="stack" style="gap: 8px">
                            <div
                                v-for="item in MOCK_LINE_ITEMS"
                                :key="item.name"
                                class="row"
                                style="gap: 10px; padding: 10px; background: var(--surface-low); border-radius: var(--r-md)"
                            >
                                <div style="width: 36px; height: 36px; flex-shrink: 0">
                                    <Surrogate :kind="item.kind" :tone="item.tone" style="width: 100%; height: 100%"/>
                                </div>
                                <div class="grow" style="min-width: 0">
                                    <div style="font-size: 13px; font-weight: 600">{{ item.name }}</div>
                                    <div style="font-size: 11px; color: var(--on-surface-variant)">{{ item.qty }} × ${{ item.price.toFixed(2) }}</div>
                                </div>
                                <div style="font-size: 13px; font-weight: 600">${{ (item.price * item.qty).toFixed(2) }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div class="card" style="background: var(--surface-low); padding: 16px">
                        <div class="stack" style="gap: 6px; font-size: 13px">
                            <div class="row" style="justify-content: space-between; color: var(--on-surface-variant)">
                                <span>Subtotal</span>
                                <span>${{ selected.total ? (selected.total / 1.13).toFixed(2) : '0.00' }}</span>
                            </div>
                            <div class="row" style="justify-content: space-between; color: var(--on-surface-variant)">
                                <span>IVA 13%</span>
                                <span>${{ selected.total ? (selected.total - selected.total / 1.13).toFixed(2) : '0.00' }}</span>
                            </div>
                            <div class="row" style="justify-content: space-between; padding-top: 8px; font-size: 16px; font-weight: 700">
                                <span>Total</span>
                                <span class="serif" style="color: var(--primary); font-size: 24px">
                                    ${{ selected.total ? selected.total.toFixed(2) : '0.00' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="row" style="gap: 10px">
                    <button class="btn-icon" style="color: var(--on-surface-variant)" title="Descargar PDF">
                        <FileText :size="16"/>
                    </button>
                    <div class="grow"/>
                    <button class="btn btn-tertiary" @click="selected = null">Cerrar</button>
                    <button class="btn btn-primary">
                        <ArrowRight :size="14" style="margin-right: 4px"/> Convertir a pedido
                    </button>
                </div>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.quot-header {
    display: none;
    grid-template-columns: 120px 1.8fr 120px 100px 1fr 140px 120px;
    gap: 16px;
    padding: 10px 16px;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}

.quot-row-mobile {
    display: block;
}

.quot-row-desktop {
    display: none;
    grid-template-columns: 120px 1.8fr 120px 100px 1fr 140px 120px;
    gap: 16px;
    align-items: center;
}

@media (min-width: 1024px) {
    .quot-header {
        display: grid;
    }
    .quot-row-mobile {
        display: none;
    }
    .quot-row-desktop {
        display: grid;
    }
}
</style>
