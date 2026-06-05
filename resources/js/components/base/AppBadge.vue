<script setup lang="ts">
interface Props {
    variant?: 'primary' | 'success' | 'warning' | 'error' | 'neutral' | 'info'
    size?: 'sm' | 'md'
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'neutral',
    size: 'md',
})

const variantClasses: Record<NonNullable<Props['variant']>, string> = {
    primary: 'bg-primary-container text-primary-dim dark:bg-primary/20 dark:text-primary',
    success: 'bg-success-container text-success dark:bg-success/20 dark:text-success',
    warning: 'bg-warning-container text-warning dark:bg-warning/20 dark:text-warning',
    error: 'bg-error-container text-error dark:bg-error/20 dark:text-error',
    info: 'bg-info-container text-info dark:bg-info/20 dark:text-info',
    neutral: 'bg-surface-high text-on-surface-variant dark:bg-surface-mid dark:text-on-surface-variant',
}

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    sm: 'text-[10px] px-2 py-0.5',
    md: 'text-xs px-2.5 py-1',
}
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1 rounded-full font-semibold uppercase tracking-[0.05em]',
            sizeClasses[props.size],
            variantClasses[props.variant],
        ]"
    >
        <slot />
    </span>
</template>
