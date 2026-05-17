<script setup>
import { ref, computed } from 'vue';
import StorefrontLayout from '@/Layouts/StorefrontLayout.vue';
import Surrogate from '@/Components/Surrogate.vue';
import { MessageCircle, ChevronRight, ShieldCheck } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: StorefrontLayout });

// Mock order items (in a real app, these come from cart state/Inertia props)
const ORDER_ITEMS = [
    { id: 'p1', name: 'Rosa Eterna Carmesí', price: 65.00, qty: 2, tone: 'rose', kind: 'rose' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, qty: 1, tone: 'rose', kind: 'peluche' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, qty: 1, tone: 'rose', kind: 'bolso' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, qty: 3, tone: 'cream', kind: 'llavero' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, qty: 1, tone: 'lilac', kind: 'peluche' },
];

// Form state
const form = ref({
    name: '',
    phone: '',
    address: '',
    city: '',
    postalCode: '',
    notes: '',
});

const errors = ref({
    name: '',
    phone: '',
    address: '',
    city: '',
});

const submitting = ref(false);
const submitted = ref(false);
const orderId = ref('');

// Totals
const subtotal = computed(() =>
    ORDER_ITEMS.reduce((s, it) => s + it.price * it.qty, 0)
);

const shipping = computed(() => subtotal.value >= 50 ? 0 : 4.00);

const tax = computed(() => subtotal.value * 0.13);

const total = computed(() => subtotal.value + shipping.value);

// El Salvador phone pattern: +503 XXXX-XXXX
function validatePhone(value) {
    const clean = value.replace(/\s|-/g, '');
    return /^(\+503)?[2678]\d{7}$/.test(clean);
}

function validate() {
    let valid = true;
    errors.value = { name: '', phone: '', address: '', city: '' };

    if (!form.value.name.trim()) {
        errors.value.name = 'El nombre es requerido.';
        valid = false;
    }
    if (!form.value.phone.trim()) {
        errors.value.phone = 'El teléfono es requerido.';
        valid = false;
    } else if (!validatePhone(form.value.phone)) {
        errors.value.phone = 'Ingresa un teléfono válido: +503 XXXX-XXXX';
        valid = false;
    }
    if (!form.value.address.trim()) {
        errors.value.address = 'La dirección es requerida.';
        valid = false;
    }
    if (!form.value.city.trim()) {
        errors.value.city = 'La ciudad es requerida.';
        valid = false;
    }
    return valid;
}

function generateOrderId() {
    const num = String(Math.floor(Math.random() * 9000) + 1000);
    return `CC-2026-${num}`;
}

function buildWhatsAppMessage(oid) {
    const lines = [
        `Orden: ${oid}`,
        `Cliente: ${form.value.name}`,
        `Teléfono: ${form.value.phone}`,
        `Dirección: ${form.value.address}, ${form.value.city}`,
        '',
        'Productos:',
        ...ORDER_ITEMS.map(it => `• ${it.qty}x ${it.name} — $${(it.price * it.qty).toFixed(2)}`),
        '',
        `Subtotal: $${subtotal.value.toFixed(2)}`,
        `IVA (13%): $${tax.value.toFixed(2)}`,
        `Envío: ${shipping.value === 0 ? 'Gratis' : '$' + shipping.value.toFixed(2)}`,
        `Total: $${total.value.toFixed(2)}`,
    ];
    if (form.value.notes) {
        lines.push('', `Notas: ${form.value.notes}`);
    }
    return encodeURIComponent(lines.join('\n'));
}

function handleSubmit() {
    if (!validate()) return;
    submitting.value = true;
    // Simulate async (no backend yet)
    setTimeout(() => {
        const oid = generateOrderId();
        orderId.value = oid;
        submitted.value = true;
        submitting.value = false;
        const msg = buildWhatsAppMessage(oid);
        window.open(`https://wa.me/50300000000?text=${msg}`, '_blank');
    }, 600);
}

