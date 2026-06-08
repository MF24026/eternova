<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { Star, ImageOff } from 'lucide-vue-next'
import type { StorefrontProduct } from '@/types/domain/Storefront'
import { useStorefrontStore } from '@/stores/storefront'

interface Props {
    product: StorefrontProduct
}

const props = defineProps<Props>()

const store = useStorefrontStore()

const formattedPrice = computed(() => store.formatPrice(props.product.base_price_cents))

const hasImage = computed(() => Boolean(props.product.default_image_url))
</script>

<template>
    <RouterLink
        :to="{ name: 'storefront.product', params: { slug: product.slug } }"
        class="group block rounded-xl overflow-hidden focus-visible:ring-2 transition-transform duration-300 hover:-translate-y-1"
        style="background: var(--surface-lowest); box-shadow: var(--shadow-rest)"
    >
        <!-- Product image -->
        <div class="relative aspect-square overflow-hidden" style="background: var(--gradient-bloom)">
            <img
                v-if="hasImage"
                :src="product.default_image_url!"
                :alt="product.name"
                class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                loading="lazy"
            />
            <div
                v-else
                class="w-full h-full flex items-center justify-center"
            >
                <ImageOff :size="32" class="text-on-surface-variant opacity-40" />
            </div>

            <!-- Featured badge -->
            <span
                v-if="product.is_featured"
                class="absolute top-2 left-2 inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-semibold"
                style="background: var(--primary-container); color: var(--primary-dim)"
                aria-label="Producto destacado"
            >
                <Star :size="10" :fill="'currentColor'" />
                Destacado
            </span>
        </div>

        <!-- Card body -->
        <div class="p-3.5">
            <p
                class="font-serif text-base text-on-surface truncate mb-1 tracking-tight"
                :title="product.name"
            >
                {{ product.name }}
            </p>

            <p
                class="text-sm font-bold"
                style="color: var(--brand-primary, var(--primary))"
            >
                {{ formattedPrice }}
            </p>

            <!-- Category tags -->
            <div v-if="product.categories.length > 0" class="flex flex-wrap gap-1 mt-2">
                <span
                    v-for="cat in product.categories.slice(0, 2)"
                    :key="cat.slug"
                    class="text-xs px-2 py-0.5 rounded-full"
                    style="background: var(--surface-mid); color: var(--on-surface-variant)"
                >
                    {{ cat.name }}
                </span>
            </div>
        </div>
    </RouterLink>
</template>
