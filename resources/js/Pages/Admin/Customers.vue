<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Slideover from '@/Components/Slideover.vue'
import { Search, Plus, Phone, MapPin, MessageCircle } from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

interface Customer {
    id: number
    name: string
    initials: string
    phone: string
    area: string
    orders: number
    total: number
    last: string
    avgTicket: number
    since: string
}

const customers = ref<Customer[]>([
    { id: 1, name: 'Ana López', initials: 'AL', phone: '+503 7892-1234', area: 'Col. Escalón', orders: 12, total: 1240.00, last: 'Hoy', avgTicket: 103.33, since: 'marzo 2024' },
    { id: 2, name: 'María González', initials: 'MG', phone: '+503 7654-3210', area: 'Santa Tecla', orders: 8, total: 890.00, last: 'Hoy', avgTicket: 111.25, since: 'enero 2024' },
    { id: 3, name: 'Sofía Ramírez', initials: 'SR', phone: '+503 7123-4567', area: 'Antiguo Cuscatlán', orders: 6, total: 2100.00, last: 'Ayer', avgTicket: 350.00, since: 'junio 2023' },
    { id: 4, name: 'Lucía Pérez', initials: 'LP', phone: '+503 7890-1122', area: 'San Benito', orders: 4, total: 340.00, last: '10 mayo', avgTicket: 85.00, since: 'octubre 2024' },
    { id: 5, name: 'Valeria Castro', initials: 'VC', phone: '+503 7445-9988', area: 'Lomas Verdes', orders: 9, total: 1580.00, last: '8 mayo', avgTicket: 175.56, since: 'abril 2023' },
    { id: 6, name: 'Daniela Martínez', initials: 'DM', phone: '+503 7332-5566', area: 'Col. Médica', orders: 3, total: 180.00, last: '5 mayo', avgTicket: 60.00, since: 'febrero 2025' },
    { id: 7, name: 'Patricia Rojas', initials: 'PR', phone: '+503 7221-4433', area: 'Merliot', orders: 7, total: 680.00, last: '3 mayo', avgTicket: 97.14, since: 'agosto 2023' },
    { id: 8, name: 'Carmen Flores', initials: 'CF', phone: '+503 7998-6655', area: 'San Salvador Centro', orders: 5, total: 420.00, last: '1 mayo', avgTicket: 84.00, since: 'noviembre 2023' },
])

const searchQuery = ref('')
const selected = ref<Customer | null>(null)

const filtered = computed(() => {
    if (!searchQuery.value) return customers.value
    const q = searchQuery.value.toLowerCase()
    return customers.value.filter(c =>
        c.name.toLowerCase().includes(q) || c.phone.includes(q)
    )
})

function openWhatsApp(phone: string) {
    const cleaned = phone.replace(/\D/g, '')
    window.open(`https://wa.me/${cleaned}`, '_blank')
}

const recentOrders = [
    { id: 'CC-0143', date: 'Hoy', total: 89.00, status: 'preparando', statusTone: 'info' },
    { id: 'CC-0128', date: '2 mayo', total: 156.00, status: 'entregado', statusTone: 'success' },
    { id: 'CC-0119', date: '20 abril', total: 245.00, status: 'entregado', statusTone: 'success' },
]
</script>

