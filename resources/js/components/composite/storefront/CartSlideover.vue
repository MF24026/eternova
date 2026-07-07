<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Minus, Plus, Trash2, ShoppingBag, MessageCircle, X } from 'lucide-vue-next'
import { useCartStore } from '@/stores/cart'
import { useStorefrontStore } from '@/stores/storefront'
import { useWhatsappCheckout } from '@/composables/useWhatsappCheckout'
import { useToast } from '@/composables/useToast'
import Surrogate from '@/components/base/Surrogate.vue'

interface Props {
    modelValue: boolean
}

defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
}>()

const cart = useCartStore()
const storefront = useStorefrontStore()
const toast = useToast()
const { checkout } = useWhatsappCheckout()

const customerNote = ref('')

function close(): void {
    emit('update:modelValue', false)
}

function handleUpdateQty(variantId: number, delta: number): void {
    const item = cart.items.find((i) => i.variantId === variantId)
    if (!item) return
    cart.updateQty(variantId, item.quantity + delta)
}

function handleCheckout(): void {
    if (!storefront.tenant) {
        toast.error('Información del negocio no disponible')
        return
    }

    const opened = checkout(cart.items, storefront.tenant, {
        name: '',
        note: customerNote.value,
    })

    if (opened) {
        toast.success('Pedido enviado por WhatsApp')
    }
}

function handleClear(): void {
    cart.clear()
    customerNote.value = ''
}

function optionLabel(options: Record<string, string>): string {
    return Object.entries(options)
        .map(([key, value]) => `${key}: ${value}`)
        .join(' · ')
}

// Subtotal and total calculations (mirroring prototype — no actual shipping API)
function subtotalCents(): number {
    return cart.subtotalCents
}
</script>

