<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { X } from 'lucide-vue-next'
import { useSwipeToClose } from '@/composables/useSwipeToClose'

interface Props {
    modelValue: boolean
    title: string
    subtitle?: string
    side?: 'right' | 'left'
    width?: string
    /** Optional data-testid forwarded to the panel dialog element. */
    testId?: string
}

const props = withDefaults(defineProps<Props>(), {
    subtitle: '',
    side: 'right',
    width: '480px',
    testId: undefined,
})

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
}>()

const panel = ref<HTMLElement | null>(null)

function close(): void {
    emit('update:modelValue', false)
}

// Swipe-to-close on the panel (right panel: swipe right; left panel: swipe left).
useSwipeToClose(panel, props.side, close)

// Lock body scroll while open
watch(
    () => props.modelValue,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : ''
    },
)

// Escape key closes
function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape' && props.modelValue) close()
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
})

// Mobile: full-width bottom sheet (bottom-0 left-0 right-0).
// Desktop (md+): dock to one edge. The md:left-auto / md:right-auto is essential —
// without it the leftover mobile `left-0`/`right-0` combines with the explicit
// width and pins the panel to the wrong (opposite) edge.
const slideClasses = {
    right: 'md:left-auto md:right-0 md:top-0 md:bottom-0 rounded-t-2xl md:rounded-t-none md:rounded-l-2xl bottom-0 left-0 right-0',
    left: 'md:right-auto md:left-0 md:top-0 md:bottom-0 rounded-t-2xl md:rounded-t-none md:rounded-r-2xl bottom-0 left-0 right-0',
}

const enterFrom = {
    right: 'md:translate-x-full translate-y-full md:translate-y-0',
    left: 'md:-translate-x-full translate-y-full md:translate-y-0',
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="duration-300 ease-out"
            leave-active-class="duration-200 ease-in"
        >
            <!-- z above the admin sidebar (z-80) so the backdrop covers the whole app. -->
            <div v-if="modelValue" class="fixed inset-0 z-[90] overflow-hidden">
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-on-surface/30 backdrop-blur-sm"
                    aria-hidden="true"
                    @click="close"
                />

                <!-- Panel -->
                <Transition
                    :enter-from-class="enterFrom[side]"
                    enter-to-class="translate-x-0 translate-y-0"
                    :leave-to-class="enterFrom[side]"
                    enter-active-class="transition-transform duration-300 ease-out"
                    leave-active-class="transition-transform duration-200 ease-in"
                >
                    <div
                        v-if="modelValue"
                        ref="panel"
                        :class="[
                            'absolute max-h-[90vh] md:max-h-none',
                            'bg-surface-lowest dark:bg-surface-low',
                            'shadow-[var(--shadow-lifted)]',
                            'overflow-hidden flex flex-col',
                            slideClasses[side],
                        ]"
                        :style="{ width: `min(${width}, 100vw)` }"
                        role="dialog"
                        :aria-label="title"
                        :data-testid="testId"
                    >
                        <!-- Drag handle (mobile) -->
                        <div class="md:hidden flex justify-center pt-3 pb-1 shrink-0">
                            <div class="w-10 h-1 rounded-full bg-outline-soft" aria-hidden="true" />
                        </div>

                        <!-- Header — separated from the body by a tonal shift, not a 1px line (No-Line Rule) -->
                        <header class="flex items-start justify-between gap-3 px-5 py-4 shrink-0 bg-surface-low dark:bg-surface-mid">
                            <div class="min-w-0">
                                <h2 class="font-serif text-xl text-on-surface tracking-tighter truncate">
                                    {{ title }}
                                </h2>
                                <p v-if="subtitle" class="text-xs text-on-surface-variant mt-0.5">
                                    {{ subtitle }}
                                </p>
                            </div>
                            <button
                                type="button"
                                class="btn-icon shrink-0 mt-0.5"
                                aria-label="Cerrar"
                                @click="close"
                            >
                                <X :size="18" />
                            </button>
                        </header>

                        <!-- Body -->
                        <div class="flex-1 overflow-y-auto p-5">
                            <slot />
                        </div>

                        <!-- Footer slot (optional) -->
                        <footer
                            v-if="$slots.footer"
                            class="shrink-0 px-5 py-4 bg-surface-low dark:bg-surface-mid"
                        >
                            <slot name="footer" />
                        </footer>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
