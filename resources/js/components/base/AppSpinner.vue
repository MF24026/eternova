<script setup lang="ts">
interface Props {
    size?: 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
})

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    sm: 'w-4 h-4 border-2',
    md: 'w-6 h-6 border-2',
    lg: 'w-10 h-10 border-[3px]',
}
</script>

<template>
    <span
        :class="[
            'inline-block rounded-full border-solid animate-spin',
            'border-primary/25 border-t-primary',
            sizeClasses[props.size],
        ]"
        role="status"
        aria-label="Cargando"
    />
</template>
