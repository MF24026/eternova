<script setup>
import { computed } from 'vue';
import Petal from './Petal.vue';

const props = defineProps({
    kind: {
        type: String,
        default: 'rose',
        validator: (v) => ['rose', 'peluche', 'bolso', 'llavero'].includes(v),
    },
    tone: {
        type: String,
        default: 'rose',
        validator: (v) => ['rose', 'lilac', 'sage', 'cream'].includes(v),
    },
});

const FILLS = {
    rose: '#7c545d',
    lilac: '#5a4b71',
    cream: '#a08589',
    sage: '#4a7c5a',
};

const ACCENTS = {
    rose: '#f8c4cf',
    lilac: '#eddcff',
    cream: '#fff0f2',
    sage: '#d8ecdc',
};

const fill = computed(() => FILLS[props.tone]);
const accent = computed(() => ACCENTS[props.tone]);
</script>

<template>
    <div class="surrogate">
        <div
            class="petal"
            style="top: -10%; left: -8%; width: 55%; height: 55%"
        >
            <Petal tone="lilac"/>
        </div>
        <div
            class="petal"
            style="bottom: -15%; right: -10%; width: 60%; height: 60%"
        >
            <Petal tone="cream"/>
        </div>

        <!-- Rose petal flower -->
        <Petal v-if="kind === 'rose'" :tone="tone"/>

        <!-- Plushie -->
        <svg v-else-if="kind === 'peluche'" viewBox="0 0 200 200">
            <ellipse cx="62" cy="78" rx="22" ry="26" :fill="accent"/>
            <ellipse cx="138" cy="78" rx="22" ry="26" :fill="accent"/>
            <ellipse cx="100" cy="115" rx="60" ry="62" :fill="accent"/>
            <circle cx="82" cy="105" r="3.5" :fill="fill"/>
            <circle cx="118" cy="105" r="3.5" :fill="fill"/>
            <path
                d="M 92 128 Q 100 135 108 128"
                :stroke="fill"
                stroke-width="2.5"
                fill="none"
                stroke-linecap="round"
            />
            <circle cx="68" cy="130" r="6" fill="#f8c4cf" opacity="0.6"/>
            <circle cx="132" cy="130" r="6" fill="#f8c4cf" opacity="0.6"/>
        </svg>

        <!-- Bag -->
        <svg v-else-if="kind === 'bolso'" viewBox="0 0 200 200">
            <path
                d="M 60 80 Q 60 50 100 50 Q 140 50 140 80"
                fill="none"
                :stroke="fill"
                stroke-width="4"
                opacity="0.5"
            />
            <path
                d="M 50 80 L 60 170 Q 60 175 65 175 L 135 175 Q 140 175 140 170 L 150 80 Z"
                :fill="fill"
            />
            <rect x="88" y="115" width="24" height="12" rx="3" :fill="accent" opacity="0.8"/>
            <circle cx="100" cy="121" r="2" :fill="fill"/>
        </svg>

        <!-- Key -->
        <svg v-else-if="kind === 'llavero'" viewBox="0 0 200 200">
            <circle cx="80" cy="80" r="38" fill="none" :stroke="fill" stroke-width="10"/>
            <circle cx="80" cy="80" r="14" :fill="accent"/>
            <path
                d="M 113 95 L 165 145 L 152 158 L 145 151 L 138 158 L 131 151 L 124 158 L 100 134"
                :fill="fill"
            />
        </svg>

        <slot/>
    </div>
</template>
