<script setup lang="ts">
import ProductImage from '@/components/base/ProductImage.vue'
import type { PosProduct } from '@/types/domain/POS'

interface Props {
    product: PosProduct
    formatCents: (cents: number) => string
}

const props = defineProps<Props>()
const emit = defineEmits<{
    select: [product: PosProduct]
}>()

// The POS always adds the first variant on tile tap. Multi-variant selection
// (picker slideover) is deferred to a future ticket.
const defaultVariant = props.product.variants[0]

const isOutOfStock = !defaultVariant || defaultVariant.available_quantity <= 0
</script>

<template>
    <button
        class="product-tile"
        :disabled="isOutOfStock"
        :aria-label="`Agregar ${product.name} al carrito`"
        :aria-disabled="isOutOfStock"
        :class="{ 'opacity-50 cursor-not-allowed': isOutOfStock }"
        @click="!isOutOfStock && emit('select', product)"
    >
        <!-- Product image or shared botanical placeholder -->
        <div class="aspect-square overflow-hidden">
            <ProductImage
                :src="product.default_image_url"
                :alt="product.name"
                :seed="product.id"
            />
        </div>

        <div class="p-3">
            <p class="text-sm font-semibold truncate text-on-surface">{{ product.name }}</p>
            <p class="text-sm font-bold text-primary mt-1">
                {{ formatCents(defaultVariant?.price_cents ?? product.base_price_cents) }}
            </p>
            <p
                v-if="isOutOfStock"
                class="text-xs mt-1"
                style="color: var(--error)"
            >
                Sin stock
            </p>
        </div>
    </button>
</template>
