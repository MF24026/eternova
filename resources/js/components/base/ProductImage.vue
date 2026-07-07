<script setup lang="ts">
/**
 * Unified product image with a consistent botanical fallback.
 *
 * Before this component, POS tiles fell back to a flat gradient while the
 * storefront fell back to the richer <Surrogate> illustration — the same
 * missing-image state looked different on each surface. ProductImage renders
 * the real image when present, otherwise the shared Surrogate illustration, so
 * every surface degrades the same way.
 *
 * Drop it inside any aspect-ratio box; it fills the box (relative wrapper).
 */
import { computed } from 'vue'
import Surrogate from '@/components/base/Surrogate.vue'

type SurrogateKind = 'rose' | 'peluche' | 'bolso' | 'llavero'
type SurrogateTone = 'rose' | 'lilac' | 'cream' | 'sage'

const TONES: readonly SurrogateTone[] = ['rose', 'lilac', 'cream', 'sage']

interface Props {
    src?: string | null
    alt: string
    kind?: SurrogateKind
    /** Explicit fallback tone. Wins over `seed`. */
    tone?: SurrogateTone
    /** When `tone` is not set, derive a varied tone from this number (e.g. product id). */
    seed?: number
    /** Extra classes for the <img> (e.g. hover transforms). */
    imgClass?: string
}

const props = withDefaults(defineProps<Props>(), {
    src: null,
    kind: 'rose',
    tone: undefined,
    seed: 0,
    imgClass: '',
})

const resolvedTone = computed<SurrogateTone>(
    () => props.tone ?? TONES[Math.abs(props.seed) % TONES.length],
)
</script>

<template>
    <div class="relative w-full h-full overflow-hidden">
        <img
            v-if="src"
            :src="src"
            :alt="alt"
            loading="lazy"
            :class="['absolute inset-0 w-full h-full object-cover', imgClass]"
        />
        <Surrogate
            v-else
            :kind="kind"
            :tone="resolvedTone"
            :fill="true"
        />
    </div>
</template>
