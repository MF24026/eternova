import { useAuthStore } from '@/stores/auth'
import { storeToRefs } from 'pinia'

/**
 * Convenience composable for auth state.
 * Components import this instead of reaching into the store directly,
 * keeping the store implementation detail behind a consistent API.
 */
export function useAuth() {
    const store = useAuthStore()
    const { currentUser, isAuthenticated, isSuperAdmin, isLoading } = storeToRefs(store)
    const { login, logout, fetchMe, clearLocal } = store

    return {
        currentUser,
        isAuthenticated,
        isSuperAdmin,
        isLoading,
        login,
        logout,
        fetchMe,
        clearLocal,
    }
}
