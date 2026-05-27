<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import Slideover from '@/Components/Slideover.vue'
import { Filter, Phone, MapPin, Clock, MessageCircle, Check } from 'lucide-vue-next'


interface OrderStatus {
    id: string
    label: string
    tone: string
}

interface Order {
    id: string
    customer: string
    date: string
    source: string
    total: number
    status: string
    items: number
}

const ORDER_STATUS: OrderStatus[] = [
    { id: 'pendiente', label: 'Pendiente', tone: 'warning' },
    { id: 'preparando', label: 'Preparando', tone: 'info' },
    { id: 'listo', label: 'Listo', tone: 'primary' },
    { id: 'entregado', label: 'Entregado', tone: 'success' },
]

const orders = ref<Order[]>([
    { id: 'CC-0143', customer: 'Ana López', date: 'Hoy · 10:24', source: 'WhatsApp', total: 89.00, status: 'pendiente', items: 2 },
    { id: 'CC-0142', customer: 'María González', date: 'Hoy · 09:10', source: 'POS', total: 166.00, status: 'preparando', items: 3 },
    { id: 'CC-0141', customer: 'Sofía Ramírez', date: 'Ayer · 17:30', source: 'Web', total: 245.00, status: 'preparando', items: 4 },
    { id: 'CC-0140', customer: 'Lucía Pérez', date: 'Ayer · 14:00', source: 'WhatsApp', total: 78.00, status: 'listo', items: 1 },
    { id: 'CC-0139', customer: 'Camila Díaz', date: '13 mayo · 16:00', source: 'POS', total: 124.00, status: 'entregado', items: 2 },
    { id: 'CC-0138', customer: 'Valeria Castro', date: '13 mayo · 11:00', source: 'Web', total: 65.00, status: 'entregado', items: 1 },
    { id: 'CC-0137', customer: 'Daniela Martínez', date: '12 mayo · 15:30', source: 'WhatsApp', total: 156.00, status: 'entregado', items: 3 },
    { id: 'CC-0136', customer: 'Patricia Rojas', date: '12 mayo · 09:00', source: 'POS', total: 92.00, status: 'entregado', items: 2 },
])

const filter = ref('all')
const selected = ref<Order | null>(null)

const shown = computed(() =>
    filter.value === 'all' ? orders.value : orders.value.filter(o => o.status === filter.value)
)

function getStatus(id: string): OrderStatus {
    return ORDER_STATUS.find(s => s.id === id) ?? ORDER_STATUS[0]
}

function getStatusIdx(statusId: string): number {
    return ORDER_STATUS.findIndex(s => s.id === statusId)
}

function nextStatusLabel(statusId: string): string {
    const idx = getStatusIdx(statusId)
    return ORDER_STATUS[Math.min(ORDER_STATUS.length - 1, idx + 1)].label
}

function advance(id: string) {
    const order = orders.value.find(o => o.id === id)
    if (!order) return
    const idx = getStatusIdx(order.status)
    if (idx < ORDER_STATUS.length - 1) {
        order.status = ORDER_STATUS[idx + 1].id
        // update selected reference
        if (selected.value?.id === id) {
            selected.value = { ...order }
        }
    }
}

const MOCK_PRODUCTS = [
    { kind: 'rose', tone: 'rose', name: 'Rosa Eterna Carmesí', price: 65.00 },
    { kind: 'rose', tone: 'lilac', name: 'Bouquet Aurora', price: 89.00 },
    { kind: 'peluche', tone: 'rose', name: 'Peluche Olivia', price: 32.00 },
    { kind: 'bolso', tone: 'rose', name: 'Cartera Petalia', price: 78.00 },
]
</script>

