<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { X, Search } from 'lucide-vue-next'
import type { Tag } from '@/types/domain/Product'

const props = defineProps<{
    modelValue: number[]
    availableTags: Tag[]
    placeholder?: string
}>()

const emit = defineEmits<{
    'update:modelValue': [ids: number[]]
}>()

const search = ref('')
const isOpen = ref(false)
const containerRef = ref<HTMLElement | null>(null)

const selectedTags = computed<Tag[]>(() =>
    props.availableTags.filter((t) => props.modelValue.includes(t.id))
)

const filteredOptions = computed<Tag[]>(() => {
    const term = search.value.trim().toLowerCase()
    return props.availableTags.filter((t) => {
        if (props.modelValue.includes(t.id)) return false
        if (term === '') return true
        return t.name.toLowerCase().includes(term)
    })
})

function select(tag: Tag): void {
    emit('update:modelValue', [...props.modelValue, tag.id])
    search.value = ''
}

function deselect(tagId: number): void {
    emit('update:modelValue', props.modelValue.filter((id) => id !== tagId))
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
        <!-- Selected chips + search input -->
        <div
            class="flex flex-wrap gap-2 min-h-[42px] px-3 py-2 rounded-xl cursor-text"
            style="background: var(--surface-low)"
            @click="isOpen = true"
        >
            <span
                v-for="tag in selectedTags"
                :key="tag.id"
                class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium shrink-0"
                style="background: var(--primary-container); color: var(--on-primary-container)"
            >
                {{ tag.name }}
                <button
                    type="button"
                    :aria-label="`Quitar etiqueta ${tag.name}`"
                    class="opacity-70 hover:opacity-100"
                    @click.stop="deselect(tag.id)"
                >
                    <X :size="10" />
                </button>
            </span>
            <input
                v-model="search"
                type="text"
                :placeholder="selectedTags.length === 0 ? (placeholder ?? 'Buscar etiquetas...') : ''"
                class="flex-1 min-w-24 bg-transparent text-sm text-on-surface focus:outline-none"
                @focus="isOpen = true"
            />
        </div>

        <!-- Dropdown -->
        <div
            v-if="isOpen && filteredOptions.length > 0"
            class="absolute z-20 top-full mt-1 w-full rounded-xl shadow-lifted overflow-hidden"
            style="background: var(--surface); border: 1px solid var(--outline-variant)"
        >
            <button
                v-for="tag in filteredOptions"
                :key="tag.id"
                type="button"
                class="w-full text-left px-4 py-2 text-sm text-on-surface hover:opacity-90 flex items-center gap-2"
                style="transition: background 0.15s"
                @click.prevent="select(tag)"
            >
                <Search :size="12" class="text-on-surface-variant shrink-0" />
                {{ tag.name }}
            </button>
        </div>
    </div>
</template>