function phoneInput(e) {
    // Auto-format SV phone as user types
    let v = e.target.value.replace(/[^\d+]/g, '');
    form.value.phone = v;
}
</script>

<template>
    <div>
        <!-- Header -->
        <section style="padding: 32px 24px 40px; background: var(--gradient-bloom);">
            <nav style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--on-surface-variant); margin-bottom: 20px;">
                <Link href="/" style="color: var(--on-surface-variant); text-decoration: none;">Casa</Link>
                <ChevronRight :size="12"/>
                <Link href="/catalog" style="color: var(--on-surface-variant); text-decoration: none;">Catálogo</Link>
                <ChevronRight :size="12"/>
                <span style="color: var(--on-surface);">Checkout</span>
            </nav>
            <div class="label-gilt" style="margin-bottom: 12px;">Pedido</div>
            <h1 class="serif" style="font-size: clamp(32px, 5vw, 52px); margin: 0; line-height: 1.02;">
                Confirmar tu pedido
            </h1>
        </section>

        <!-- Success state -->
        <div v-if="submitted" style="padding: 60px 24px; text-align: center; max-width: 540px; margin: 0 auto;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: var(--success-container); display: grid; place-items: center; margin: 0 auto 24px;">
                <ShieldCheck :size="36" style="color: var(--success);"/>
            </div>
            <div class="label-gilt" style="margin-bottom: 8px;">Pedido generado</div>
            <h2 class="serif" style="font-size: 36px; margin: 0 0 8px;">{{ orderId }}</h2>
            <p style="color: var(--on-surface-variant); line-height: 1.6; margin-bottom: 24px;">
                Tu pedido ha sido generado. Te hemos redirigido a WhatsApp para que puedas confirmarlo con el atelier.
            </p>
            <span class="label-gilt">Te confirmamos el pedido por WhatsApp en 2-5 minutos</span>
            <div style="margin-top: 32px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <Link href="/" class="btn btn-primary">Volver a la tienda</Link>
                <Link :href="`/track?order=${orderId}`" class="btn btn-tertiary">Rastrear mi pedido</Link>
            </div>
        </div>

        <!-- Checkout form -->
        <div v-else class="checkout-layout">
            <!-- Left: Customer form -->
            <div>
                <div class="card" style="padding: 28px; margin-bottom: 0;">
                    <h2 class="serif" style="font-size: 24px; margin: 0 0 24px;">Datos de entrega</h2>

                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <!-- Name -->
                        <div>
                            <label class="field-label" for="checkout-name">Nombre completo</label>
                            <input
                                id="checkout-name"
                                v-model="form.name"
                                class="field"
                                type="text"
                                placeholder="Ana María López"
                            />
                            <p v-if="errors.name" style="font-size: 12px; color: var(--error); margin-top: 6px;">{{ errors.name }}</p>
                        </div>

                        <!-- Phone -->
                        <div>
                            <label class="field-label" for="checkout-phone">Teléfono (El Salvador)</label>
                            <input
                                id="checkout-phone"
                                v-model="form.phone"
                                class="field"
                                type="tel"
                                placeholder="+503 7000-0000"
                                @input="phoneInput"
                            />
                            <p v-if="errors.phone" style="font-size: 12px; color: var(--error); margin-top: 6px;">{{ errors.phone }}</p>
                            <p v-else style="font-size: 12px; color: var(--on-surface-variant); margin-top: 6px;">Formato: +503 XXXX-XXXX</p>
                        </div>

                        <!-- Address -->
                        <div>
                            <label class="field-label" for="checkout-address">Dirección</label>
                            <input
                                id="checkout-address"
                                v-model="form.address"
                                class="field"
                                type="text"
                                placeholder="Colonia Escalón, Calle Los Andes #12"
                            />
                            <p v-if="errors.address" style="font-size: 12px; color: var(--error); margin-top: 6px;">{{ errors.address }}</p>
                        </div>

                        <!-- City + Postal code row -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div>
                                <label class="field-label" for="checkout-city">Ciudad</label>
                                <input
                                    id="checkout-city"
                                    v-model="form.city"
                                    class="field"
                                    type="text"
                                    placeholder="San Salvador"
                                />
                                <p v-if="errors.city" style="font-size: 12px; color: var(--error); margin-top: 6px;">{{ errors.city }}</p>
                            </div>
                            <div>
                                <label class="field-label" for="checkout-postal">Código postal (opcional)</label>
                                <input
                                    id="checkout-postal"
                                    v-model="form.postalCode"
                                    class="field"
                                    type="text"
                                    placeholder="1101"
                                />
                            </div>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="field-label" for="checkout-notes">Notas adicionales</label>
                            <textarea
                                id="checkout-notes"
                                v-model="form.notes"
                                class="field"
                                rows="3"
                                placeholder="Instrucciones para la entrega, referencias del lugar…"
                                style="resize: vertical;"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Order summary -->
            <div class="order-summary-col">
                <div class="card" style="padding: 28px; position: sticky; top: 80px;">
                    <h2 class="serif" style="font-size: 24px; margin: 0 0 24px;">Resumen</h2>

                    <!-- Items list -->
                    <div style="display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px;">
                        <div
                            v-for="item in ORDER_ITEMS"
                            :key="item.id"
                            style="display: flex; align-items: center; gap: 12px;"
                        >
                            <div style="width: 52px; height: 52px; flex-shrink: 0; border-radius: var(--r-lg); overflow: hidden;">
                                <Surrogate :kind="item.kind" :tone="item.tone" style="width: 100%; height: 100%;"/>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-size: 14px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    {{ item.name }}
                                </div>
                                <div style="font-size: 12px; color: var(--on-surface-variant);">
                                    Cant: {{ item.qty }}
                                </div>
                            </div>
                            <div style="font-size: 14px; font-weight: 600; color: var(--primary); flex-shrink: 0;">
                                ${{ (item.price * item.qty).toFixed(2) }}
                            </div>
                        </div>
                    </div>

                    <!-- Totals -->
                    <div style="display: flex; flex-direction: column; gap: 10px; padding-top: 16px; background: var(--surface-low); border-radius: var(--r-lg); padding: 16px; font-size: 14px;">
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
                                <span v-if="shipping === 0" class="bloom bloom-success" style="font-size: 11px;">Gratis</span>
                                <span v-else>${{ shipping.toFixed(2) }}</span>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding-top: 10px; font-size: 20px; font-weight: 700;">
                            <span>Total</span>
                            <span class="serif" style="color: var(--primary); font-size: 28px;">${{ total.toFixed(2) }}</span>
                        </div>
                    </div>

                    <!-- CTA -->
                    <button
                        class="btn btn-primary"
                        style="width: 100%; justify-content: center; padding: 16px 22px; margin-top: 20px; font-size: 15px;"
                        :disabled="submitting"
                        @click="handleSubmit"
                    >
                        <span v-if="submitting" style="display: inline-flex; align-items: center; gap: 8px;">
                            Enviando…
                        </span>
                        <span v-else style="display: inline-flex; align-items: center; gap: 8px;">
                            <MessageCircle :size="16"/>
                            Confirmar y enviar por WhatsApp
                        </span>
                    </button>

                    <p class="label-gilt" style="text-align: center; margin-top: 12px; display: block;">
                        Te confirmamos el pedido por WhatsApp en 2-5 minutos
                    </p>

                    <p style="font-size: 12px; color: var(--on-surface-variant); text-align: center; margin-top: 8px;">
                        Pago contra entrega · transferencia · efectivo
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.checkout-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
    padding: 32px 24px 80px;
}

.order-summary-col {
    /* stacks below on mobile, sticky on desktop */
}

@media (min-width: 768px) {
    .checkout-layout {
        grid-template-columns: 1.2fr 1fr;
        gap: 40px;
        padding: 40px 80px 96px;
    }

    section {
        padding-left: 80px !important;
        padding-right: 80px !important;
    }
}
</style>
