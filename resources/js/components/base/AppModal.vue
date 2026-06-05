<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue'
import { X } from 'lucide-vue-next'

interface Props {
    modelValue: boolean
    title: string
    maxWidth?: 'sm' | 'md' | 'lg' | 'xl'
}

const props = withDefaults(defineProps<Props>(), {
    maxWidth: 'md',
})

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
}>()

const maxWidthClasses: Record<NonNullable<Props['maxWidth']>, string> = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
}

function close(): void {
    emit('update:modelValue', false)
}

watch(
    () => props.modelValue,
    (open) => { document.body.style.overflow = open ? 'hidden' : '' },
)

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && props.modelValue) close()
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
})
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modelValue"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
            >
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-on-surface/30 backdrop-blur-sm"
                    aria-hidden="true"
                    @click="close"
                />

                <!-- Dialog -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition-all duration-150 ease-in"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="modelValue"
                        :class="[
                            'relative w-full bg-surface-lowest dark:bg-surface-low rounded-2xl',
                            'shadow-[var(--shadow-lifted)] overflow-hidden',
                            maxWidthClasses[maxWidth],
                        ]"
                        role="dialog"
                        aria-modal="true"
                        :aria-label="title"
                    >
                        <!-- Header -->
                        <header class="flex items-center justify-between gap-3 px-6 pt-5 pb-4">
                            <h2 class="font-serif text-xl text-on-surface tracking-tighter">
                                {{ title }}
                            </h2>
                            <button
                                type="button"
                                class="btn-icon"
                                aria-label="Cerrar"
                                @click="close"
                            >
                                <X :size="18" />
                            </button>
                        </header>

                        <!-- Body -->
                        <div class="px-6 pb-4">
                            <slot />
                        </div>

                        <!-- Footer -->
                        <footer
                            v-if="$slots.footer"
                            class="px-6 py-4 bg-surface-low dark:bg-surface-mid flex justify-end gap-3"
                        >
                            <slot name="footer" />
                        </footer>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
