import type { NavigationGuardReturn } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

let bootDone = false

export async function authGuard(
    to: Parameters<import('vue-router').NavigationGuard>[0],
): Promise<NavigationGuardReturn> {
    const auth = useAuthStore()

    // On the first navigation of the session, attempt to hydrate the current user
    // from the server (cookie-based session may already exist).
    if (!bootDone) {
        await auth.fetchMe()
        bootDone = true
    }

    if (to.meta.requiresAuth === true && !auth.isAuthenticated) {
        return { name: 'login', query: { redirect: to.fullPath } }
    }

    if (to.meta.guestOnly === true && auth.isAuthenticated) {
        return { name: 'admin.dashboard' }
    }

    return undefined
}
