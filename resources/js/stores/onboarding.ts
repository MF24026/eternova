import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export interface AccountData {
    name: string
    email: string
    password: string
}

export interface PlanChoice {
    plan_slug: 'basico' | 'pro' | 'enterprise'
    billing: 'monthly' | 'yearly'
}

export type StarterTemplate = 'floreria' | 'accesorios' | 'peluches' | 'reposteria'

export interface TenantData {
    slug: string
    name: string
    business_name: string
    country_code: string
    currency: string
    language: string
    timezone: string
    starter_template: StarterTemplate
}

export type OnboardingStep = 'account' | 'plan' | 'tenant' | 'done'

export const useOnboardingStore = defineStore('onboarding', () => {
    const step = ref<OnboardingStep>('account')

    const account = ref<AccountData>({ name: '', email: '', password: '' })

    const planChoice = ref<PlanChoice>({ plan_slug: 'basico', billing: 'monthly' })

    const tenantData = ref<TenantData>({
        slug: '',
        name: '',
        business_name: '',
        country_code: 'SV',
        currency: 'USD',
        language: 'es',
        timezone: 'America/El_Salvador',
        starter_template: 'floreria',
    })

    const createdTenantSlug = ref<string | null>(null)
    const tempBearerToken = ref<string | null>(null)
    const isSubmitting = ref(false)
    const errors = ref<Record<string, string[]>>({})

    const canGoNextFromAccount = computed(() =>
        account.value.name.length >= 2 &&
        account.value.email.includes('@') &&
        account.value.password.length >= 8,
    )

    function reset(): void {
        step.value = 'account'
        account.value = { name: '', email: '', password: '' }
        planChoice.value = { plan_slug: 'basico', billing: 'monthly' }
        tenantData.value = {
            slug: '',
            name: '',
            business_name: '',
            country_code: 'SV',
            currency: 'USD',
            language: 'es',
            timezone: 'America/El_Salvador',
            starter_template: 'floreria',
        }
        createdTenantSlug.value = null
        tempBearerToken.value = null
        isSubmitting.value = false
        errors.value = {}
    }

    function goTo(next: OnboardingStep): void {
        step.value = next
    }

    return {
        step,
        account,
        planChoice,
        tenantData,
        createdTenantSlug,
        tempBearerToken,
        isSubmitting,
        errors,
        canGoNextFromAccount,
        reset,
        goTo,
    }
})
