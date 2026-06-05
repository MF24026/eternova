<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { LogOut, ChevronDown, User } from 'lucide-vue-next'
import { useAuth } from '@/composables/useAuth'
import AppSpinner from '@/components/base/AppSpinner.vue'

const router = useRouter()
const { currentUser, isAuthenticated, logout, isLoading } = useAuth()

const menuOpen = ref(false)
const signingOut = ref(false)

async function handleLogout() {
    signingOut.value = true
    menuOpen.value = false
    try {
        await logout()
        await router.push({ name: 'login' })
    } finally {
        signingOut.value = false
    }
}
</script>

<template>
    <header
        class="sticky top-0 z-30 w-full bg-surface-lowest/80 backdrop-blur-[24px] border-b border-outline-variant dark:bg-surface-low/80"
    >
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">
            <router-link
                :to="{ name: 'home' }"
                class="font-serif font-semibold text-primary tracking-tighter text-lg"
            >
                Eternova
            </router-link>

            <nav v-if="isAuthenticated" class="relative">
                <button
                    type="button"
                    class="flex items-center gap-2 text-sm text-on-surface hover:text-primary transition-colors focus-visible:outline-none"
                    @click="menuOpen = !menuOpen"
                >
                    <span class="hidden sm:inline">{{ currentUser?.name }}</span>
                    <User class="w-5 h-5 text-on-surface-variant" aria-hidden="true" />
                    <ChevronDown
                        class="w-4 h-4 text-on-surface-variant transition-transform"
                        :class="menuOpen ? 'rotate-180' : ''"
                        aria-hidden="true"
                    />
                </button>

                <div
                    v-if="menuOpen"
                    class="absolute right-0 mt-2 w-48 rounded-xl bg-surface-lowest shadow-[var(--shadow-lifted)] py-1 dark:bg-surface-mid"
                    role="menu"
                >
                    <button
                        type="button"
                        class="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-on-surface hover:bg-surface-low transition-colors dark:hover:bg-surface-high"
                        role="menuitem"
                        :disabled="signingOut"
                        @click="handleLogout"
                    >
                        <AppSpinner v-if="signingOut" size="sm" />
                        <LogOut v-else class="w-4 h-4" aria-hidden="true" />
                        Cerrar sesion
                    </button>
                </div>
            </nav>

            <div v-else-if="isLoading">
                <AppSpinner size="sm" />
            </div>

            <div v-else class="flex items-center gap-3">
                <router-link
                    :to="{ name: 'login' }"
                    class="text-sm text-primary hover:underline"
                >
                    Iniciar sesion
                </router-link>
                <router-link
                    :to="{ name: 'signup' }"
                    class="text-sm bg-primary text-on-primary px-4 py-2 rounded-full hover:bg-primary-dim transition-colors"
                >
                    Crear cuenta
                </router-link>
            </div>
        </div>
    </header>
</template>
