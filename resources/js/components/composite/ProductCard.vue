<script setup lang="ts">
import { ShoppingCart } from 'lucide-vue-next'
import PriceDisplay from './PriceDisplay.vue'

interface Props {
    name: string
    /** Price in cents */
    priceCents: number
    imageSrc?: string
    category?: string
}

withDefaults(defineProps<Props>(), {
    imageSrc: '',
    category: '',
})

const emit = defineEmits<{
    'add-to-cart': []
}>()
</script>

<template>
    <div class="bg-surface-lowest dark:bg-surface-low rounded-xl overflow-hidden shadow-[var(--shadow-ambient)] flex flex-col">
        <!-- Product image -->
        <div class="aspect-square bg-surface-low dark:bg-surface-mid overflow-hidden">
            <img
                v-if="imageSrc"
                :src="imageSrc"
                :alt="name"
                class="w-full h-full object-cover"
            />
            <div
                v-else
                class="w-full h-full flex items-center justify-center"
                style="background: var(--gradient-soft)"
                aria-hidden="true"
            >
                <svg viewBox="0 0 60 60" class="w-12 h-12 text-primary opacity-40" fill="currentColor" aria-hidden="true">
                    <circle cx="30" cy="30" r="6" />
                    <ellipse cx="30" cy="15" rx="6" ry="10" opacity="0.5" />
                    <ellipse cx="30" cy="45" rx="6" ry="10" opacity="0.5" />
                    <ellipse cx="15" cy="30" rx="10" ry="6" opacity="0.5" />
                    <ellipse cx="45" cy="30" rx="10" ry="6" opacity="0.5" />
                </svg>
            </div>
        </div>

        <!-- Info -->
        <div class="p-4 flex flex-col gap-2 flex-1">
            <p v-if="category" class="label text-on-surface-variant text-[10px]">
                {{ category }}
            </p>
            <h3 class="font-serif text-base text-on-surface tracking-tighter leading-snug">
                {{ name }}
            </h3>

            <div class="flex items-center justify-between mt-auto pt-2">
                <PriceDisplay :cents="priceCents" />

                <!-- Add to cart slot — defaults to icon button -->
                <slot name="action">
                    <button
                        type="button"
                        class="w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center hover:bg-primary-dim transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                        :aria-label="`Agregar ${name} al carrito`"
                        @click="emit('add-to-cart')"
                    >
                        <ShoppingCart :size="14" />
                    </button>
                </slot>
            </div>
        </div>
    </div>
</template>
