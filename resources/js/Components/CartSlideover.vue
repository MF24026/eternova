<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Slideover from '@/Components/Slideover.vue';
import Surrogate from '@/Components/Surrogate.vue';
import { ShoppingBag, Minus, Plus, Trash2, MessageCircle } from 'lucide-vue-next';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    cartItems: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['close', 'update-qty', 'remove']);

const subtotal = computed(() =>
    props.cartItems.reduce((s, it) => s + it.price * it.qty, 0)
);

const shipping = computed(() => (subtotal.value > 50 ? 0 : 4.00));

const tax = computed(() => subtotal.value * 0.13);

const total = computed(() => subtotal.value + shipping.value);

const itemCount = computed(() =>
    props.cartItems.reduce((s, it) => s + it.qty, 0)
);

const subtitle = computed(() =>
    `${itemCount.value} ${itemCount.value === 1 ? 'pieza' : 'piezas'}`
);

function buildWhatsAppMessage() {
    const lines = props.cartItems.map(it =>
        `• ${it.qty}x ${it.name} — $${(it.price * it.qty).toFixed(2)}`
    );
    lines.push('');
    lines.push(`Subtotal: $${subtotal.value.toFixed(2)}`);
    lines.push(`Envío: ${shipping.value === 0 ? 'Gratis' : '$' + shipping.value.toFixed(2)}`);
    lines.push(`Total: $${total.value.toFixed(2)}`);
    return encodeURIComponent(
        'Hola! Me gustaría confirmar mi pedido:\n\n' + lines.join('\n')
    );
}

function confirmWhatsApp() {
    const msg = buildWhatsAppMessage();
    window.open(`https://wa.me/50300000000?text=${msg}`, '_blank');
}
</script>

<template>
    <Slideover
        :open="open"
        :subtitle="subtitle"
        title="Tu canasta"
        @close="emit('close')"
    >
        <!-- Empty state -->
        <div v-if="cartItems.length === 0" style="padding: 80px 0; text-align: center; color: var(--on-surface-variant);">
            <div style="width: 96px; height: 96px; margin: 0 auto 24px; border-radius: 50%; background: var(--surface-low); display: grid; place-items: center;">
                <ShoppingBag :size="32"/>
            </div>
            <p style="font-size: 15px; line-height: 1.6; max-width: 260px; margin: 0 auto;">
                Tu canasta está vacía. Comienza por elegir una rosa.
            </p>
            <Link href="/catalog" class="btn btn-tertiary" style="margin-top: 20px; display: inline-flex;" @click="emit('close')">
                Ver catálogo
            </Link>
        </div>

        <!-- Items list -->
        <div v-else style="display: flex; flex-direction: column; gap: 14px;">
            <div
                v-for="item in cartItems"
                :key="item.id"
                style="background: var(--surface-low); padding: 14px; display: flex; gap: 14px; align-items: center; border-radius: var(--r-lg);"
            >
                <!-- Product image -->
                <div style="width: 64px; height: 64px; flex-shrink: 0; border-radius: var(--r-lg); overflow: hidden;">
                    <Surrogate :kind="item.kind" :tone="item.tone" style="width: 100%; height: 100%;"/>
                </div>

                <!-- Info -->
                <div style="flex: 1; min-width: 0;">
                    <div class="serif" style="font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                        {{ item.name }}
                    </div>
                    <div style="font-size: 13px; color: var(--primary); font-weight: 600; margin-bottom: 6px;">
                        ${{ item.price.toFixed(2) }}
                    </div>
                    <!-- Quantity selector -->
                    <div style="display: inline-flex; align-items: center; background: var(--surface-lowest); border-radius: 99px; padding: 2px; gap: 2px;">
                        <button
                            style="width: 24px; height: 24px; display: grid; place-items: center; border-radius: 99px; color: var(--on-surface-variant);"
                            aria-label="Reducir"
                            @click="emit('update-qty', item.id, Math.max(1, item.qty - 1))"
                        >
                            <Minus :size="12"/>
                        </button>
                        <span style="min-width: 22px; text-align: center; font-size: 13px; font-weight: 600;">{{ item.qty }}</span>
                        <button
                            style="width: 24px; height: 24px; display: grid; place-items: center; border-radius: 99px; color: var(--on-surface-variant);"
                            aria-label="Aumentar"
                            @click="emit('update-qty', item.id, item.qty + 1)"
                        >
                            <Plus :size="12"/>
                        </button>
                    </div>
                </div>

                <!-- Remove button -->
                <button
                    class="btn-icon"
                    aria-label="Eliminar"
                    style="color: var(--on-surface-variant); flex-shrink: 0;"
                    @click="emit('remove', item.id)"
                >
                    <Trash2 :size="16"/>
                </button>
            </div>

            <!-- Note to atelier -->
            <div style="background: var(--surface-mid); border-radius: var(--r-lg); padding: 16px; margin-top: 8px;">
                <div class="label-gilt" style="margin-bottom: 6px;">Nota para el atelier</div>
                <textarea
                    class="field"
                    rows="3"
                    placeholder="Quisiera que la cinta sea color crema…"
                    style="background: var(--surface-lowest); resize: vertical;"
                />
            </div>
        </div>

        <!-- Footer slot -->
        <template #footer>
            <div v-if="cartItems.length > 0" style="display: flex; flex-direction: column; gap: 16px;">
                <!-- Totals -->
                <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px;">
                    <div style="display: flex; justify-content: space-between; color: var(--on-surface-variant);">
                        <span>Subtotal</span>
                        <span>${{ subtotal.toFixed(2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--on-surface-variant);">
                        <span>IVA (13%)</span>
                        <span>${{ tax.toFixed(2) }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: var(--on-surface-variant);">
                        <span>Envío</span>
                        <span>
                            <span v-if="shipping === 0" class="bloom bloom-success" style="font-size: 12px;">Gratis</span>
                            <span v-else>${{ shipping.toFixed(2) }}</span>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-top: 10px; font-size: 18px; font-weight: 700; border-top: 0;">
                        <span>Total</span>
                        <span class="serif" style="color: var(--primary); font-size: 24px;">${{ total.toFixed(2) }}</span>
                    </div>
                </div>

                <p v-if="subtotal < 50" style="font-size: 12px; color: var(--on-surface-variant); margin: 0;">
                    Agrega ${{ (50 - subtotal).toFixed(2) }} más para envío gratis.
                </p>

                <!-- WhatsApp CTA -->
                <button
                    class="btn btn-primary"
                    style="width: 100%; justify-content: center; padding: 16px 22px;"
                    @click="confirmWhatsApp"
                >
                    <MessageCircle :size="16"/>
                    Confirmar por WhatsApp
                </button>

                <p style="font-size: 12px; color: var(--on-surface-variant); text-align: center; margin: 0;">
                    Pago contra entrega · transferencia · efectivo
                </p>
            </div>
        </template>
    </Slideover>
</template>
