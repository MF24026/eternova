<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import StorefrontLayout from '@/Layouts/StorefrontLayout.vue';
import Surrogate from '@/Components/Surrogate.vue';
import { Search, Check, MessageCircle, ChevronRight, Package } from 'lucide-vue-next';

defineOptions({ layout: StorefrontLayout });

const props = defineProps({
    order: { type: String, default: null },
});

const MOCK_ORDERS = {
    'CC-2026-0042': {
        id: 'CC-2026-0042',
        date: '14 mayo 2026',
        customer: 'Ana M.',
        total: 166.00,
        delivery: '15 mayo 2026',
        location: 'San Salvador',
        currentStage: 1,
        items: [
            { id: 'p1', name: 'Rosa Eterna Carmesí', price: 65.00, qty: 2, tone: 'rose', kind: 'rose' },
            { id: 'p3', name: 'Peluche Olivia', price: 32.00, qty: 1, tone: 'rose', kind: 'peluche' },
        ],
    },
    'CC-2026-0142': {
        id: 'CC-2026-0142',
        date: '13 mayo 2026',
        customer: 'María F.',
        total: 214.00,
        delivery: '15 mayo 2026',
        location: 'Antiguo Cuscatlán',
        currentStage: 2,
        items: [
            { id: 'p2', name: 'Bouquet Aurora', price: 89.00, qty: 1, tone: 'lilac', kind: 'rose' },
            { id: 'p4', name: 'Cartera Petalia', price: 78.00, qty: 1, tone: 'rose', kind: 'bolso' },
            { id: 'p5', name: 'Llavero Camelia', price: 14.00, qty: 2, tone: 'cream', kind: 'llavero' },
            { id: 'p7', name: 'Peluche Lavanda', price: 36.00, qty: 1, tone: 'lilac', kind: 'peluche' },
        ],
    },
};

const STAGES = [
    { id: 'received', label: 'Pedido recibido', times: { '0': '10:24 AM', '1': '10:24 AM', '2': '09:15 AM', '3': '08:00 AM' } },
    { id: 'prep', label: 'Preparando en el atelier', times: { '1': '11:50 AM', '2': '10:30 AM', '3': '09:45 AM' } },
    { id: 'ready', label: 'Listo para envío', times: { '2': '02:00 PM', '3': '11:00 AM' } },
    { id: 'delivered', label: 'Entregado', times: { '3': '03:30 PM' } },
];

// State
const searchInput = ref(props.order ?? '');
const lookedUpOrder = ref(props.order ?? null);
const notFound = ref(false);

const foundOrder = computed(() => {
    if (!lookedUpOrder.value) return null;
    return MOCK_ORDERS[lookedUpOrder.value] ?? null;
});

function getStageTime(stageIndex, currentStage) {
    if (stageIndex > currentStage) return '—';
    return STAGES[stageIndex].times[String(currentStage)] ?? '—';
}

function doSearch() {
    const val = searchInput.value.trim().toUpperCase();
    if (!val) return;
    lookedUpOrder.value = val;
    notFound.value = !MOCK_ORDERS[val];
}

function buildWhatsAppUrl(orderId) {
    const msg = encodeURIComponent(`Hola! Quiero consultar sobre mi pedido ${orderId}.`);
    return `https://wa.me/50300000000?text=${msg}`;
}
</script>

