<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Plus, FileText, ArrowRight } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'

onMounted(() => { document.title = 'Cotizaciones — Eternova' })

type QuoteStatus = 'borrador' | 'enviada' | 'aceptada' | 'rechazada'
interface Quote { id: string; customer: string; date: string; validUntil: string; total: number; status: QuoteStatus; items: number }

const statusInfo: Record<QuoteStatus, { label: string; variant: 'neutral' | 'info' | 'success' | 'error' }> = {
    borrador: { label: 'Borrador', variant: 'neutral' },
    enviada: { label: 'Enviada', variant: 'info' },
    aceptada: { label: 'Aceptada', variant: 'success' },
    rechazada: { label: 'Rechazada', variant: 'error' },
}

const quotes = ref<Quote[]>([
    { id: 'Q-0042', customer: 'Hotel Sheraton', date: 'Hoy', total: 1240.00, status: 'enviada', items: 8, validUntil: '22 mayo' },
    { id: 'Q-0041', customer: 'Boda Sofia R.', date: 'Ayer', total: 845.00, status: 'aceptada', items: 12, validUntil: '20 mayo' },
    { id: 'Q-0040', customer: 'Empresa Davivienda', date: '12 mayo', total: 2100.00, status: 'borrador', items: 25, validUntil: '30 mayo' },
    { id: 'Q-0039', customer: 'Lucia Perez', date: '10 mayo', total: 220.00, status: 'rechazada', items: 3, validUntil: '17 mayo' },
    { id: 'Q-0038', customer: 'Cafe El Olivar', date: '8 mayo', total: 580.00, status: 'aceptada', items: 14, validUntil: '15 mayo' },
    { id: 'Q-0037', customer: 'Boutique Rosal', date: '5 mayo', total: 960.00, status: 'enviada', items: 10, validUntil: '19 mayo' },
])

const filterStatus = ref<'all' | QuoteStatus>('all')
const selected = ref<Quote | null>(null)

const shown = computed(() =>
    filterStatus.value === 'all' ? quotes.value : quotes.value.filter(q => q.status === filterStatus.value)
)

const mockLineItems = [
    { name: 'Rosa Eterna Carmesi', price: 65.00, qty: 2 },
    { name: 'Bouquet Aurora', price: 89.00, qty: 1 },
    { name: 'Peluche Olivia', price: 32.00, qty: 3 },
]
</script>

<template>
    <div class="h-[calc(100vh-120px)] overflow-hidden">
        <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
            <div class="flex flex-wrap gap-3 mb-4">
                <div class="scroll">
                    <div class="tabs inline-flex">
                        <button :class="['tab', { active: filterStatus === 'all' }]" @click="filterStatus = 'all'">Todas ({{ quotes.length }})</button>
                        <button v-for="[k, v] in Object.entries(statusInfo)" :key="k" :class="['tab', { active: filterStatus === k }]" @click="filterStatus = k as QuoteStatus">{{ v.label }}</button>
                    </div>
                </div>
                <div class="grow" />
                <AppButton :icon="Plus" @click="selected = { id: 'Nueva', customer: '', date: 'Hoy', validUntil: '', total: 0, status: 'borrador', items: 0 }">Nueva</AppButton>
            </div>

            <div class="scroll flex-1 min-h-0">
                <div class="flex flex-col gap-1.5">
                    <button
                        v-for="q in shown"
                        :key="q.id"
                        class="card-hover rounded-xl p-3.5 text-left w-full block"
                        style="background: var(--surface-low)"
                        @click="selected = q"
                    >
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background: var(--primary-container); color: var(--primary-dim)">
                                <FileText :size="16" />
                            </div>
                            <div class="grow min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="serif text-base text-on-surface">{{ q.customer }}</span>
                                    <AppBadge :variant="statusInfo[q.status].variant" size="sm">{{ statusInfo[q.status].label }}</AppBadge>
                                </div>
                                <p class="text-xs text-on-surface-variant">{{ q.id }} · {{ q.items }} items · Valida hasta {{ q.validUntil }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-bold text-primary">${{ q.total.toFixed(2) }}</p>
                                <p class="text-xs text-on-surface-variant">{{ q.date }}</p>
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Quote detail slideover -->
    <AppSlideover
        :model-value="!!selected"
        :title="selected?.customer || 'Nueva cotizacion'"
        :subtitle="`Cotizacion ${selected?.id || ''}`"
        @update:model-value="selected = null"
    >
        <template v-if="selected">
            <div class="flex flex-col gap-5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl p-4" style="background: var(--surface-low)">
                        <p class="label-gilt mb-1">Total</p>
                        <p class="serif text-3xl text-primary">${{ selected.total.toFixed(2) }}</p>
                    </div>
                    <div class="rounded-xl p-4" style="background: var(--surface-low)">
                        <p class="label-gilt mb-1">Estado</p>
                        <AppBadge :variant="statusInfo[selected.status].variant">{{ statusInfo[selected.status].label }}</AppBadge>
                    </div>
                </div>

                <div>
                    <p class="label-gilt mb-2.5">Lineas</p>
                    <div class="flex flex-col gap-2">
                        <div v-for="item in mockLineItems.slice(0, Math.max(1, selected.items))" :key="item.name" class="flex items-center gap-3 p-2.5 rounded-xl" style="background: var(--surface-low)">
                            <div class="w-10 h-10 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                            <div class="grow">
                                <p class="text-sm font-semibold">{{ item.name }}</p>
                                <p class="text-xs text-on-surface-variant">{{ item.qty }} × ${{ item.price.toFixed(2) }}</p>
                            </div>
                            <span class="text-sm font-bold text-primary">${{ (item.qty * item.price).toFixed(2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <template #footer>
            <div class="flex gap-2.5">
                <AppButton variant="secondary" class="flex-1 justify-center" @click="selected = null">Cerrar</AppButton>
                <AppButton class="flex-1 justify-center">
                    Enviar PDF <ArrowRight :size="14" class="ml-1" />
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
