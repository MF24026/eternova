<script setup lang="ts">
import { Minus, Plus, Trash2 } from 'lucide-vue-next'
import type { PosCartLine } from '@/types/domain/POS'

interface Props {
    line: PosCartLine
    formatCents: (cents: number) => string
}

const props = defineProps<Props>()
const emit = defineEmits<{
    increment: [variantId: number]
    decrement: [variantId: number]
    remove: [variantId: number]
}>()

const variantLabel = Object.values(props.line.variantOptions).join(' · ')
</script>

<template>
    <div class="flex items-center gap-3 py-2.5">
        <!-- Thumbnail -->
        <div class="w-11 h-11 rounded-lg shrink-0 overflow-hidden">
            <img
                v-if="line.imageUrl"
                :src="line.imageUrl"
                :alt="line.productName"
                class="w-full h-full object-cover"
            />
            <div
                v-else
                class="w-full h-full"
                style="background: var(--gradient-soft)"
                aria-hidden="true"
            />
        </div>

        <!-- Name + unit price -->
        <div class="grow min-w-0">
            <p class="text-sm font-semibold truncate text-on-surface" :title="line.productName">{{ line.productName }}</p>
            <p class="text-xs text-on-surface-variant">
                <span v-if="variantLabel" class="mr-1">{{ variantLabel }} ·</span>
                {{ formatCents(line.priceCents) }} c/u
            </p>
        </div>

        <!-- Qty stepper -->
        <div
            class="inline-flex items-center rounded-full p-0.5"
            style="background: var(--surface-lowest)"
        >
            <button
                class="w-7 h-7 flex items-center justify-center rounded-full transition-colors hover:bg-surface-low"
                :aria-label="`Reducir cantidad de ${line.productName}`"
                @click="emit('decrement', line.variantId)"
            >
                <Minus :size="12" aria-hidden="true" />
            </button>
            <span
                class="w-6 text-center text-xs font-semibold text-on-surface tabular-nums"
                :aria-label="`Cantidad: ${line.quantity}`"
            >
                {{ line.quantity }}
            </span>
            <button
                class="w-7 h-7 flex items-center justify-center rounded-full transition-colors hover:bg-surface-low"
                :disabled="line.quantity >= line.availableQuantity"
                :aria-label="`Aumentar cantidad de ${line.productName}`"
                @click="emit('increment', line.variantId)"
            >
                <Plus :size="12" aria-hidden="true" />
            </button>
        </div>

        <!-- Line total -->
        <span class="w-16 text-right text-sm font-semibold shrink-0 text-on-surface tabular-nums">
            {{ formatCents(line.priceCents * line.quantity) }}
        </span>

        <!-- Remove -->
        <button
            class="btn-icon w-7 h-7 shrink-0"
            :aria-label="`Eliminar ${line.productName} del carrito`"
            @click="emit('remove', line.variantId)"
        >
            <Trash2 :size="12" aria-hidden="true" />
        </button>
    </div>
</template>