<template>
    <div>
        <!-- Hero: search bar -->
        <section style="padding: 40px 24px 56px; background: var(--gradient-bloom);">
            <div class="label-gilt" style="margin-bottom: 12px;">Seguimiento</div>
            <h1 class="serif" style="font-size: clamp(32px, 6vw, 52px); margin: 0 0 8px;">
                ¿Dónde está tu pedido?
            </h1>
            <p style="color: var(--on-surface-variant); margin-bottom: 32px; font-size: 15px;">
                Ingresa tu número de orden para ver el estado en tiempo real.
            </p>

            <form
                style="display: flex; gap: 12px; flex-wrap: wrap; max-width: 560px;"
                @submit.prevent="doSearch"
            >
                <input
                    v-model="searchInput"
                    class="field"
                    type="text"
                    placeholder="CC-2026-0042"
                    style="flex: 1; min-width: 200px;"
                    aria-label="Número de orden"
                />
                <button class="btn btn-primary" type="submit" style="display: flex; align-items: center; gap: 8px;">
                    <Search :size="16"/>
                    Rastrear
                </button>
            </form>

            <p style="font-size: 12px; color: var(--on-surface-variant); margin-top: 12px;">
                Prueba: CC-2026-0042 o CC-2026-0142
            </p>
        </section>

        <!-- Not found -->
        <div
            v-if="lookedUpOrder && notFound"
            style="padding: 60px 24px; text-align: center; max-width: 480px; margin: 0 auto;"
        >
            <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--surface-low); display: grid; place-items: center; margin: 0 auto 20px;">
                <Package :size="28" style="color: var(--on-surface-variant);"/>
            </div>
            <h2 class="serif" style="font-size: 28px; margin: 0 0 12px;">Orden no encontrada</h2>
            <p style="color: var(--on-surface-variant); font-size: 15px; line-height: 1.6; margin-bottom: 24px;">
                No encontramos la orden <strong>{{ lookedUpOrder }}</strong>. Verifica el número e intenta nuevamente.
            </p>
            <a
                :href="buildWhatsAppUrl(lookedUpOrder)"
                target="_blank"
                rel="noopener"
                class="btn btn-tertiary"
                style="display: inline-flex; align-items: center; gap: 8px;"
            >
                <MessageCircle :size="16"/>
                Contactar al atelier
            </a>
        </div>

        <!-- Order found -->
        <div v-else-if="foundOrder" class="tracking-layout">
            <!-- Order header card -->
            <div class="card" style="grid-column: 1 / -1; padding: 24px; display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">
                <div style="flex: 1; min-width: 200px;">
                    <div class="label-gilt" style="margin-bottom: 8px;">Número de orden</div>
                    <div class="serif" style="font-size: 28px; margin-bottom: 4px;">{{ foundOrder.id }}</div>
                    <p style="font-size: 14px; color: var(--on-surface-variant); margin: 0;">
                        Para {{ foundOrder.customer }} · Entrega {{ foundOrder.delivery }} · {{ foundOrder.location }}
                    </p>
                </div>
                <div>
                    <div class="label-gilt" style="margin-bottom: 4px;">Total</div>
                    <div class="serif" style="font-size: 28px; color: var(--primary);">${{ foundOrder.total.toFixed(2) }}</div>
                </div>
            </div>

            <!-- Timeline -->
            <div>
                <div class="label-gilt" style="margin-bottom: 16px;">Estado del pedido</div>
                <div style="display: flex; flex-direction: column; gap: 0;">
                    <div
                        v-for="(stage, i) in STAGES"
                        :key="stage.id"
                        style="display: flex; align-items: flex-start; gap: 16px; position: relative; padding-bottom: 24px;"
                    >
                        <!-- Vertical connector line -->
                        <div
                            v-if="i < STAGES.length - 1"
                            style="position: absolute; left: 15px; top: 32px; bottom: 0; width: 2px;"
                            :style="{ background: i < foundOrder.currentStage ? 'var(--primary)' : 'var(--surface-high)' }"
                        />

                        <!-- Step indicator -->
                        <div
                            :style="{
                                width: '32px',
                                height: '32px',
                                borderRadius: '50%',
                                background: i <= foundOrder.currentStage ? 'var(--primary)' : 'var(--surface-mid)',
                                color: i <= foundOrder.currentStage ? 'var(--on-primary)' : 'var(--on-surface-variant)',
                                display: 'grid',
                                placeItems: 'center',
                                flexShrink: '0',
                                zIndex: '1',
                                position: 'relative',
                            }"
                        >
                            <Check v-if="i < foundOrder.currentStage" :size="16"/>
                            <span v-else style="font-size: 13px; font-weight: 700;">{{ i + 1 }}</span>
                        </div>

                        <!-- Step text -->
                        <div style="flex: 1; padding-top: 4px;">
                            <div
                                :style="{
                                    fontWeight: i === foundOrder.currentStage ? '700' : '500',
                                    color: i > foundOrder.currentStage ? 'var(--on-surface-variant)' : 'var(--on-surface)',
                                    fontSize: '15px',
                                    marginBottom: '4px',
                                }"
                            >
                                {{ stage.label }}
                            </div>
                            <div style="font-size: 12px; color: var(--on-surface-variant);">
                                {{ getStageTime(i, foundOrder.currentStage) }}
                            </div>
                        </div>

                        <!-- Active badge -->
                        <span
                            v-if="i === foundOrder.currentStage"
                            class="bloom bloom-primary"
                            style="flex-shrink: 0; margin-top: 2px;"
                        >
                            En curso
                        </span>
                    </div>
                </div>
            </div>

            <!-- Order summary card -->
            <div class="card" style="background: var(--surface-low);">
                <div class="label-gilt" style="margin-bottom: 12px;">Resumen del pedido</div>

                <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 20px;">
                    <div
                        v-for="item in foundOrder.items"
                        :key="item.id"
                        style="display: flex; align-items: center; gap: 12px;"
                    >
                        <div style="width: 48px; height: 48px; flex-shrink: 0; border-radius: var(--r-lg); overflow: hidden;">
                            <Surrogate :kind="item.kind" :tone="item.tone" style="width: 100%; height: 100%;"/>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-size: 14px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                {{ item.qty }}x {{ item.name }}
                            </div>
                        </div>
                        <div style="font-size: 14px; font-weight: 600; color: var(--primary); flex-shrink: 0;">
                            ${{ (item.price * item.qty).toFixed(2) }}
                        </div>
                    </div>
                </div>

                <div style="border-top: 0; padding-top: 0;">
                    <div style="display: flex; justify-content: space-between; font-size: 18px; font-weight: 700; padding-top: 16px; background: var(--surface-high); border-radius: var(--r-lg); padding: 12px 16px;">
                        <span>Total</span>
                        <span class="serif" style="color: var(--primary); font-size: 22px;">${{ foundOrder.total.toFixed(2) }}</span>
                    </div>
                </div>

                <a
                    :href="buildWhatsAppUrl(foundOrder.id)"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-tertiary"
                    style="width: 100%; justify-content: center; margin-top: 20px; display: flex; align-items: center; gap: 8px;"
                >
                    <MessageCircle :size="16"/>
                    Contactar al atelier
                </a>
            </div>
        </div>

        <!-- Initial empty state (no search yet) -->
        <div
            v-else-if="!lookedUpOrder"
            style="padding: 60px 24px; text-align: center; max-width: 480px; margin: 0 auto;"
        >
            <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--surface-low); display: grid; place-items: center; margin: 0 auto 20px;">
                <Search :size="28" style="color: var(--on-surface-variant);"/>
            </div>
            <p style="color: var(--on-surface-variant); font-size: 15px; line-height: 1.6;">
                Ingresa tu número de orden arriba para ver el estado de tu pedido.
            </p>
        </div>
    </div>
</template>

<style scoped>
.tracking-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
    padding: 32px 24px 80px;
}

@media (min-width: 768px) {
    section {
        padding-left: 80px !important;
        padding-right: 80px !important;
    }

    .tracking-layout {
        grid-template-columns: 1.4fr 1fr;
        gap: 40px;
        padding: 40px 80px 96px;
        align-items: start;
    }
}
</style>
