<script setup lang="ts">
import { computed } from 'vue'
import { CheckCircle, XCircle, AlertTriangle, Info, X } from 'lucide-vue-next'
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()

const typeConfig = {
    success: {
        icon: CheckCircle,
        classes: 'bg-success-container text-success border-success/20 dark:bg-success/20 dark:text-success',
    },
    error: {
        icon: XCircle,
        classes: 'bg-error-container text-error border-error/20 dark:bg-error/20 dark:text-error',
    },
    warning: {
        icon: AlertTriangle,
        classes: 'bg-warning-container text-warning border-warning/20 dark:bg-warning/20 dark:text-warning',
    },
    info: {
        icon: Info,
        classes: 'bg-info-container text-info border-info/20 dark:bg-info/20 dark:text-info',
    },
}

const toasts = computed(() => ui.toasts)
</script>

<template>
    <Teleport to="body">
        <div
            class="fixed bottom-4 right-4 z-[100] flex flex-col gap-2 max-w-sm w-full pointer-events-none"
            aria-live="polite"
            aria-atomic="false"
        >
            <TransitionGroup
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-2 scale-95"
                enter-to-class="opacity-100 translate-y-0 scale-100"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="opacity-100 translate-y-0 scale-100"
                leave-to-class="opacity-0 translate-y-1 scale-95"
            >
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    :class="[
                        'flex items-start gap-3 px-4 py-3 rounded-xl border shadow-[var(--shadow-lifted)] pointer-events-auto',
                        typeConfig[toast.type]?.classes ?? typeConfig.info.classes,
                    ]"
                    role="alert"
                >
                    <component
                        :is="typeConfig[toast.type]?.icon ?? typeConfig.info.icon"
                        :size="16"
                        class="shrink-0 mt-0.5"
                        aria-hidden="true"
                    />
                    <p class="flex-1 text-sm font-medium">{{ toast.message }}</p>
                    <button
                        type="button"
                        class="shrink-0 opacity-70 hover:opacity-100 transition-opacity"
                        aria-label="Cerrar notificación"
                        @click="ui.removeToast(toast.id)"
                    >
                        <X :size="14" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
