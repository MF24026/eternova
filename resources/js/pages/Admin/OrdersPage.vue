<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Filter, Phone, MapPin, Clock, MessageCircle, Check } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'

onMounted(() => { document.title = 'Pedidos — Eternova' })

interface OrderStatus { id: string; label: string; variant: 'warning' | 'info' | 'primary' | 'success' }
interface Order { id: string; customer: string; date: string; source: string; total: number; status: string; items: number }

const ORDER_STATUS: OrderStatus[] = [
    { id: 'pendiente', label: 'Pendiente', variant: 'warning' },
    { id: 'preparando', label: 'Preparando', variant: 'info' },
    { id: 'listo', label: 'Listo', variant: 'primary' },
    { id: 'entregado', label: 'Entregado', variant: 'success' },
]

const orders = ref<Order[]>([
    { id: 'CC-0143', customer: 'Ana Lopez', date: 'Hoy · 10:24', source: 'WhatsApp', total: 89.00, status: 'pendiente', items: 2 },
    { id: 'CC-0142', customer: 'Maria Gonzalez', date: 'Hoy · 09:10', source: 'POS', total: 166.00, status: 'preparando', items: 3 },
    { id: 'CC-0141', customer: 'Sofia Ramirez', date: 'Ayer · 17:30', source: 'Web', total: 245.00, status: 'preparando', items: 4 },
    { id: 'CC-0140', customer: 'Lucia Perez', date: 'Ayer · 14:00', source: 'WhatsApp', total: 78.00, status: 'listo', items: 1 },
    { id: 'CC-0139', customer: 'Camila Diaz', date: '13 mayo · 16:00', source: 'POS', total: 124.00, status: 'entregado', items: 2 },
    { id: 'CC-0138', customer: 'Valeria Castro', date: '13 mayo · 11:00', source: 'Web', total: 65.00, status: 'entregado', items: 1 },
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
        if (selected.value?.id === id) selected.value = { ...order }
    }
}

const mockItems = [
    { name: 'Rosa Eterna Carmesi', price: 65.00 },
    { name: 'Bouquet Aurora', price: 89.00 },
    { name: 'Peluche Olivia', price: 32.00 },
    { name: 'Cartera Petalia', price: 78.00 },
]
</script>

