<script setup>
import { computed } from 'vue';

const props = defineProps({
    tone: {
        type: String,
        default: 'rose',
        validator: (v) => ['rose', 'lilac', 'sage', 'cream'].includes(v),
    },
    size: { type: Number, default: 1 },
});

const FILLS = {
    rose: ['#f8c4cf', '#7c545d'],
    lilac: ['#eddcff', '#5a4b71'],
    sage: ['#d8ecdc', '#4a7c5a'],
    cream: ['#fff0f2', '#7c545d'],
};

const colors = computed(() => FILLS[props.tone] || FILLS.rose);
const gradientId = computed(() => `pg-${props.tone}-${Math.random().toString(36).slice(2, 6)}`);
const dimensions = computed(() => ({
    width: `${props.size * 100}%`,
    height: `${props.size * 100}%`,
}));
</script>

<template>
    <svg viewBox="0 0 200 200" :style="dimensions">
        <defs>
            <radialGradient :id="gradientId" cx="50%" cy="40%" r="60%">
                <stop offset="0%" :stop-color="colors[0]" stop-opacity="0.95"/>
                <stop offset="100%" :stop-color="colors[1]" stop-opacity="0.55"/>
            </radialGradient>
        </defs>
        <g transform="translate(100 100)">
            <ellipse
                v-for="deg in [0, 60, 120, 180, 240, 300]"
                :key="deg"
                cx="0"
                cy="-38"
                rx="22"
                ry="48"
                :fill="`url(#${gradientId})`"
                :transform="`rotate(${deg})`"
                opacity="0.85"
            />
            <circle r="14" :fill="colors[1]" opacity="0.4"/>
            <circle r="6" :fill="colors[0]"/>
        </g>
    </svg>
</template>
