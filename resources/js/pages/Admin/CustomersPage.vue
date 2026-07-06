<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Search, Plus, Phone, MapPin, MessageCircle } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppAvatar from '@/components/base/AppAvatar.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'

onMounted(() => { document.title = 'Clientes — Eternova' })

interface Customer {
    id: number; name: string; phone: string; area: string
    orders: number; total: number; last: string; avgTicket: number; since: string
}

const customers = ref<Customer[]>([
    { id: 1, name: 'Ana Lopez', phone: '+503 7892-1234', area: 'Col. Escalon', orders: 12, total: 1240.00, last: 'Hoy', avgTicket: 103.33, since: 'marzo 2024' },
    { id: 2, name: 'Maria Gonzalez', phone: '+503 7654-3210', area: 'Santa Tecla', orders: 8, total: 890.00, last: 'Hoy', avgTicket: 111.25, since: 'enero 2024' },
    { id: 3, name: 'Sofia Ramirez', phone: '+503 7123-4567', area: 'Antiguo Cuscatlan', orders: 6, total: 2100.00, last: 'Ayer', avgTicket: 350.00, since: 'junio 2023' },
    { id: 4, name: 'Lucia Perez', phone: '+503 7890-1122', area: 'San Benito', orders: 4, total: 340.00, last: '10 mayo', avgTicket: 85.00, since: 'octubre 2024' },
    { id: 5, name: 'Valeria Castro', phone: '+503 7445-9988', area: 'Lomas Verdes', orders: 9, total: 1580.00, last: '8 mayo', avgTicket: 175.56, since: 'abril 2023' },
    { id: 6, name: 'Daniela Martinez', phone: '+503 7332-5566', area: 'Col. Medica', orders: 3, total: 180.00, last: '5 mayo', avgTicket: 60.00, since: 'febrero 2025' },
    { id: 7, name: 'Patricia Rojas', phone: '+503 7221-4433', area: 'Merliot', orders: 7, total: 680.00, last: '3 mayo', avgTicket: 97.14, since: 'agosto 2023' },
    { id: 8, name: 'Carmen Flores', phone: '+503 7998-6655', area: 'San Salvador Centro', orders: 5, total: 420.00, last: '1 mayo', avgTicket: 84.00, since: 'noviembre 2023' },
])

const searchQuery = ref('')
const selected = ref<Customer | null>(null)

const filtered = computed(() => {
    if (!searchQuery.value) return customers.value
    const q = searchQuery.value.toLowerCase()
    return customers.value.filter(c => c.name.toLowerCase().includes(q) || c.phone.includes(q))
})

const recentOrders = [
    { id: 'CC-0143', date: 'Hoy', total: 89.00, status: 'Preparando', variant: 'info' as const },
    { id: 'CC-0128', date: '2 mayo', total: 156.00, status: 'Entregado', variant: 'success' as const },
    { id: 'CC-0119', date: '20 abril', total: 245.00, status: 'Entregado', variant: 'success' as const },
]
</script>