<template>
    <div class="h-[calc(100vh-120px)] overflow-hidden">
        <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
            <!-- Filter tabs -->
            <div class="flex justify-between mb-4 flex-wrap gap-3">
                <div class="scroll">
                    <div class="tabs inline-flex">
                        <button :class="['tab', { active: filter === 'all' }]" @click="filter = 'all'">Todos ({{ orders.length }})</button>
                        <button v-for="s in ORDER_STATUS" :key="s.id" :class="['tab', { active: filter === s.id }]" @click="filter = s.id">{{ s.label }}</button>
                    </div>
                </div>
                <button class="btn btn-tertiary hidden lg:inline-flex">
                    <Filter :size="14" class="mr-1.5" /> Filtros
                </button>
            </div>

            <!-- Desktop header -->
            <div class="orders-header">
                <div v-for="h in ['Pedido', 'Cliente', 'Fecha', 'Items', 'Origen', 'Estado', 'Total']" :key="h" class="label" style="font-size: 10px">{{ h }}</div>
            </div>

            <div class="scroll flex-1 min-h-0">
                <div class="flex flex-col gap-1.5">
                    <button
                        v-for="o in shown"
                        :key="o.id"
                        class="card-hover rounded-xl p-3.5 text-left w-full block"
                        style="background: var(--surface-low)"
                        @click="selected = o"
                    >
                        <!-- Mobile -->
                        <div class="orders-row-mobile">
                            <div class="flex justify-between mb-1.5">
                                <span class="serif text-base text-on-surface">{{ o.customer }}</span>
                                <span class="serif text-primary font-semibold">${{ o.total.toFixed(2) }}</span>
                            </div>
                            <div class="flex justify-between text-xs text-on-surface-variant">
                                <span>{{ o.id }} · {{ o.date }}</span>
                                <AppBadge :variant="getStatus(o.status).variant" size="sm">{{ getStatus(o.status).label }}</AppBadge>
                            </div>
                        </div>
                        <!-- Desktop -->
                        <div class="orders-row-desktop text-sm">
                            <div class="font-semibold">{{ o.id }}</div>
                            <div>{{ o.customer }}</div>
                            <div class="text-on-surface-variant">{{ o.date }}</div>
                            <div class="text-on-surface-variant">{{ o.items }}</div>
                            <div><AppBadge variant="neutral" size="sm">{{ o.source }}</AppBadge></div>
                            <div><AppBadge :variant="getStatus(o.status).variant" size="sm">{{ getStatus(o.status).label }}</AppBadge></div>
                            <div class="text-right font-bold text-primary">${{ o.total.toFixed(2) }}</div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Order detail slideover -->
    <AppSlideover
        :model-value="!!selected"
        :title="selected?.customer ?? ''"
        :subtitle="`Pedido ${selected?.id ?? ''}`"
        @update:model-value="selected = null"
    >
        <template v-if="selected">
            <!-- Timeline -->
            <div class="flex flex-col gap-2 mb-6">
                <div
                    v-for="(s, i) in ORDER_STATUS"
                    :key="s.id"
                    class="flex items-center gap-3 p-3.5 rounded-xl"
                    :class="i === getStatusIdx(selected.status) ? 'bg-primary-container' : 'bg-surface-low'"
                >
                    <span
                        class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-xs font-semibold"
                        :style="{
                            background: i <= getStatusIdx(selected.status) ? 'var(--primary)' : 'var(--surface-mid)',
                            color: i <= getStatusIdx(selected.status) ? 'var(--on-primary)' : 'var(--on-surface-variant)',
                        }"
                    >
                        <Check v-if="i < getStatusIdx(selected.status)" :size="12" />
                        <span v-else>{{ i + 1 }}</span>
                    </span>
                    <span class="text-sm" :class="i === getStatusIdx(selected.status) ? 'font-bold text-primary-dim' : 'text-on-surface-variant'">
                        {{ s.label }}
                    </span>
                </div>
            </div>

            <!-- Items -->
            <p class="label-gilt mb-2.5">Productos</p>
            <div class="flex flex-col gap-2.5 mb-5">
                <div
                    v-for="item in mockItems.slice(0, selected.items)"
                    :key="item.name"
                    class="flex items-center gap-3 p-2.5 rounded-xl"
                    style="background: var(--surface-low)"
                >
                    <div class="w-11 h-11 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                    <div class="grow">
                        <p class="text-sm font-semibold">{{ item.name }}</p>
                        <p class="text-xs text-on-surface-variant">1 × ${{ item.price.toFixed(2) }}</p>
                    </div>
                </div>
            </div>

            <!-- Customer info -->
            <div class="rounded-xl p-4" style="background: var(--surface-low)">
                <p class="label-gilt mb-3">Cliente</p>
                <div class="flex flex-col gap-2 text-sm text-on-surface-variant">
                    <div class="flex items-center gap-2"><Phone :size="14" /> +503 7892-1234</div>
                    <div class="flex items-center gap-2"><MapPin :size="14" /> Col. Escalon, San Salvador</div>
                    <div class="flex items-center gap-2"><Clock :size="14" /> Entrega 15 mayo · 11 AM</div>
                </div>
            </div>

            <div class="flex justify-between items-center mt-5 pt-4">
                <span class="font-semibold text-on-surface">Total</span>
                <span class="serif text-3xl text-primary">${{ selected.total.toFixed(2) }}</span>
            </div>
        </template>

        <template #footer>
            <div class="flex flex-col gap-2.5">
                <AppButton
                    v-if="selected && selected.status !== 'entregado'"
                    class="w-full justify-center"
                    @click="advance(selected.id)"
                >
                    Avanzar a {{ selected ? nextStatusLabel(selected.status) : '' }}
                </AppButton>
                <AppButton variant="secondary" class="w-full justify-center">
                    <MessageCircle :size="14" class="mr-1.5" /> Mensaje al cliente
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>

<style scoped>
.orders-header {
    display: none;
    grid-template-columns: 100px 1.6fr 1fr 80px 1fr 140px 80px;
    gap: 16px;
    padding: 8px 14px;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}
.orders-row-mobile { display: block; }
.orders-row-desktop { display: none; grid-template-columns: 100px 1.6fr 1fr 80px 1fr 140px 80px; gap: 16px; align-items: center; }
@media (min-width: 1024px) {
    .orders-header { display: grid; }
    .orders-row-mobile { display: none; }
    .orders-row-desktop { display: grid; }
}
</style>
