<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'
import AppHeader from '@/components/layout/AppHeader.vue'
import AppCard from '@/components/base/AppCard.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'

const router = useRouter()
const { currentUser, isLoading, logout } = useAuth()

async function handleLogout() {
    await logout()
    await router.push({ name: 'login' })
}
</script>

<template>
    <div class="min-h-screen bg-surface">
        <AppHeader />

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div v-if="isLoading" class="flex justify-center py-20">
                <AppSpinner size="lg" />
            </div>

            <template v-else-if="currentUser">
                <div class="mb-8">
                    <h1 class="font-serif text-2xl font-semibold text-on-surface tracking-tighter">
                        Bienvenido, {{ currentUser.name }}
                    </h1>
                    <p class="text-on-surface-variant text-sm mt-1">
                        {{ currentUser.email }}
                    </p>
                </div>

                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 mb-8">
                    <AppCard v-if="currentUser.tenants.length === 0">
                        <p class="text-sm text-on-surface-variant">
                            No tienes negocios configurados aun.
                        </p>
                    </AppCard>

                    <AppCard
                        v-for="tenant in currentUser.tenants"
                        :key="tenant.id"
                    >
                        <h3 class="font-medium text-on-surface mb-1">
                            {{ tenant.business_name }}
                        </h3>
                        <p class="text-xs text-on-surface-variant capitalize">
                            Rol: {{ tenant.role }}
                        </p>
                    </AppCard>
                </div>

                <AppButton variant="secondary" @click="handleLogout">
                    Cerrar sesion
                </AppButton>
            </template>
        </main>
    </div>
</template>