<template>
    <div class="h-[calc(100vh-120px)] overflow-hidden">
        <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
            <div class="flex gap-3 mb-4">
                <div class="relative flex-1">
                    <AppInput v-model="searchQuery" placeholder="Buscar por nombre, teléfono...">
                        <template #icon><Search :size="16" /></template>
                    </AppInput>
                </div>
                <AppButton :icon="Plus">Cliente</AppButton>
            </div>

            <!-- Desktop header -->
            <div class="cust-header">
                <div v-for="h in ['', 'Cliente', 'Contacto', 'Zona', 'Pedidos', 'Total', 'último']" :key="h" class="label" style="font-size: 10px">{{ h }}</div>
            </div>

            <div class="scroll flex flex-col gap-1.5 flex-1 min-h-0">
                <button
                    v-for="c in filtered"
                    :key="c.id"
                    class="cust-row"
                    @click="selected = c"
                >
                    <AppAvatar :name="c.name" size="sm" class="hidden lg:flex" />
                    <!-- Mobile -->
                    <div class="cust-mobile">
                        <div class="flex justify-between mb-1.5">
                            <span class="serif text-base text-on-surface">{{ c.name }}</span>
                            <span class="font-bold text-primary">${{ c.total.toFixed(2) }}</span>
                        </div>
                        <div class="text-xs text-on-surface-variant">{{ c.phone }} · {{ c.orders }} pedidos</div>
                    </div>
                    <!-- Desktop -->
                    <div class="cust-desktop-name hidden lg:flex items-center">
                        <span class="serif text-base">{{ c.name }}</span>
                    </div>
                    <div class="cust-desktop text-xs text-on-surface-variant hidden lg:flex items-center">{{ c.phone }}</div>
                    <div class="cust-desktop text-xs text-on-surface-variant hidden lg:flex items-center">{{ c.area }}</div>
                    <div class="cust-desktop text-sm font-semibold hidden lg:flex items-center">{{ c.orders }}</div>
                    <div class="cust-desktop text-sm font-bold text-primary hidden lg:flex items-center">${{ c.total.toFixed(2) }}</div>
                    <div class="cust-desktop text-xs text-on-surface-variant hidden lg:flex items-center">{{ c.last }}</div>
                </button>
            </div>
        </div>
    </div>

    <!-- Customer detail -->
    <AppSlideover
        :model-value="!!selected"
        :title="selected?.name ?? ''"
        subtitle="Cliente"
        @update:model-value="selected = null"
    >
        <template v-if="selected">
            <div class="flex flex-col gap-5">
                <div class="flex items-center gap-4">
                    <AppAvatar :name="selected.name" size="xl" />
                    <div>
                        <p class="serif text-2xl text-on-surface">{{ selected.name }}</p>
                        <p class="text-xs text-on-surface-variant">Cliente desde {{ selected.since }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl p-4" style="background: var(--surface-low)">
                        <p class="label-gilt mb-1">Pedidos</p>
                        <p class="serif text-3xl text-on-surface">{{ selected.orders }}</p>
                    </div>
                    <div class="rounded-xl p-4" style="background: var(--surface-low)">
                        <p class="label-gilt mb-1">Total</p>
                        <p class="serif text-3xl text-primary">${{ selected.total.toFixed(2) }}</p>
                    </div>
                </div>
                <div class="rounded-xl p-4" style="background: var(--surface-low)">
                    <p class="label-gilt mb-3">Contacto</p>
                    <div class="flex flex-col gap-2 text-sm text-on-surface-variant">
                        <div class="flex items-center gap-2"><Phone :size="14" /> {{ selected.phone }}</div>
                        <div class="flex items-center gap-2"><MapPin :size="14" /> {{ selected.area }}, San Salvador</div>
                        <div class="flex items-center gap-2"><MessageCircle :size="14" /> Cumpleanos: 14 abril</div>
                    </div>
                </div>
                <div>
                    <p class="label-gilt mb-2.5">Pedidos recientes</p>
                    <div class="flex flex-col gap-1.5">
                        <div v-for="o in recentOrders" :key="o.id" class="flex items-center gap-3 p-2.5 rounded-xl" style="background: var(--surface-low)">
                            <div class="grow">
                                <p class="text-sm font-semibold">{{ o.id }}</p>
                                <p class="text-xs text-on-surface-variant">{{ o.date }}</p>
                            </div>
                            <AppBadge :variant="o.variant" size="sm">{{ o.status }}</AppBadge>
                            <span class="font-bold text-sm">${{ o.total.toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        <template #footer>
            <AppButton class="w-full justify-center">
                <MessageCircle :size="14" class="mr-1.5" /> Escribir por WhatsApp
            </AppButton>
        </template>
    </AppSlideover>
</template>

<style scoped>
.cust-header {
    display: none;
    grid-template-columns: 48px 1.5fr 1.4fr 1fr 80px 120px 120px;
    gap: 16px;
    padding: 6px 14px;
    margin-bottom: 4px;
}
.cust-row {
    background: var(--surface-low);
    border-radius: var(--r-lg);
    padding: 12px 14px;
    text-align: left;
    display: block;
    width: 100%;
    cursor: pointer;
    transition: background 0.2s;
}
.cust-row:hover { background: var(--surface-mid); }
.cust-mobile { display: block; }
@media (min-width: 1024px) {
    .cust-header { display: grid; }
    .cust-row { display: grid; grid-template-columns: 48px 1.5fr 1.4fr 1fr 80px 120px 120px; gap: 16px; align-items: center; }
    .cust-mobile { display: none; }
}
</style>
