<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { CheckIcon, ZapIcon, BuildingIcon } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import PlansService from '@/services/PlansService'
import { useOnboardingStore } from '@/stores/onboarding'
import type { Plan } from '@/types/domain/Plan'

const store = useOnboardingStore()

const plans = ref<Plan[]>([])
const loading = ref(true)
const billing = ref<'monthly' | 'yearly'>(store.planChoice.billing)
const selectedSlug = ref<'basico' | 'pro' | 'enterprise'>(store.planChoice.plan_slug)

onMounted(async () => {
    try {
        plans.value = await PlansService.list()
    } finally {
        loading.value = false
    }
})

function formatPrice(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`
}

function planPrice(plan: Plan): number {
    return billing.value === 'yearly' ? plan.price_yearly_cents / 12 : plan.price_monthly_cents
}

const yearlySavingsPercent = computed(() => 17)

function selectPlan(slug: 'basico' | 'pro' | 'enterprise'): void {
    selectedSlug.value = slug
}

function handleNext(): void {
    store.planChoice = { plan_slug: selectedSlug.value, billing: billing.value }
    store.goTo('tenant')
}

function handleBack(): void {
    store.goTo('account')
}

const planIcons: Record<string, typeof CheckIcon> = {
    basico: ZapIcon,
    pro: CheckIcon,
    enterprise: BuildingIcon,
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <h2 class="font-serif text-2xl font-semibold text-on-surface tracking-tighter">
                Elige tu plan
            </h2>
            <p class="mt-1 text-sm text-on-surface-variant">
                Todos los planes incluyen 14 días de prueba gratuita.
            </p>
        </div>

        <!-- Billing toggle -->
        <div class="flex items-center justify-center gap-3">
            <span
                class="text-sm font-medium"
                :class="billing === 'monthly' ? 'text-on-surface' : 'text-on-surface-variant'"
            >
                Mensual
            </span>
            <button
                type="button"
                role="switch"
                :aria-checked="billing === 'yearly'"
                class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                :class="billing === 'yearly' ? 'bg-primary' : 'bg-surface-high dark:bg-surface-mid'"
                @click="billing = billing === 'monthly' ? 'yearly' : 'monthly'"
            >
                <span
                    class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                    :class="billing === 'yearly' ? 'translate-x-6' : 'translate-x-1'"
                />
            </button>
            <span
                class="text-sm font-medium"
                :class="billing === 'yearly' ? 'text-on-surface' : 'text-on-surface-variant'"
            >
                Anual
                <span class="ml-1 text-xs font-semibold text-primary">-{{ yearlySavingsPercent }}%</span>
            </span>
        </div>

        <!-- Plans loading state -->
        <div v-if="loading" class="flex justify-center py-8">
            <AppSpinner size="lg" />
        </div>

        <!-- Plan cards -->
        <div v-else class="flex flex-col gap-3">
            <button
                v-for="plan in plans"
                :key="plan.slug"
                type="button"
                :aria-pressed="selectedSlug === plan.slug"
                class="relative text-left rounded-xl p-4 border-2 transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                :class="[
                    selectedSlug === plan.slug
                        ? 'border-primary bg-primary-container/20 dark:bg-primary/10'
                        : 'border-surface-high bg-surface-lowest hover:border-primary/40 dark:border-surface-mid dark:bg-surface-low',
                ]"
                @click="selectPlan(plan.slug)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <div
                            class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center"
                            :class="selectedSlug === plan.slug ? 'bg-primary text-on-primary' : 'bg-surface-high text-on-surface-variant'"
                        >
                            <component :is="planIcons[plan.slug] ?? CheckIcon" :size="16" />
                        </div>
                        <div>
                            <div class="font-semibold text-sm text-on-surface">{{ plan.name }}</div>
                            <div class="text-xs text-on-surface-variant line-clamp-1">{{ plan.description }}</div>
                        </div>
                    </div>

                    <div class="flex-shrink-0 text-right">
                        <div class="font-semibold text-on-surface text-sm">
                            {{ formatPrice(planPrice(plan)) }}
                            <span class="text-xs font-normal text-on-surface-variant">/mes</span>
                        </div>
                        <div v-if="billing === 'yearly'" class="text-xs text-on-surface-variant">
                            {{ formatPrice(plan.price_yearly_cents) }}/yr
                        </div>
                    </div>
                </div>

                <!-- Feature list (collapsed to top 4) -->
                <ul class="mt-3 flex flex-col gap-1">
                    <li
                        v-for="feature in plan.features.slice(0, 4)"
                        :key="feature"
                        class="flex items-center gap-1.5 text-xs text-on-surface-variant"
                    >
                        <CheckIcon :size="12" class="flex-shrink-0 text-primary" aria-hidden="true" />
                        {{ feature }}
                    </li>
                    <li
                        v-if="plan.features.length > 4"
                        class="text-xs text-primary font-medium ml-[18px]"
                    >
                        +{{ plan.features.length - 4 }} mas...
                    </li>
                </ul>

                <!-- Selected indicator -->
                <div
                    v-if="selectedSlug === plan.slug"
                    class="absolute top-3 right-3 w-5 h-5 rounded-full bg-primary flex items-center justify-center"
                    aria-hidden="true"
                >
                    <CheckIcon :size="12" class="text-on-primary" />
                </div>
            </button>
        </div>

        <div class="flex gap-3">
            <AppButton
                variant="secondary"
                class="flex-1"
                @click="handleBack"
            >
                Atras
            </AppButton>
            <AppButton
                class="flex-1"
                :disabled="!selectedSlug"
                @click="handleNext"
            >
                Continuar
            </AppButton>
        </div>
    </div>
</template>
