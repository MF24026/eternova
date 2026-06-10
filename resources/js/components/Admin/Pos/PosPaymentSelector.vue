<script setup lang="ts">
import { Wallet, CreditCard, ArrowLeftRight } from 'lucide-vue-next'
import type { PosPaymentMethod } from '@/types/domain/POS'

interface Props {
    modelValue: PosPaymentMethod
}

defineProps<Props>()
const emit = defineEmits<{
    'update:modelValue': [method: PosPaymentMethod]
}>()

interface PaymentOption {
    id: PosPaymentMethod
    label: string
    // Lucide icon component — typed as a constructor for use with <component :is>
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    icon: any
}

const options: PaymentOption[] = [
    { id: 'cash', label: 'Efectivo', icon: Wallet },
    { id: 'card', label: 'Tarjeta', icon: CreditCard },
    { id: 'transfer', label: 'Transferencia', icon: ArrowLeftRight },
]
</script>

<template>
    <div>
        <p class="label-gilt mb-2">Metodo de pago</p>
        <div class="grid grid-cols-3 gap-2">
            <button
                v-for="opt in options"
                :key="opt.id"
                class="py-3 rounded-xl flex flex-col items-center gap-1.5 text-xs font-semibold
                       transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30"
                :style="{
                    background: modelValue === opt.id ? 'var(--primary-container)' : 'var(--surface-lowest)',
                    color: modelValue === opt.id ? 'var(--primary-dim)' : 'var(--on-surface-variant)',
                }"
                :aria-pressed="modelValue === opt.id"
                :aria-label="`Pagar con ${opt.label}`"
                @click="emit('update:modelValue', opt.id)"
            >
                <component :is="opt.icon" :size="18" aria-hidden="true" />
                {{ opt.label }}
            </button>
        </div>
    </div>
</template>
