<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Minus, Plus, Trash2, ShoppingBag, MessageCircle } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppInput from '@/components/base/AppInput.vue'
import { useCartStore } from '@/stores/cart'
import { useStorefrontStore } from '@/stores/storefront'
import { useWhatsappCheckout } from '@/composables/useWhatsappCheckout'
import { useToast } from '@/composables/useToast'

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

// Optional customer fields shown at the bottom of the cart
const customerName = ref('')
const customerNote = ref('')

function handleUpdateQty(variantId: number, delta: number): void {
    const item = cart.items.find((i) => i.variantId === variantId)
    if (!item) return
    cart.updateQty(variantId, item.quantity + delta)
}

function handleCheckout(): void {
    if (!storefront.tenant) {
        toast.error('Informacion del negocio no disponible')
        return
    }

    const opened = checkout(cart.items, storefront.tenant, {
        name: customerName.value,
        note: customerNote.value,
    })

    if (opened) {
        toast.success('Pedido enviado por WhatsApp')
        // UX decision: do NOT auto-clear the cart after checkout.
        // The customer may want to re-send the message (network issues, closed
        // WhatsApp by accident, needs to forward to a different number, etc.).
        // A visible "Vaciar carrito" option below the button lets them clear
        // intentionally if needed.
    }
}

function handleClear(): void {
    cart.clear()
    customerName.value = ''
    customerNote.value = ''
}

function optionLabel(options: Record<string, string>): string {
    return Object.entries(options)
        .map(([key, value]) => `${key}: ${value}`)
        .join(' · ')
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Tu carrito"
        :subtitle="cart.isEmpty ? '' : `${cart.count} ${cart.count === 1 ? 'producto' : 'productos'}`"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <!-- Empty state -->
        <div
            v-if="cart.isEmpty"
            class="flex flex-col items-center justify-center gap-4 py-16 text-center"
            data-testid="cart-empty-state"
        >
            <div
                class="w-16 h-16 rounded-full flex items-center justify-center"
                style="background: var(--surface-high)"
            >
                <ShoppingBag :size="28" style="color: var(--on-surface-variant)" />
            </div>
            <div>
                <p class="font-medium text-on-surface text-sm">Tu carrito esta vacio</p>
                <p class="text-xs text-on-surface-variant mt-1">
                    Agrega productos para comenzar tu pedido
                </p>
            </div>
            <RouterLink
                :to="{ name: 'storefront.products' }"
                class="btn btn-primary text-sm"
                @click="emit('update:modelValue', false)"
            >
                Ver catalogo
            </RouterLink>
        </div>

        <!-- Item list -->
        <template v-else>
            <ul class="space-y-4" data-testid="cart-items">
                <li
                    v-for="item in cart.items"
                    :key="item.variantId"
                    class="flex gap-3"
                    :data-testid="`cart-item-${item.variantId}`"
                >
                    <!-- Thumbnail -->
                    <div
                        class="w-16 h-16 shrink-0 rounded-xl overflow-hidden"
                        style="background: var(--surface-high)"
                    >
                        <img
                            v-if="item.imageUrl"
                            :src="item.imageUrl"
                            :alt="item.productName"
                            class="w-full h-full object-cover"
                        />
                        <div
                            v-else
                            class="w-full h-full flex items-center justify-center"
                        >
                            <ShoppingBag :size="20" style="color: var(--on-surface-variant)" />
                        </div>
                    </div>

                    <!-- Details + controls -->
                    <div class="flex-1 min-w-0 flex flex-col gap-1.5">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-on-surface truncate leading-tight">
                                    {{ item.productName }}
                                </p>
                                <p
                                    v-if="Object.keys(item.variantOptions).length > 0"
                                    class="text-xs text-on-surface-variant mt-0.5 truncate"
                                    :data-testid="`cart-item-options-${item.variantId}`"
                                >
                                    {{ optionLabel(item.variantOptions) }}
                                </p>
                            </div>
                            <!-- Remove button -->
                            <button
                                type="button"
                                class="btn-icon shrink-0 -mt-0.5"
                                :aria-label="`Eliminar ${item.productName}`"
                                @click="cart.removeItem(item.variantId)"
                            >
                                <Trash2 :size="14" style="color: var(--error)" />
                            </button>
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <!-- Quantity stepper -->
                            <div
                                class="flex items-center rounded-full overflow-hidden gap-0.5"
                                style="background: var(--surface-high)"
                            >
                                <button
                                    type="button"
                                    class="btn-icon w-7 h-7 rounded-full text-xs"
                                    :aria-label="`Reducir cantidad de ${item.productName}`"
                                    :disabled="item.quantity <= 1"
                                    :data-testid="`cart-item-decrement-${item.variantId}`"
                                    @click="handleUpdateQty(item.variantId, -1)"
                                >
                                    <Minus :size="12" />
                                </button>
                                <span
                                    class="w-6 text-center text-xs font-bold text-on-surface select-none"
                                    :data-testid="`cart-item-qty-${item.variantId}`"
                                >
                                    {{ item.quantity }}
                                </span>
                                <button
                                    type="button"
                                    class="btn-icon w-7 h-7 rounded-full text-xs"
                                    :aria-label="`Aumentar cantidad de ${item.productName}`"
                                    :data-testid="`cart-item-increment-${item.variantId}`"
                                    @click="handleUpdateQty(item.variantId, 1)"
                                >
                                    <Plus :size="12" />
                                </button>
                            </div>

                            <!-- Line subtotal -->
                            <p
                                class="text-sm font-bold text-on-surface"
                                :data-testid="`cart-item-subtotal-${item.variantId}`"
                            >
                                {{ storefront.formatPrice(item.priceCents * item.quantity) }}
                            </p>
                        </div>
                    </div>
                </li>
            </ul>

            <!-- Divider via background shift (no 1px border rule) -->
            <div
                class="mt-5 -mx-5 px-5 py-4 space-y-3"
                style="background: var(--surface-mid)"
            >
                <!-- Subtotal row -->
                <div class="flex items-center justify-between">
                    <p class="label-gilt">Subtotal</p>
                    <p
                        class="text-sm font-bold text-on-surface"
                        data-testid="cart-subtotal"
                    >
                        {{ storefront.formatPrice(cart.subtotalCents) }}
                    </p>
                </div>
            </div>

            <!-- Optional customer fields — no required, helps the tenant identify the order -->
            <div class="mt-4 space-y-3">
                <p class="label-gilt">Tu informacion (opcional)</p>
                <AppInput
                    v-model="customerName"
                    label="Tu nombre"
                    placeholder="Ej: Maria Lopez"
                    data-testid="checkout-customer-name"
                />
                <AppInput
                    v-model="customerNote"
                    label="Nota"
                    placeholder="Ej: Para entregar el viernes"
                    data-testid="checkout-customer-note"
                />
            </div>
        </template>

        <!-- Sticky footer with checkout CTA -->
        <template v-if="!cart.isEmpty" #footer>
            <div class="space-y-2">
                <button
                    type="button"
                    class="btn btn-primary w-full flex items-center justify-center gap-2"
                    data-testid="checkout-whatsapp-btn"
                    @click="handleCheckout"
                >
                    <MessageCircle :size="16" />
                    Finalizar pedido por WhatsApp
                </button>

                <!-- Secondary: clear cart -->
                <button
                    type="button"
                    class="btn w-full text-sm text-on-surface-variant hover:text-error transition-colors text-center py-1"
                    data-testid="clear-cart-btn"
                    @click="handleClear"
                >
                    Vaciar carrito
                </button>
            </div>
        </template>
    </AppSlideover>
</template>
