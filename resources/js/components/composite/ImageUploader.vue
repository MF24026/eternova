<script setup lang="ts">
import { ref } from 'vue'
import { Upload, ImagePlus } from 'lucide-vue-next'
import AppSpinner from '@/components/base/AppSpinner.vue'

interface Props {
    accept?: string
    disabled?: boolean
    isUploading?: boolean
    uploadProgress?: number
    uploadError?: string | null
}

const props = withDefaults(defineProps<Props>(), {
    accept:         'image/jpeg,image/png,image/webp',
    disabled:       false,
    isUploading:    false,
    uploadProgress: 0,
    uploadError:    null,
})

const emit = defineEmits<{
    (e: 'select', file: File): void
}>()

const isDragging = ref(false)
const fileInput  = ref<HTMLInputElement | null>(null)

function openFilePicker(): void {
    if (props.disabled || props.isUploading) return
    fileInput.value?.click()
}

function handleFileInput(event: Event): void {
    const input = event.target as HTMLInputElement
    const file  = input.files?.[0]
    if (file) {
        emit('select', file)
        // Reset input so the same file can be re-selected after a failed upload.
        input.value = ''
    }
}

function handleDrop(event: DragEvent): void {
    isDragging.value = false
    if (props.disabled || props.isUploading) return

    const file = event.dataTransfer?.files?.[0]
    if (file) {
        emit('select', file)
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <!-- Drop zone -->
        <button
            type="button"
            :class="[
                'flex flex-col items-center justify-center gap-3 w-full rounded-xl border-2 border-dashed',
                'transition-colors duration-150 cursor-pointer',
                isDragging ? 'border-primary bg-primary/5' : 'border-outline-variant',
                (disabled || isUploading) ? 'opacity-50 cursor-not-allowed' : 'hover:border-primary hover:bg-surface-low',
            ]"
            style="min-height: 9rem; background: var(--surface-low)"
            :disabled="disabled || isUploading"
            @click="openFilePicker"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="handleDrop"
        >
            <template v-if="isUploading">
                <AppSpinner size="lg" />
                <div class="flex flex-col items-center gap-1">
                    <span class="text-sm text-on-surface">Subiendo...</span>
                    <div
                        class="h-1.5 rounded-full overflow-hidden"
                        style="width: 8rem; background: var(--outline-variant)"
                    >
                        <div
                            class="h-full rounded-full transition-all duration-200"
                            style="background: var(--primary)"
                            :style="{ width: `${uploadProgress}%` }"
                        />
                    </div>
                    <span class="text-xs text-on-surface-variant">{{ uploadProgress }}%</span>
                </div>
            </template>

            <template v-else>
                <div
                    class="w-12 h-12 rounded-xl flex items-center justify-center"
                    style="background: var(--primary-container)"
                >
                    <ImagePlus :size="22" style="color: var(--primary)" />
                </div>
                <div class="flex flex-col items-center gap-0.5">
                    <span class="text-sm font-medium text-on-surface">
                        Arrastra una imagen o haz clic
                    </span>
                    <span class="text-xs text-on-surface-variant">
                        JPEG, PNG o WebP — max 5 MB — min 400 px
                    </span>
                </div>
                <div
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                    style="background: var(--primary); color: var(--on-primary)"
                >
                    <Upload :size="13" />
                    Seleccionar archivo
                </div>
            </template>
        </button>

        <!-- Error message -->
        <p v-if="uploadError" class="text-xs" style="color: var(--error)">
            {{ uploadError }}
        </p>

        <!-- Hidden file input -->
        <input
            ref="fileInput"
            type="file"
            class="hidden"
            :accept="accept"
            @change="handleFileInput"
        />
    </div>
</template>
