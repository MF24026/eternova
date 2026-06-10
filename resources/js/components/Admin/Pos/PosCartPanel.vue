<script setup lang="ts">
import { computed } from 'vue'
import { User } from 'lucide-vue-next'
import PosCartLine from './PosCartLine.vue'
import PosPaymentSelector from './PosPaymentSelector.vue'
import type { PosCartLine as CartLine, PosPaymentMethod } from '@/types/domain/POS'

interface Props {
    lines: CartLine[]
    paymentMethod: PosPaymentMethod
    subtotalCents: number
    taxCents: number
    totalCents: number
    isSubmitting: boolean
    formatCents: (cents: number) => string
}

const props = defineProps<Props>()
const emit = defineEmits<{
    increment: [variantId: number]
    decrement: [variantId: number]
    removeLine: [variantId: number]
    'update:paymentMethod': [method: PosPaymentMethod]
    checkout: []
}>()

const isEmpty = computed(() => props.lines.length === 0)

const cobrarLabel = computed(() =>
    props.isSubmitting
        ? 'Procesando...'
        : `Cobrar ${props.formatCents(props.totalCents)}`,
)
</script>

<template>
    <div
        class="card"
        style="
            padding: 24px;
            display: flex;
            flex-direction: column;
            min-height: 0;
            background: var(--surface-low);
            flex: 1;
            max-width: 380px;
        "
    >
        <!-- Panel header -->
        <div class="flex justify-between items-start mb-4">
            <div>
                <p class="label-gilt">Venta en curso</p>
                <p class="serif text-xl text-on-surface">Nueva venta</p>
            </div>
            <button
                class="btn-icon"
                aria-label="Seleccionar cliente"
            >
                <User :size="18" aria-hidden="true" />
            </button>
        </div>

        <!-- Cart lines — scrollable -->
        <div class="scroll flex-1 min-h-0 -mx-2 px-2">
            <!-- Empty state -->
            <div
                v-if="isEmpty"
                class="py-10 text-center text-sm text-on-surface-variant"
                aria-live="polite"
                data-testid="pos-empty-cart"
            >
                Toca un producto para empezar.
            </div>

            <!-- Lines list -->
            <div v-else aria-label="Productos en el carrito">
                <PosCartLine
                    v-for="line in lines"
                    :key="line.variantId"
                    :line="line"
                    :format-cents="formatCents"
                    @increment="emit('increment', $event)"
                    @decrement="emit('decrement', $event)"
                    @remove="emit('removeLine', $event)"
                />
            </div>
        </div>

        <!-- Totals summary -->
        <div
            class="flex flex-col gap-2 text-sm"
            style="padding-top: 14px; margin-top: 14px; border-top: 1px solid var(--outline-variant)"
        >
            <div class="flex justify-between text-on-surface-variant">
                <span>Subtotal</span>
                <span class="tabular-nums">{{ formatCents(subtotalCents) }}</span>
            </div>
            <!--
                IVA row is shown as $0.00 intentionally.
                Tax logic is a backend TODO — the backend currently returns tax_cents: 0.
                Showing the row keeps the layout design-ready for when tax lands.
                The "Cobrar" amount correctly matches totalCents = subtotalCents + 0.
            -->
            <div class="flex justify-between text-on-surface-variant">
                <span>IVA</span>
                <span class="tabular-nums">{{ formatCents(taxCents) }}</span>
            </div>
            <div class="flex justify-between items-center pt-2">
                <span class="font-semibold text-base text-on-surface">Total</span>
                <span class="serif text-3xl text-primary tabular-nums">
                    {{ formatCents(totalCents) }}
                </span>
            </div>
        </div>

        <!-- Payment method selector -->
        <div class="mt-4">
            <PosPaymentSelector
                :model-value="paymentMethod"
                @update:model-value="emit('update:paymentMethod', $event)"
            />
        </div>

        <!-- Checkout button -->
        <button
            class="btn btn-primary mt-4 w-full justify-center"
            style="padding: 18px; font-size: 16px"
            :disabled="isEmpty || isSubmitting"
            :aria-busy="isSubmitting"
            data-testid="pos-checkout-btn"
            @click="emit('checkout')"
        >
            {{ cobrarLabel }}
        </button>
    </div>
</template>
