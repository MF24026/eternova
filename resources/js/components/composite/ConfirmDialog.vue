<script setup lang="ts">
/**
 * ConfirmDialog — single host for the useConfirm() promise-based confirmation.
 *
 * Mount once near the root of an authenticated layout. It renders the shared
 * confirm state on top of AppModal (Escape / backdrop = cancel). Do not place
 * more than one host per app.
 */
import { nextTick, watch } from 'vue'
import { AlertTriangle } from 'lucide-vue-next'
import AppModal from '@/components/base/AppModal.vue'
import AppButton from '@/components/base/AppButton.vue'
import { useConfirmHost } from '@/composables/useConfirm'

const { state, settle } = useConfirmHost()

// Focus the confirm action when the dialog opens so Enter confirms and the
// focus ring is visible (keyboard accessibility). AppModal teleports to body,
// so query the rendered button rather than holding a component ref.
watch(() => state.open, (open) => {
    if (!open) return
    void nextTick(() => {
        document.querySelector<HTMLElement>('[data-testid="confirm-accept"]')?.focus()
    })
})
</script>

<template>
    <AppModal
        :model-value="state.open"
        :title="state.title"
        max-width="sm"
        @update:model-value="settle(false)"
    >
        <div class="flex items-start gap-3">
            <span
                v-if="state.variant === 'danger'"
                class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center"
                style="background: color-mix(in srgb, var(--error) 14%, transparent); color: var(--error)"
                aria-hidden="true"
            >
                <AlertTriangle :size="18" />
            </span>
            <p v-if="state.message" class="text-sm text-on-surface-variant leading-relaxed" data-testid="confirm-message">
                {{ state.message }}
            </p>
        </div>

        <template #footer>
            <AppButton variant="secondary" size="sm" data-testid="confirm-cancel" @click="settle(false)">
                {{ state.cancelLabel }}
            </AppButton>
            <AppButton
                :variant="state.variant === 'danger' ? 'danger' : 'primary'"
                size="sm"
                data-testid="confirm-accept"
                @click="settle(true)"
            >
                {{ state.confirmLabel }}
            </AppButton>
        </template>
    </AppModal>
</template>
