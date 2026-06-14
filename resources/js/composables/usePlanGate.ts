import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * Plan-gating helper. Reads the current tenant's plan entitlements (loaded into
 * the auth store from /me) and answers whether a given limit/feature is allowed.
 *
 * Entitlement keys live in plan.limits (see PlansSeeder): booleans like
 * `pdf_quotations` / `custom_domain`, and numeric caps like `max_branches`
 * (null = unlimited).
 *
 * Doctrine (saas-plan-gating-billing, Pattern B): gate the UI but keep locked
 * features VISIBLE with an upgrade CTA — never hide them. Use this composable to
 * decide `locked`, then wrap the feature in <UpgradeLock>.
 */
export function usePlanGate() {
    const auth = useAuthStore()

    const plan = computed(() => auth.currentUser?.plan ?? null)
    const planSlug = computed(() => plan.value?.slug ?? null)

    /**
     * Whether the plan grants a given entitlement key.
     * true boolean / positive number / null (unlimited) → allowed.
     * Missing key, false, or 0 → not allowed.
     */
    function allows(key: string): boolean {
        const limits = plan.value?.limits
        if (!limits || !(key in limits)) return false

        const value = limits[key]
        if (value === null) return true // unlimited
        if (typeof value === 'boolean') return value
        if (typeof value === 'number') return value > 0
        return Boolean(value)
    }

    /** Raw limit value for a key (number cap, boolean flag, null = unlimited, undefined = unknown). */
    function limit(key: string): number | boolean | null | undefined {
        return plan.value?.limits?.[key]
    }

    return { plan, planSlug, allows, limit }
}