<template>
    <!-- Scrim -->
    <div
        class="slideover-scrim"
        :class="{ open: modelValue }"
        @click="close"
    />

    <!-- Panel -->
    <aside
        class="slideover"
        :class="{ open: modelValue }"
        role="dialog"
        :aria-label="`Tu canasta — ${cart.count} ${cart.count === 1 ? 'pieza' : 'piezas'}`"
    >
        <!-- Header -->
        <header style="padding: 24px 24px 16px; display: flex; align-items: flex-start; gap: 12px">
            <div class="grow">
                <div class="label-gilt" style="margin-bottom: 6px">
                    {{ cart.count }} {{ cart.count === 1 ? 'pieza' : 'piezas' }}
                </div>
                <h2 class="serif" style="margin: 0; font-size: 28px; line-height: 1.1">Tu canasta</h2>
            </div>
            <button
                type="button"
                class="btn-icon"
                aria-label="Cerrar carrito"
                @click="close"
            >
                <X :size="18" />
            </button>
        </header>

        <!-- Body (scrollable) -->
        <div class="scroll grow" style="padding: 0 24px 24px">

            <!-- Empty state -->
            <div
                v-if="cart.isEmpty"
                class="flex flex-col items-center justify-center gap-4 text-center"
                style="padding: 80px 0"
                data-testid="cart-empty-state"
            >
                <div
                    style="width: 96px; height: 96px; margin: 0 auto 8px; border-radius: 50%; background: var(--surface-low); display: grid; place-items: center"
                >
                    <ShoppingBag :size="32" style="color: var(--on-surface-variant)" />
                </div>
                <p style="color: var(--on-surface-variant)">
                    Tu canasta esta vacia. Comienza por elegir un producto.
                </p>
                <RouterLink
                    :to="{ name: 'storefront.products' }"
                    class="btn btn-primary text-sm"
                    @click="close"
                >
                    Ver catálogo
                </RouterLink>
            </div>

            <!-- Item list -->
            <div
                v-else
                class="stack"
                style="gap: 14px"
                data-testid="cart-items"
            >
                <!-- Cart item row -->
                <div
                    v-for="item in cart.items"
                    :key="item.variantId"
                    class="card flex gap-3.5 items-center"
                    style="background: var(--surface-low); padding: 14px; border-radius: var(--r-lg)"
                    :data-testid="`cart-item-${item.variantId}`"
                >
                    <!-- Thumbnail: real image or surrogate -->
                    <div
                        class="relative shrink-0 overflow-hidden"
                        style="width: 64px; height: 64px; border-radius: var(--r-lg)"
                    >
                        <img
                            v-if="item.imageUrl"
                            :src="item.imageUrl"
                            :alt="item.productName"
                            class="w-full h-full object-cover"
                        />
                        <Surrogate
                            v-else
                            tone="rose"
                            :fill="true"
                        />
                    </div>

                    <!-- Name + price + qty -->
                    <div class="grow min-w-0">
                        <div
                            class="serif"
                            style="font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap"
                        >
                            {{ item.productName }}
                        </div>
                        <div
                            v-if="Object.keys(item.variantOptions).length > 0"
                            class="text-xs truncate mt-0.5"
                            style="color: var(--on-surface-variant)"
                            :data-testid="`cart-item-options-${item.variantId}`"
                        >
                            {{ optionLabel(item.variantOptions) }}
                        </div>
                        <div style="font-size: 13px; color: var(--primary); font-weight: 600; margin-top: 2px">
                            {{ storefront.formatPrice(item.priceCents) }}
                        </div>

                        <!-- Qty stepper (compact) -->
                        <div
                            class="inline-flex items-center gap-0.5 mt-2"
                            style="background: var(--surface-lowest); border-radius: 999px; padding: 2px"
                        >
                            <button
                                type="button"
                                style="width: 24px; height: 24px; display: grid; place-items: center; border-radius: 999px"
                                :aria-label="`Reducir cantidad de ${item.productName}`"
                                :disabled="item.quantity <= 1"
                                :data-testid="`cart-item-decrement-${item.variantId}`"
                                @click="handleUpdateQty(item.variantId, -1)"
                            >
                                <Minus :size="12" />
                            </button>
                            <span
                                style="min-width: 22px; text-align: center; font-size: 13px; font-weight: 600"
                                :data-testid="`cart-item-qty-${item.variantId}`"
                            >
                                {{ item.quantity }}
                            </span>
                            <button
                                type="button"
                                style="width: 24px; height: 24px; display: grid; place-items: center; border-radius: 999px"
                                :aria-label="`Aumentar cantidad de ${item.productName}`"
                                :data-testid="`cart-item-increment-${item.variantId}`"
                                @click="handleUpdateQty(item.variantId, 1)"
                            >
                                <Plus :size="12" />
                            </button>
                        </div>
                    </div>

                    <!-- Remove -->
                    <button
                        type="button"
                        class="btn-icon shrink-0"
                        :aria-label="`Eliminar ${item.productName}`"
                        @click="cart.removeItem(item.variantId)"
                    >
                        <Trash2 :size="16" style="color: var(--error)" />
                    </button>
                </div>

                <!-- Nota para el atelier -->
                <div
                    style="background: var(--surface-mid); border-radius: var(--r-lg); padding: 16px; margin-top: 12px"
                >
                    <div class="label-gilt" style="margin-bottom: 6px">Nota para el atelier</div>
                    <textarea
                        v-model="customerNote"
                        class="field"
                        rows="3"
                        placeholder="Quisiera que la cinta sea color crema…"
                        style="background: var(--surface-lowest); resize: vertical"
                        data-testid="checkout-customer-note"
                    />
                </div>
            </div>
        </div>

        <!-- Footer — only when cart has items -->
        <footer
            v-if="!cart.isEmpty"
            style="padding: 16px 24px 24px; background: var(--surface-low)"
        >
            <div class="stack" style="gap: 16px">
                <!-- Totals -->
                <div class="stack" style="gap: 8px; font-size: 14px">
                    <div class="row" style="justify-content: space-between; color: var(--on-surface-variant)">
                        <span>Subtotal</span>
                        <span
                            class="font-bold"
                            style="color: var(--on-surface)"
                            data-testid="cart-subtotal"
                        >
                            {{ storefront.formatPrice(subtotalCents()) }}
                        </span>
                    </div>
                    <!-- Total row -->
                    <div class="row" style="justify-content: space-between; padding-top: 10px">
                        <span style="font-size: 16px; font-weight: 700">Total</span>
                        <span
                            class="serif"
                            style="color: var(--primary); font-size: 24px"
                        >
                            {{ storefront.formatPrice(subtotalCents()) }}
                        </span>
                    </div>
                </div>

                <!-- WhatsApp CTA -->
                <button
                    type="button"
                    class="btn btn-primary w-full justify-center"
                    style="width: 100%; padding: 16px 22px"
                    data-testid="checkout-whatsapp-btn"
                    @click="handleCheckout"
                >
                    <MessageCircle :size="16" />
                    Confirmar por WhatsApp
                </button>

                <p class="text-center text-xs" style="color: var(--on-surface-variant); margin: 0">
                    Pago contra entrega · transferencia · efectivo
                </p>

                <!-- Clear cart -->
                <button
                    type="button"
                    class="btn w-full text-center text-sm justify-center"
                    style="color: var(--on-surface-variant)"
                    data-testid="clear-cart-btn"
                    @click="handleClear"
                >
                    Vaciar canasta
                </button>
            </div>
        </footer>
    </aside>
</template>
