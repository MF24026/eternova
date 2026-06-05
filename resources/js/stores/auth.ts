import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import AuthService from '@/services/AuthService'
import type { User } from '@/types/domain/User'

export const useAuthStore = defineStore('auth', () => {
    const currentUser = ref<User | null>(null)
    const isLoading = ref(false)

    const isAuthenticated = computed(() => currentUser.value !== null)
    const isSuperAdmin = computed(() => currentUser.value?.is_super_admin === true)

    async function fetchMe(): Promise<void> {
        isLoading.value = true
        try {
            currentUser.value = await AuthService.me()
        } catch {
            currentUser.value = null
        } finally {
            isLoading.value = false
        }
    }

    async function login(email: string, password: string): Promise<void> {
        await AuthService.login(email, password)
        await fetchMe()
    }

    async function logout(): Promise<void> {
        try {
            await AuthService.logout()
        } finally {
            clearLocal()
        }
    }

    function clearLocal(): void {
        currentUser.value = null
    }

    return {
        currentUser,
        isLoading,
        isAuthenticated,
        isSuperAdmin,
        fetchMe,
        login,
        logout,
        clearLocal,
    }
})
