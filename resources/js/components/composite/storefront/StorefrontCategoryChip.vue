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
        class="bloom transition-all duration-200"
        :class="active ? 'bloom-primary' : 'bloom-soft'"
        :aria-pressed="active"
        @click="emit('select', category?.slug ?? null)"
    >
        {{ category?.name ?? 'Todos' }}
        <span
            v-if="category && category.products_count > 0"
            class="opacity-60 font-normal"
            style="font-size: 11px"
        >
            {{ category.products_count }}
        </span>
    </button>
</template>
