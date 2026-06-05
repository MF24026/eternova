<script setup lang="ts">
interface Props {
    padding?: 'none' | 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
    padding: 'md',
})

const paddingClasses: Record<NonNullable<Props['padding']>, string> = {
    none: '',
    sm: 'p-4',
    md: 'p-6',
    lg: 'p-8',
}
</script>

<template>
    <div
        :class="[
            'bg-surface-lowest rounded-xl',
            'shadow-[var(--shadow-ambient)]',
            'dark:bg-surface-low',
            paddingClasses[props.padding],
        ]"
    >
        <slot />
    </div>
</template>
