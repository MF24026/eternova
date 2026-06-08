<script setup lang="ts">
import type { StorefrontCategory } from '@/types/domain/Storefront'

interface Props {
    category: StorefrontCategory | null // null = "All categories" chip
    active: boolean
}

defineProps<Props>()

const emit = defineEmits<{
    select: [slug: string | null]
}>()
</script>

<template>
    <button
        type="button"
        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-full text-sm font-semibold transition-all duration-200 whitespace-nowrap focus-visible:ring-2 focus-visible:ring-offset-1"
        :class="active
            ? 'text-on-primary shadow-sm'
            : 'text-on-surface-variant hover:text-on-surface'"
        :style="active
            ? 'background: var(--brand-primary, var(--primary))'
            : 'background: var(--surface-high)'"
        :aria-pressed="active"
        @click="emit('select', category?.slug ?? null)"
    >
        {{ category?.name ?? 'Todos' }}
        <span
            v-if="category && category.products_count > 0"
            class="text-xs font-normal opacity-70"
        >
            {{ category.products_count }}
        </span>
    </button>
</template>
