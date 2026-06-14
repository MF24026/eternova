<script setup lang="ts">
/**
 * UpgradeLock — plan-gating wrapper (Pattern B: visible but locked).
 *
 * Renders its slot content normally when unlocked. When `locked`, the content is
 * dimmed + non-interactive and an overlay with a lock icon, the required plan,
 * and an upgrade CTA is shown — the feature stays VISIBLE to drive conversion.
 */
import { Lock } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'

defineProps<{
    locked: boolean
    title?: string
    requiredPlan?: string
}>()

const emit = defineEmits<{ upgrade: [] }>()
</script>

<template>
    <div class="relative" data-testid="upgrade-lock">
        <div :class="locked ? 'opacity-40 pointer-events-none select-none blur-[1px]' : ''" :aria-hidden="locked || undefined">
            <slot />
        </div>

        <div
            v-if="locked"
            class="absolute inset-0 flex flex-col items-center justify-center text-center gap-2 rounded-xl p-4"
            style="background: color-mix(in srgb, var(--surface-lowest) 78%, transparent)"
            data-testid="upgrade-overlay"
        >
            <span class="w-10 h-10 rounded-full flex items-center justify-center" style="background: var(--primary-container); color: var(--primary)">
                <Lock :size="18" aria-hidden="true" />
            </span>
            <p v-if="title" class="text-sm font-semibold text-on-surface">{{ title }}</p>
            <p class="text-xs text-on-surface-variant">
                Disponible en el plan
                <span class="font-semibold text-primary">{{ requiredPlan ?? 'superior' }}</span>
            </p>
            <AppButton variant="primary" size="sm" data-testid="upgrade-cta" @click="emit('upgrade')">
                Mejorar plan
            </AppButton>
        </div>
    </div>
</template>
