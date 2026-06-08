<script setup lang="ts">
import { ref } from 'vue'
import { Star, Trash2 } from 'lucide-vue-next'
import AppSpinner from '@/components/base/AppSpinner.vue'
import type { ProductImage } from '@/types/domain/Product'

interface Props {
    images: ProductImage[]
    defaultImageUrl?: string | null
    deletingUrl?: string | null
    settingDefaultUrl?: string | null
}

const props = withDefaults(defineProps<Props>(), {
    defaultImageUrl:   null,
    deletingUrl:       null,
    settingDefaultUrl: null,
})

const emit = defineEmits<{
    (e: 'set-default', fullUrl: string): void
    (e: 'delete', fullUrl: string): void
    (e: 'reorder', orderedFullUrls: string[]): void
}>()

// ── Drag-to-reorder (HTML5 native) ────────────────────────────────────────────
const dragIndex = ref<number | null>(null)

function onDragStart(index: number): void {
    dragIndex.value = index
}

function onDragOver(event: DragEvent, index: number): void {
    event.preventDefault()

    if (dragIndex.value === null || dragIndex.value === index) return

    const reordered = [...props.images]
    const [moved]   = reordered.splice(dragIndex.value, 1)
    reordered.splice(index, 0, moved)
    dragIndex.value = index

    emit('reorder', reordered.map((img) => img.full))
}

function onDragEnd(): void {
    dragIndex.value = null
}
</script>

<template>
    <div v-if="images.length === 0" class="text-sm text-on-surface-variant py-4 text-center">
        Sin imagenes. Sube la primera imagen con el selector de arriba.
    </div>

    <div
        v-else
        class="grid gap-3"
        style="grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr))"
    >
        <div
            v-for="(image, index) in images"
            :key="image.full"
            :draggable="true"
            :class="[
                'relative rounded-xl overflow-hidden group cursor-grab select-none',
                dragIndex === index ? 'opacity-40' : 'opacity-100',
            ]"
            style="aspect-ratio: 1; background: var(--surface-low)"
            @dragstart="onDragStart(index)"
            @dragover="onDragOver($event, index)"
            @dragend="onDragEnd"
        >
            <!-- Thumbnail -->
            <img
                :src="image.thumbnail"
                :alt="`Imagen ${index + 1}`"
                class="w-full h-full object-cover"
                loading="lazy"
            />

            <!-- Default badge -->
            <div
                v-if="defaultImageUrl === image.full"
                class="absolute top-1.5 left-1.5 flex items-center gap-1 px-1.5 py-0.5 rounded-lg text-xs font-semibold"
                style="background: var(--primary); color: var(--on-primary)"
            >
                <Star :size="10" :stroke-width="2.5" />
                Principal
            </div>

            <!-- Hover actions overlay -->
            <div
                class="absolute inset-0 flex items-center justify-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity duration-150"
                style="background: rgba(0,0,0,0.45)"
            >
                <!-- Set default -->
                <button
                    v-if="defaultImageUrl !== image.full"
                    type="button"
                    class="w-8 h-8 rounded-lg flex items-center justify-center transition-transform hover:scale-110"
                    style="background: var(--primary); color: var(--on-primary)"
                    title="Establecer como imagen principal"
                    :disabled="settingDefaultUrl === image.full"
                    @click.stop="emit('set-default', image.full)"
                >
                    <AppSpinner v-if="settingDefaultUrl === image.full" size="sm" />
                    <Star v-else :size="14" :stroke-width="2" />
                </button>

                <!-- Delete -->
                <button
                    type="button"
                    class="w-8 h-8 rounded-lg flex items-center justify-center transition-transform hover:scale-110"
                    style="background: var(--error-container); color: var(--error)"
                    title="Eliminar imagen"
                    :disabled="deletingUrl === image.full"
                    @click.stop="emit('delete', image.full)"
                >
                    <AppSpinner v-if="deletingUrl === image.full" size="sm" />
                    <Trash2 v-else :size="14" :stroke-width="2" />
                </button>
            </div>
        </div>
    </div>
</template>
