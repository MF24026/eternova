<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { X, ChevronDown } from 'lucide-vue-next'
import type { Category } from '@/types/domain/Category'

const props = defineProps<{
    modelValue: number[]
    availableCategories: Category[]
    placeholder?: string
}>()

const emit = defineEmits<{
    'update:modelValue': [ids: number[]]
}>()

const isOpen = ref(false)
const containerRef = ref<HTMLElement | null>(null)

const selectedCategories = computed<Category[]>(() =>
    props.availableCategories.filter((c) => props.modelValue.includes(c.id))
)

function toggle(categoryId: number): void {
    const current = props.modelValue
    if (current.includes(categoryId)) {
        emit('update:modelValue', current.filter((id) => id !== categoryId))
    } else {
        emit('update:modelValue', [...current, categoryId])
    }
}

function remove(categoryId: number): void {
    emit('update:modelValue', props.modelValue.filter((id) => id !== categoryId))
}

function onClickOutside(event: MouseEvent): void {
    if (containerRef.value && !containerRef.value.contains(event.target as Node)) {
        isOpen.value = false
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
    <div ref="containerRef" class="relative">
        <!-- Trigger showing selected chips -->
        <div
            class="flex flex-wrap gap-2 min-h-[42px] px-3 py-2 rounded-xl cursor-pointer"
            style="background: var(--surface-low)"
            @click="isOpen = !isOpen"
        >
            <span
                v-for="cat in selectedCategories"
                :key="cat.id"
                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium shrink-0"
                style="background: var(--secondary-container); color: var(--on-secondary-container)"
            >
                {{ cat.name }}
                <button
                    type="button"
                    :aria-label="`Quitar categoría ${cat.name}`"
                    class="opacity-70 hover:opacity-100"
                    @click.stop="remove(cat.id)"
                >
                    <X :size="10" />
                </button>
            </span>
            <span
                v-if="selectedCategories.length === 0"
                class="text-sm text-on-surface-variant self-center"
            >
                {{ placeholder ?? 'Seleccionar categorías...' }}
            </span>
            <ChevronDown
                :size="14"
                class="ml-auto self-center text-on-surface-variant"
                :class="{ 'rotate-180': isOpen }"
            />
        </div>

        <!-- Dropdown -->
        <div
            v-if="isOpen"
            class="absolute z-20 top-full mt-1 w-full rounded-xl shadow-lifted overflow-y-auto max-h-52"
            style="background: var(--surface); border: 1px solid var(--outline-variant)"
        >
            <button
                v-for="cat in availableCategories"
                :key="cat.id"
                type="button"
                class="w-full text-left px-4 py-2.5 text-sm flex items-center gap-3 hover:opacity-90"
                :style="cat.parent_id !== null ? 'padding-left: 2rem' : ''"
                @click.prevent="toggle(cat.id)"
            >
                <!-- Checkbox indicator -->
                <span
                    class="w-4 h-4 rounded flex-shrink-0 flex items-center justify-center"
                    :style="modelValue.includes(cat.id)
                        ? 'background: var(--primary); color: var(--on-primary)'
                        : 'border: 1.5px solid var(--outline-variant)'"
                >
                    <svg v-if="modelValue.includes(cat.id)" viewBox="0 0 12 12" width="10" height="10" fill="currentColor">
                        <path d="M1 6l3.5 3.5L11 1.5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <span class="text-on-surface">{{ cat.name }}</span>
            </button>

            <div v-if="availableCategories.length === 0" class="px-4 py-3 text-sm text-on-surface-variant">
                Sin categorías disponibles.
            </div>
        </div>
    </div>
</template>
