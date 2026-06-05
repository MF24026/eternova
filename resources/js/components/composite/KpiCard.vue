<script setup lang="ts">
import { TrendingUp, TrendingDown } from 'lucide-vue-next'

interface Props {
    title: string
    value: string
    trend?: string
    trendPositive?: boolean | null
    variant?: 'primary' | 'warning' | 'error' | 'info' | 'success'
}

withDefaults(defineProps<Props>(), {
    trend: '',
    trendPositive: null,
    variant: 'primary',
})

const containerBg: Record<NonNullable<Props['variant']>, string> = {
    primary: 'var(--primary-container)',
    warning: 'var(--warning-container)',
    error: 'var(--error-container)',
    info: 'var(--info-container)',
    success: 'var(--success-container)',
}

const iconColor: Record<NonNullable<Props['variant']>, string> = {
    primary: 'var(--primary)',
    warning: 'var(--warning)',
    error: 'var(--error)',
    info: 'var(--info)',
    success: 'var(--success)',
}
</script>

<template>
    <div
        class="kpi relative overflow-hidden bg-surface-lowest dark:bg-surface-low rounded-2xl shadow-[var(--shadow-ambient)] p-6 flex flex-col gap-3"
    >
        <!-- Bloom decoration -->
        <div
            class="absolute -right-4 -top-4 w-20 h-20 rounded-full opacity-50"
            :style="{ background: containerBg[variant] }"
            aria-hidden="true"
        />

        <!-- Icon slot -->
        <div
            class="w-9 h-9 rounded-xl flex items-center justify-center relative"
            :style="{ background: containerBg[variant], color: iconColor[variant] }"
        >
            <slot name="icon" />
        </div>

        <div class="relative">
            <p class="label text-on-surface-variant">{{ title }}</p>
            <p class="font-serif text-3xl text-on-surface tracking-tighter leading-none mt-1">
                {{ value }}
            </p>
        </div>

        <p v-if="trend" class="relative text-xs flex items-center gap-1">
            <TrendingUp
                v-if="trendPositive === true"
                :size="12"
                class="text-success"
                aria-hidden="true"
            />
            <TrendingDown
                v-else-if="trendPositive === false"
                :size="12"
                class="text-error"
                aria-hidden="true"
            />
            <span :class="trendPositive === true ? 'text-success' : trendPositive === false ? 'text-error' : 'text-on-surface-variant'">
                {{ trend }}
            </span>
        </p>
    </div>
</template>
