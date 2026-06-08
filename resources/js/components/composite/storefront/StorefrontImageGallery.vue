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

// The index of the currently displayed full/medium image.
const activeIndex = ref(0)

// Reset to first image when the gallery changes (navigating between products).
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

function activeMediumSrc(): string | null {
    if (props.gallery.length > 0) {
        return props.gallery[activeIndex.value]?.medium ?? null
    }
    return props.defaultImage
}
</script>

<template>
    <div class="space-y-3">
        <!-- Main image -->
        <div
            class="relative w-full aspect-square rounded-xl overflow-hidden"
            style="background: var(--gradient-bloom)"
        >
            <img
                v-if="activeMediumSrc()"
                :src="activeMediumSrc()!"
                :alt="productName"
                class="w-full h-full object-cover"
            />
            <div
                v-else
                class="w-full h-full flex items-center justify-center"
            >
                <ImageOff :size="48" class="text-on-surface-variant opacity-30" />
            </div>

            <!-- Prev/Next arrows — only shown when there are multiple images -->
            <template v-if="gallery.length > 1">
                <button
                    type="button"
                    class="absolute left-2 top-1/2 -translate-y-1/2 btn-icon w-9 h-9"
                    style="background: color-mix(in oklab, var(--surface-lowest) 80%, transparent)"
                    aria-label="Imagen anterior"
                    @click="prev"
                >
                    <ChevronLeft :size="18" />
                </button>
                <button
                    type="button"
                    class="absolute right-2 top-1/2 -translate-y-1/2 btn-icon w-9 h-9"
                    style="background: color-mix(in oklab, var(--surface-lowest) 80%, transparent)"
                    aria-label="Imagen siguiente"
                    @click="next"
                >
                    <ChevronRight :size="18" />
                </button>
            </template>
        </div>

        <!-- Thumbnail strip — rendered only when more than one image exists -->
        <div
            v-if="gallery.length > 1"
            class="flex gap-2 overflow-x-auto pb-1 scroll"
        >
            <button
                v-for="(image, i) in gallery"
                :key="i"
                type="button"
                class="shrink-0 w-16 h-16 rounded-lg overflow-hidden transition-all duration-200 focus-visible:ring-2 focus-visible:ring-offset-1"
                :class="activeIndex === i ? 'ring-2' : 'opacity-60 hover:opacity-100'"
                :style="activeIndex === i ? 'ring-color: var(--brand-primary, var(--primary))' : ''"
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
