<script setup lang="ts">
type PetalTone = 'rose' | 'lilac' | 'cream' | 'sage'

interface Props {
    tone?: PetalTone
    size?: number
}

withDefaults(defineProps<Props>(), {
    tone: 'rose',
    size: 1,
})

const fills: Record<PetalTone, [string, string]> = {
    rose: ['#f8c4cf', '#7c545d'],
    lilac: ['#eddcff', '#5a4b71'],
    sage: ['#d8ecdc', '#4a7c5a'],
    cream: ['#fff0f2', '#7c545d'],
}
</script>

<template>
    <svg
        :viewBox="`0 0 200 200`"
        :style="{ width: `${size * 100}%`, height: `${size * 100}%` }"
        :aria-hidden="true"
    >
        <defs>
            <radialGradient :id="`pg-${tone}`" cx="50%" cy="40%" r="60%">
                <stop offset="0%" :stop-color="fills[tone][0]" stop-opacity=".95" />
                <stop offset="100%" :stop-color="fills[tone][1]" stop-opacity=".55" />
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
                :fill="`url(#pg-${tone})`"
                :transform="`rotate(${deg})`"
                opacity=".85"
            />
            <circle r="14" :fill="fills[tone][1]" opacity=".4" />
            <circle r="6" :fill="fills[tone][0]" />
        </g>
    </svg>
</template>
