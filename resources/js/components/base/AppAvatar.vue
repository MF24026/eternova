<script setup lang="ts">
import { computed } from 'vue'

interface Props {
    src?: string
    name: string
    size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'
}

const props = withDefaults(defineProps<Props>(), {
    src: '',
    size: 'md',
})

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    xs: 'w-6 h-6 text-[10px]',
    sm: 'w-8 h-8 text-xs',
    md: 'w-10 h-10 text-sm',
    lg: 'w-12 h-12 text-base',
    xl: 'w-16 h-16 text-xl',
}

const initials = computed(() => {
    const parts = props.name.trim().split(/\s+/)
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase()
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase()
})
</script>

<template>
    <span
        :class="[
            'inline-flex items-center justify-center rounded-full overflow-hidden shrink-0',
            sizeClasses[props.size],
        ]"
        :aria-label="name"
    >
        <img
            v-if="src"
            :src="src"
            :alt="name"
            class="w-full h-full object-cover"
        />
        <span
            v-else
            class="w-full h-full flex items-center justify-center font-semibold text-primary-dim"
            style="background: var(--gradient-soft)"
        >
            {{ initials }}
        </span>
    </span>
</template>
