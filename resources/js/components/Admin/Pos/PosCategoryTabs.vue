<script setup lang="ts">
import type { PosProductCategory } from '@/types/domain/POS'

interface Props {
    categories: PosProductCategory[]
    modelValue: string
}

defineProps<Props>()
const emit = defineEmits<{
    'update:modelValue': [slug: string]
}>()

const ALL_SLUG = 'all'
</script>

<template>
    <div
        class="scroll"
        style="overflow-x: auto; white-space: nowrap; padding-bottom: 2px"
        role="tablist"
        aria-label="Categorías"
    >
        <div class="tabs inline-flex">
            <button
                :class="['tab', { active: modelValue === ALL_SLUG }]"
                role="tab"
                :aria-selected="modelValue === ALL_SLUG"
                @click="emit('update:modelValue', ALL_SLUG)"
            >
                Todo
            </button>
            <button
                v-for="cat in categories"
                :key="cat.id"
                :class="['tab', { active: modelValue === cat.slug }]"
                role="tab"
                :aria-selected="modelValue === cat.slug"
                @click="emit('update:modelValue', cat.slug)"
            >
                {{ cat.name }}
            </button>
        </div>
    </div>
</template>
