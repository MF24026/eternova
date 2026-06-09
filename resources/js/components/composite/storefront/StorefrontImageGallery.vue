<script setup lang="ts">
import { ref, watch } from 'vue'
import { ImageOff, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import type { StorefrontProductImage } from '@/types/domain/Storefront'

interface Props {
    gallery: StorefrontProductImage[]
    defaultImage: string | null
    productName: string
}

const props = defineProps<Props>()

const activeIndex = ref(0)

watch(() => props.gallery, () => { activeIndex.value = 0 })

function selectIndex(i: number): void {
    activeIndex.value = i
}

function prev(): void {
    activeIndex.value = activeIndex.value > 0
        ? activeIndex.value - 1
        : props.gallery.length - 1
}

function next(): void {
    activeIndex.value = activeIndex.value < props.gallery.length - 1
        ? activeIndex.value + 1
        : 0
}

function activeFullSrc(): string | null {
    if (props.gallery.length > 0) {
        return props.gallery[activeIndex.value]?.full ?? props.gallery[activeIndex.value]?.medium ?? null
    }
    return props.defaultImage
}
</script>

<template>
    <div class="space-y-3">
        <!-- Main image — 4/5 ratio matching prototype -->
        <div
            class="relative w-full overflow-hidden"
            style="aspect-ratio: 4/5; border-radius: var(--r-xl); background: var(--gradient-bloom)"
        >
            <img
                v-if="activeFullSrc()"
                :src="activeFullSrc()!"
                :alt="productName"
                class="w-full h-full object-cover"
            />
            <div
                v-else
                class="w-full h-full flex items-center justify-center"
            >
                <ImageOff :size="48" style="color: var(--on-surface-variant); opacity: .3" />
            </div>

            <!-- Prev/Next only when multiple images -->
            <template v-if="gallery.length > 1">
                <button
                    type="button"
                    class="btn-icon absolute left-3 top-1/2 -translate-y-1/2"
                    style="width: 36px; height: 36px; background: color-mix(in oklab, var(--surface-lowest) 80%, transparent)"
                    aria-label="Imagen anterior"
                    @click="prev"
                >
                    <ChevronLeft :size="18" />
                </button>
                <button
                    type="button"
                    class="btn-icon absolute right-3 top-1/2 -translate-y-1/2"
                    style="width: 36px; height: 36px; background: color-mix(in oklab, var(--surface-lowest) 80%, transparent)"
                    aria-label="Imagen siguiente"
                    @click="next"
                >
                    <ChevronRight :size="18" />
                </button>
            </template>
        </div>

        <!-- Thumbnail strip -->
        <div
            v-if="gallery.length > 1"
            class="grid gap-3"
            style="grid-template-columns: repeat(3, 1fr)"
        >
            <button
                v-for="(image, i) in gallery"
                :key="i"
                type="button"
                class="overflow-hidden transition-all duration-200 focus-visible:ring-2 focus-visible:ring-offset-1"
                style="aspect-ratio: 1/1; border-radius: var(--r-lg)"
                :style="activeIndex === i
                    ? 'box-shadow: 0 0 0 2px var(--primary)'
                    : 'opacity: .65'"
                :aria-label="`Ver imagen ${i + 1}`"
                :aria-pressed="activeIndex === i"
                @click="selectIndex(i)"
            >
                <img
                    :src="image.thumbnail"
                    :alt="`${productName} — imagen ${i + 1}`"
                    class="w-full h-full object-cover"
                    loading="lazy"
                />
            </button>
        </div>
    </div>
</template>