<template>
    <AdminLayout title="Pedidos" breadcrumb="Operación">
        <div style="height: calc(100vh - 120px); overflow: hidden">
            <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
                <!-- Filters row -->
                <div class="row" style="justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px">
                    <div class="scroll" style="max-width: 100%">
                        <div class="tabs" style="display: inline-flex">
                            <button :class="['tab', { active: filter === 'all' }]" @click="filter = 'all'">
                                Todos ({{ orders.length }})
                            </button>
                            <button
                                v-for="s in ORDER_STATUS"
                                :key="s.id"
                                :class="['tab', { active: filter === s.id }]"
                                @click="filter = s.id"
                            >
                                {{ s.label }}
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-tertiary desktop-only">
                        <Filter :size="14" style="margin-right: 6px"/> Filtros
                    </button>
                </div>

                <!-- Table header (desktop) -->
                <div class="orders-header">
                    <div
                        v-for="h in ['Pedido', 'Cliente', 'Fecha', 'Items', 'Origen', 'Estado', 'Total']"
                        :key="h"
                        class="label"
                        style="font-size: 10px"
                    >
                        {{ h }}
                    </div>
                </div>

                <!-- Rows -->
                <div class="scroll" style="flex: 1; min-height: 0">
                    <div class="stack" style="gap: 6px">
                        <button
                            v-for="o in shown"
                            :key="o.id"
                            class="card-hover"
                            style="background: var(--surface-low); border-radius: var(--r-lg); padding: 14px 16px; text-align: left; display: block; width: 100%"
                            @click="selected = o"
                        >
                            <!-- Mobile layout -->
                            <div class="orders-row-mobile">
                                <div class="row" style="justify-content: space-between; margin-bottom: 6px">
                                    <span class="serif" style="font-size: 16px">{{ o.customer }}</span>
                                    <span class="serif" style="color: var(--primary); font-weight: 600">${{ o.total.toFixed(2) }}</span>
                                </div>
                                <div class="row" style="justify-content: space-between; font-size: 12px; color: var(--on-surface-variant)">
                                    <span>{{ o.id }} · {{ o.date }}</span>
                                    <span :class="`bloom bloom-${getStatus(o.status).tone}`" style="font-size: 10px; padding: 3px 8px">
                                        {{ getStatus(o.status).label }}
                                    </span>
                                </div>
                            </div>
                            <!-- Desktop layout -->
                            <div class="orders-row-desktop">
                                <div style="font-weight: 600">{{ o.id }}</div>
                                <div>{{ o.customer }}</div>
                                <div style="color: var(--on-surface-variant)">{{ o.date }}</div>
                                <div style="color: var(--on-surface-variant)">{{ o.items }}</div>
                                <div>
                                    <span class="bloom bloom-soft" style="font-size: 11px">{{ o.source }}</span>
                                </div>
                                <div>
                                    <span :class="`bloom bloom-${getStatus(o.status).tone}`" style="font-size: 11px">
                                        {{ getStatus(o.status).label }}
                                    </span>
                                </div>
                                <div style="text-align: right; color: var(--primary); font-weight: 700">
                                    ${{ o.total.toFixed(2) }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Order detail slideover -->
        <Slideover
            :open="!!selected"
            :title="selected?.customer ?? ''"
            :subtitle="`Pedido ${selected?.id ?? ''}`"
            @close="selected = null"
        >
            <template v-if="selected">
                <!-- Timeline -->
                <div class="stack" style="gap: 8px; margin-bottom: 24px">
                    <div
                        v-for="(s, i) in ORDER_STATUS"
                        :key="s.id"
                        :class="['tl-step',
                            i < getStatusIdx(selected.status) ? 'done' : '',
                            i === getStatusIdx(selected.status) ? 'active' : '',
                        ]"
                    >
                        <span :style="{
                            width: '28px', height: '28px', borderRadius: '50%',
                            background: i <= getStatusIdx(selected.status) ? 'var(--primary)' : 'var(--surface-mid)',
                            color: i <= getStatusIdx(selected.status) ? 'var(--on-primary)' : 'var(--on-surface-variant)',
                            display: 'grid', placeItems: 'center',
                            flexShrink: 0, fontSize: '12px', fontWeight: 600,
                        }">
                            <Check v-if="i < getStatusIdx(selected.status)" :size="14"/>
                            <span v-else>{{ i + 1 }}</span>
                        </span>
                        <div class="grow" :style="{ fontSize: '13px', fontWeight: i === getStatusIdx(selected.status) ? 700 : 500 }">
                            {{ s.label }}
                        </div>
                    </div>
                </div>

                <!-- Items -->
                <div class="label-gilt" style="margin-bottom: 10px">Productos</div>
                <div class="stack" style="gap: 10px; margin-bottom: 20px">
                    <div
                        v-for="p in MOCK_PRODUCTS.slice(0, selected.items)"
                        :key="p.name"
                        class="row"
                        style="gap: 12px; padding: 10px; background: var(--surface-low); border-radius: var(--r-md)"
                    >
                        <div style="width: 44px; height: 44px; flex-shrink: 0">
                            <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%"/>
                        </div>
                        <div class="grow" style="min-width: 0">
                            <div style="font-size: 13px; font-weight: 600">{{ p.name }}</div>
                            <div style="font-size: 11px; color: var(--on-surface-variant)">1 × ${{ p.price.toFixed(2) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Customer info -->
                <div class="card" style="background: var(--surface-low); padding: 16px">
                    <div class="label-gilt" style="margin-bottom: 12px">Cliente</div>
                    <div class="stack" style="gap: 8px; font-size: 13px">
                        <div class="row" style="gap: 10px"><Phone :size="14"/> +503 7892-1234</div>
                        <div class="row" style="gap: 10px"><MapPin :size="14"/> Col. Escalón, San Salvador</div>
                        <div class="row" style="gap: 10px"><Clock :size="14"/> Entrega 15 mayo · 11 AM</div>
                    </div>
                </div>

                <div style="padding-top: 16px; margin-top: 16px">
                    <div class="row" style="justify-content: space-between; font-size: 16px; font-weight: 700">
                        <span>Total</span>
                        <span class="serif" style="color: var(--primary); font-size: 26px">${{ selected.total.toFixed(2) }}</span>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="stack" style="gap: 10px">
                    <button
                        v-if="selected && selected.status !== 'entregado'"
                        class="btn btn-primary"
                        style="width: 100%; justify-content: center"
                        @click="advance(selected.id)"
                    >
                        Avanzar a {{ selected ? nextStatusLabel(selected.status) : '' }}
                    </button>
                    <button class="btn btn-tertiary" style="width: 100%; justify-content: center">
                        <MessageCircle :size="14" style="margin-right: 6px"/> Mensaje al cliente
                    </button>
                </div>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.orders-header {
    display: none;
    grid-template-columns: 100px 1.6fr 1fr 80px 1fr 140px 80px;
    gap: 16px;
    padding: 10px 16px;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}

.orders-row-mobile {
    display: block;
}

.orders-row-desktop {
    display: none;
    grid-template-columns: 100px 1.6fr 1fr 80px 1fr 140px 80px;
    gap: 16px;
    align-items: center;
    font-size: 13px;
}

@media (min-width: 1024px) {
    .orders-header {
        display: grid;
    }
    .orders-row-mobile {
        display: none;
    }
    .orders-row-desktop {
        display: grid;
    }
}
</style>
