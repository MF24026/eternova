<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import type { StorefrontProduct } from '@/types/domain/Storefront'
import { useStorefrontStore } from '@/stores/storefront'
import Surrogate from '@/components/base/Surrogate.vue'

interface Props {
    product: StorefrontProduct
}

const props = defineProps<Props>()
const store = useStorefrontStore()

const formattedPrice = computed(() => store.formatPrice(props.product.base_price_cents))

const TONES = ['rose', 'lilac', 'cream', 'sage'] as const
type SurrogateTone = typeof TONES[number]

function toneFromId(id: number): SurrogateTone {
    return TONES[id % TONES.length]
}
</script>

<template>
    <RouterLink
        :to="{ name: 'storefront.product', params: { slug: product.slug } }"
        class="group block text-left card-hover overflow-hidden"
        style="transition: transform .35s ease; border-radius: var(--r-xl)"
    >
        <!-- Product image -->
        <div
            class="relative overflow-hidden mb-3.5"
            style="aspect-ratio: 1/1; border-radius: var(--r-xl)"
        >
            <img
                v-if="product.default_image_url"
                :src="product.default_image_url"
                :alt="product.name"
                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                loading="lazy"
            />
            <Surrogate
                v-else
                :tone="toneFromId(product.id)"
                :fill="true"
            />

            <!-- Featured badge -->
            <span
                v-if="product.is_featured"
                class="absolute top-2 left-2 bloom bloom-primary text-xs"
                aria-label="Producto destacado"
            >
                Destacado
            </span>
        </div>

        <!-- Card body -->
        <div class="label-gilt" style="margin-bottom: 4px">
            {{ product.categories[0]?.name ?? 'Producto' }}
        </div>
        <p
            class="serif truncate"
            style="font-size: 18px; margin-bottom: 6px"
            :title="product.name"
        >
            {{ product.name }}
        </p>
        <p style="font-size: 14px; font-weight: 600; color: var(--primary)">
            {{ formattedPrice }}
        </p>
    </RouterLink>
</template>