<template>
    <AdminLayout title="Clientes" breadcrumb="CRM">
        <div style="height: calc(100vh - 120px); overflow: hidden">
            <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
                <!-- Toolbar -->
                <div class="row" style="margin-bottom: 16px; gap: 12px">
                    <div style="position: relative; flex: 1">
                        <input
                            v-model="searchQuery"
                            class="field"
                            placeholder="Buscar por nombre, teléfono…"
                            style="padding-left: 44px"
                        />
                        <Search :size="18" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)"/>
                    </div>
                    <button class="btn btn-primary">
                        <Plus :size="14" style="margin-right: 4px"/> Cliente
                    </button>
                </div>

                <!-- Table header (desktop) -->
                <div class="cust-header">
                    <div v-for="h in ['', 'Cliente', 'Contacto', 'Zona', 'Pedidos', 'Total', 'Último']" :key="h" class="label" style="font-size: 10px">{{ h }}</div>
                </div>

                <div class="scroll stack" style="gap: 6px; flex: 1; min-height: 0">
                    <button
                        v-for="c in filtered"
                        :key="c.id"
                        class="cust-row"
                        @click="selected = c"
                    >
                        <!-- Avatar (desktop) -->
                        <span class="cust-avatar">{{ c.initials }}</span>

                        <!-- Mobile layout -->
                        <div class="cust-mobile">
                            <div class="row" style="justify-content: space-between; margin-bottom: 6px">
                                <span class="serif" style="font-size: 16px">{{ c.name }}</span>
                                <span class="serif" style="color: var(--primary); font-weight: 600">${{ c.total.toFixed(2) }}</span>
                            </div>
                            <div style="font-size: 12px; color: var(--on-surface-variant)">{{ c.phone }} · {{ c.orders }} pedidos</div>
                        </div>

                        <!-- Desktop layout -->
                        <div class="cust-desktop-name">
                            <div class="serif" style="font-size: 15px">{{ c.name }}</div>
                        </div>
                        <div class="cust-desktop-contact" style="font-size: 12px; color: var(--on-surface-variant)">{{ c.phone }}</div>
                        <div class="cust-desktop-area" style="font-size: 12px; color: var(--on-surface-variant)">{{ c.area }}</div>
                        <div class="cust-desktop-orders" style="font-size: 14px; font-weight: 600">{{ c.orders }}</div>
                        <div class="cust-desktop-total" style="color: var(--primary); font-weight: 700; font-size: 14px">${{ c.total.toFixed(2) }}</div>
                        <div class="cust-desktop-last" style="font-size: 12px; color: var(--on-surface-variant)">{{ c.last }}</div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Customer detail slideover -->
        <Slideover
            :open="!!selected"
            :title="selected?.name ?? ''"
            subtitle="Cliente"
            @close="selected = null"
        >
            <template v-if="selected">
                <div class="stack" style="gap: 20px">
                    <!-- Header -->
                    <div class="row" style="gap: 14px; align-items: center">
                        <span style="width: 64px; height: 64px; border-radius: 50%; background: var(--gradient-soft);
                            display: grid; place-items: center; color: var(--primary-dim); font-weight: 700; font-size: 22px; flex-shrink: 0">
                            {{ selected.initials }}
                        </span>
                        <div>
                            <div class="serif" style="font-size: 22px">{{ selected.name }}</div>
                            <div style="font-size: 12px; color: var(--on-surface-variant)">Cliente desde {{ selected.since }}</div>
                        </div>
                    </div>

                    <!-- KPIs -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px">
                        <div class="card" style="background: var(--surface-low); padding: 16px">
                            <div class="label-gilt" style="margin-bottom: 4px">Pedidos</div>
                            <div class="serif" style="font-size: 26px">{{ selected.orders }}</div>
                        </div>
                        <div class="card" style="background: var(--surface-low); padding: 16px">
                            <div class="label-gilt" style="margin-bottom: 4px">Total</div>
                            <div class="serif" style="font-size: 26px; color: var(--primary)">${{ selected.total.toFixed(2) }}</div>
                        </div>
                    </div>

                    <!-- Contact info -->
                    <div class="card" style="background: var(--surface-low); padding: 16px">
                        <div class="label-gilt" style="margin-bottom: 12px">Contacto</div>
                        <div class="stack" style="gap: 8px; font-size: 13px">
                            <div class="row" style="gap: 10px"><Phone :size="14"/> {{ selected.phone }}</div>
                            <div class="row" style="gap: 10px"><MapPin :size="14"/> {{ selected.area }}, San Salvador</div>
                            <div class="row" style="gap: 10px"><MessageCircle :size="14"/> Cumpleaños: 14 abril</div>
                        </div>
                    </div>

                    <!-- Recent orders -->
                    <div>
                        <div class="label-gilt" style="margin-bottom: 10px">Pedidos recientes</div>
                        <div class="stack" style="gap: 6px">
                            <div
                                v-for="o in recentOrders"
                                :key="o.id"
                                class="row"
                                style="gap: 12px; padding: 10px; background: var(--surface-low); border-radius: var(--r-md)"
                            >
                                <div class="grow">
                                    <div style="font-size: 13px; font-weight: 600">{{ o.id }}</div>
                                    <div style="font-size: 11px; color: var(--on-surface-variant)">{{ o.date }}</div>
                                </div>
                                <span :class="`bloom bloom-${o.statusTone}`" style="font-size: 10px">{{ o.status }}</span>
                                <div style="font-weight: 700; font-size: 13px">${{ o.total.toFixed(2) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <template #footer>
                <button
                    class="btn btn-primary"
                    style="width: 100%; justify-content: center"
                    @click="selected && openWhatsApp(selected.phone)"
                >
                    <MessageCircle :size="14" style="margin-right: 6px"/> Escribir por WhatsApp
                </button>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.cust-header {
    display: none;
    grid-template-columns: 48px 1.5fr 1.4fr 1fr 80px 120px 120px;
    gap: 16px;
    padding: 6px 16px;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}

.cust-row {
    background: var(--surface-low);
    border-radius: var(--r-lg);
    padding: 12px 16px;
    text-align: left;
    display: block;
    width: 100%;
    cursor: pointer;
    transition: background 0.2s;
}
.cust-row:hover {
    background: var(--surface-mid);
}

.cust-avatar {
    display: none;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--gradient-soft);
    place-items: center;
    color: var(--primary-dim);
    font-weight: 600;
    font-size: 13px;
    flex-shrink: 0;
}

.cust-mobile { display: block; }
.cust-desktop-name,
.cust-desktop-contact,
.cust-desktop-area,
.cust-desktop-orders,
.cust-desktop-total,
.cust-desktop-last { display: none; }

@media (min-width: 1024px) {
    .cust-header {
        display: grid;
    }
    .cust-row {
        display: grid;
        grid-template-columns: 48px 1.5fr 1.4fr 1fr 80px 120px 120px;
        gap: 16px;
        align-items: center;
    }
    .cust-avatar {
        display: grid;
    }
    .cust-mobile { display: none; }
    .cust-desktop-name,
    .cust-desktop-contact,
    .cust-desktop-area,
    .cust-desktop-orders,
    .cust-desktop-total,
    .cust-desktop-last { display: flex; align-items: center; }
}
</style>
