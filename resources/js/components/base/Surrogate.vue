<script setup lang="ts">
/**
 * Botanical gradient placeholder shown when a product has no real image.
 *
 * kind: maps to the SVG art style (rose petal / plushie bear / bag / keyring).
 * tone: maps to the colour palette used for fills.
 *
 * When real catalog products lack a kind/tone, default to 'rose' and cycle
 * tone from the product id so fallbacks still look varied.
 */
import Petal from '@/components/base/Petal.vue'

type SurrogateKind = 'rose' | 'peluche' | 'bolso' | 'llavero'
type SurrogateTone = 'rose' | 'lilac' | 'cream' | 'sage'

interface Props {
    kind?: SurrogateKind
    tone?: SurrogateTone
    /** When true the surrogate fills its container absolutely (use inside a relative wrapper) */
    fill?: boolean
}

withDefaults(defineProps<Props>(), {
    kind: 'rose',
    tone: 'rose',
    fill: false,
})

const fillColors: Record<SurrogateTone, string> = {
    rose: '#f8c4cf',
    lilac: '#eddcff',
    cream: '#fff0f2',
    sage: '#d8ecdc',
}

const darkColors: Record<SurrogateTone, string> = {
    rose: '#7c545d',
    lilac: '#5a4b71',
    cream: '#a08589',
    sage: '#4a7c5a',
}
</script>

<template>
    <div
        class="surrogate"
        :class="fill ? 'absolute inset-0' : 'w-full h-full'"
    >
        <!-- Top-left ambient petal -->
        <div
            class="petal"
            style="top: -10%; left: -8%; width: 55%; height: 55%;"
        >
            <Petal tone="lilac" :size="1" />
        </div>

        <!-- Bottom-right ambient petal -->
        <div
            class="petal"
            style="bottom: -15%; right: -10%; width: 60%; height: 60%;"
        >
            <Petal tone="cream" :size="1" />
        </div>

        <!-- Rose / Petal art -->
        <Petal v-if="kind === 'rose'" :tone="tone" :size="0.6" />

        <!-- Plushie bear art -->
        <svg v-else-if="kind === 'peluche'" viewBox="0 0 200 200" style="width:60%;height:60%" aria-hidden="true">
            <ellipse cx="62" cy="78" rx="22" ry="26" :fill="fillColors[tone]" />
            <ellipse cx="138" cy="78" rx="22" ry="26" :fill="fillColors[tone]" />
            <ellipse cx="100" cy="115" rx="60" ry="62" :fill="fillColors[tone]" />
            <circle cx="82" cy="105" r="3.5" :fill="darkColors[tone]" />
            <circle cx="118" cy="105" r="3.5" :fill="darkColors[tone]" />
            <path d="M 92 128 Q 100 135 108 128" :stroke="darkColors[tone]" stroke-width="2.5" fill="none" stroke-linecap="round" />
            <circle cx="68" cy="130" r="6" fill="#f8c4cf" opacity=".6" />
            <circle cx="132" cy="130" r="6" fill="#f8c4cf" opacity=".6" />
        </svg>

        <!-- Bag art -->
        <svg v-else-if="kind === 'bolso'" viewBox="0 0 200 200" style="width:60%;height:60%" aria-hidden="true">
            <path
                d="M 60 80 Q 60 50 100 50 Q 140 50 140 80"
                fill="none"
                :stroke="darkColors[tone]"
                stroke-width="4"
                opacity=".5"
            />
            <path
                d="M 50 80 L 60 170 Q 60 175 65 175 L 135 175 Q 140 175 140 170 L 150 80 Z"
                :fill="darkColors[tone]"
            />
            <rect x="88" y="115" width="24" height="12" rx="3" :fill="fillColors[tone]" opacity=".8" />
            <circle cx="100" cy="121" r="2" :fill="darkColors[tone]" />
        </svg>

        <!-- Keyring art -->
        <svg v-else-if="kind === 'llavero'" viewBox="0 0 200 200" style="width:60%;height:60%" aria-hidden="true">
            <circle cx="80" cy="80" r="38" fill="none" :stroke="darkColors[tone]" stroke-width="10" />
            <circle cx="80" cy="80" r="14" :fill="fillColors[tone]" />
            <path d="M 113 95 L 165 145 L 152 158 L 145 151 L 138 158 L 131 151 L 124 158 L 100 134" :fill="darkColors[tone]" />
        </svg>
    </div>
</template>
