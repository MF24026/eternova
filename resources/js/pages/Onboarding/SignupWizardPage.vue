<script setup lang="ts">
import { computed, defineAsyncComponent } from 'vue'
import { useOnboardingStore } from '@/stores/onboarding'
import type { OnboardingStep } from '@/stores/onboarding'

// Lazy-load each step to reduce the initial bundle for the wizard route.
const AccountStep = defineAsyncComponent(() => import('./steps/AccountStep.vue'))
const PlanStep    = defineAsyncComponent(() => import('./steps/PlanStep.vue'))
const TenantStep  = defineAsyncComponent(() => import('./steps/TenantStep.vue'))
const DoneStep    = defineAsyncComponent(() => import('./steps/DoneStep.vue'))

const store = useOnboardingStore()

const steps: OnboardingStep[] = ['account', 'plan', 'tenant', 'done']

const currentStepIndex = computed(() => steps.indexOf(store.step))

const stepLabels: Record<OnboardingStep, string> = {
    account: 'Cuenta',
    plan: 'Plan',
    tenant: 'Negocio',
    done: 'Listo',
}
</script>

<template>
    <!-- OnboardingLayout wraps this page and provides the centered card shell.
         The layout renders a max-w-md card with the slot content inside. -->
    <div class="flex flex-col gap-6 p-6 sm:p-8">
        <!-- Step progress indicator -->
        <nav aria-label="Progreso del registro" class="flex items-center gap-2">
            <template
                v-for="(s, i) in steps"
                :key="s"
            >
                <!-- Step dot -->
                <div class="flex items-center gap-1">
                    <div
                        class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-colors duration-200"
                        :class="[
                            i < currentStepIndex
                                ? 'bg-primary text-on-primary'
                                : i === currentStepIndex
                                    ? 'bg-primary text-on-primary ring-2 ring-primary/30 ring-offset-2'
                                    : 'bg-surface-high text-on-surface-variant dark:bg-surface-mid',
                        ]"
                        :aria-current="i === currentStepIndex ? 'step' : undefined"
                    >
                        {{ i + 1 }}
                    </div>
                    <span
                        class="hidden sm:block text-xs font-medium"
                        :class="i === currentStepIndex ? 'text-on-surface' : 'text-on-surface-variant'"
                    >
                        {{ stepLabels[s] }}
                    </span>
                </div>

                <!-- Connector line between steps (all except last) -->
                <div
                    v-if="i < steps.length - 1"
                    class="flex-1 h-px transition-colors duration-300"
                    :class="i < currentStepIndex ? 'bg-primary' : 'bg-surface-high dark:bg-surface-mid'"
                />
            </template>
        </nav>

        <!-- Step content — transitions between steps -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
            mode="out-in"
        >
            <AccountStep v-if="store.step === 'account'" />
            <PlanStep    v-else-if="store.step === 'plan'" />
            <TenantStep  v-else-if="store.step === 'tenant'" />
            <DoneStep    v-else-if="store.step === 'done'" />
        </Transition>
    </div>
</template>
