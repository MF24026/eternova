<script setup lang="ts">
import AppSpinner from './AppSpinner.vue'

interface Props {
    variant?: 'primary' | 'secondary' | 'ghost'
    loading?: boolean
    disabled?: boolean
    type?: 'button' | 'submit' | 'reset'
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'primary',
    loading: false,
    disabled: false,
    type: 'button',
})

const variantClasses: Record<NonNullable<Props['variant']>, string> = {
    primary: [
        'bg-primary text-on-primary',
        'hover:bg-primary-dim',
        'focus-visible:ring-primary/40',
        'dark:bg-primary-container dark:text-on-surface dark:hover:bg-primary/80',
    ].join(' '),
    secondary: [
        'bg-surface-high text-on-surface',
        'hover:bg-surface-highest',
        'focus-visible:ring-on-surface/20',
        'dark:bg-surface-mid dark:hover:bg-surface-high',
    ].join(' '),
    ghost: [
        'bg-transparent text-primary',
        'hover:bg-primary-container/30',
        'focus-visible:ring-primary/30',
        'dark:text-primary-container dark:hover:bg-primary/10',
    ].join(' '),
}
</script>

<template>
    <button
        :type="props.type"
        :disabled="props.disabled || props.loading"
        :class="[
            'inline-flex items-center justify-center gap-2',
            'px-5 py-2.5 rounded-full',
            'font-sans font-medium text-sm',
            'transition-colors duration-150',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-1',
            'disabled:opacity-50 disabled:pointer-events-none',
            variantClasses[props.variant],
        ]"
    >
        <AppSpinner v-if="props.loading" size="sm" />
        <slot />
    </button>
</template>
