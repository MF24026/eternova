<script setup lang="ts">
import { computed } from 'vue'
import { useFormatCurrency } from '@/composables/useFormatCurrency'

interface Props {
    /** Amount in cents (integer). Pass 8900 for $89.00 */
    cents: number
    size?: 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
    size: 'md',
})

const { formatCents } = useFormatCurrency()

const formatted = computed(() => formatCents(props.cents))

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    sm: 'text-sm font-semibold',
    md: 'text-base font-semibold',
    lg: 'font-serif text-2xl tracking-tighter',
}
</script>

<template>
    <span
        :class="['text-primary', sizeClasses[size]]"
        :title="`${cents / 100}`"
    >
        {{ formatted }}
    </span>
</template>
