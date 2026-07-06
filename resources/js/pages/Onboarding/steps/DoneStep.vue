<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { CheckCircleIcon } from 'lucide-vue-next'
import { useOnboardingStore } from '@/stores/onboarding'

const store = useOnboardingStore()

const countdown = ref(3)

// Derive the tenant URL from the current host, replacing the leftmost label.
// - On production: tenant.eternova.app
// - On local dev:  tenant.eternova.localhost:8080
// This avoids hardcoding the host so it works in any environment.
const tenantUrl = computed(() => {
    const slug = store.createdTenantSlug ?? ''
    const { protocol, host } = window.location

    // Swap the leftmost hostname segment for the new tenant slug.
    // If the host has no dots (e.g. "localhost"), prefix it directly.
    const parts = host.split('.')
    const newHost = parts.length > 1
        ? [slug, ...parts.slice(1)].join('.')
        : `${slug}.${host}`

    return `${protocol}//${newHost}/admin/dashboard`
})

onMounted(() => {
    const interval = setInterval(() => {
        countdown.value -= 1
        if (countdown.value <= 0) {
            clearInterval(interval)
            window.location.href = tenantUrl.value
        }
    }, 1000)
})
</script>

<template>
    <div class="flex flex-col items-center gap-6 py-4 text-center">
        <div class="w-16 h-16 rounded-full bg-primary-container flex items-center justify-center">
            <CheckCircleIcon :size="32" class="text-primary" />
        </div>

        <div>
            <h2 class="font-serif text-2xl font-semibold text-on-surface tracking-tighter">
                Listo, tu negocio esta en linea
            </h2>
            <p class="mt-2 text-sm text-on-surface-variant">
                Redirigiendo en {{ countdown }}...
            </p>
        </div>

        <div v-if="store.createdTenantSlug" class="w-full rounded-xl bg-surface-low p-4 dark:bg-surface-mid">
            <p class="text-xs text-on-surface-variant mb-1">Tu panel de administración:</p>
            <p class="text-sm font-medium text-primary break-all">{{ tenantUrl }}</p>
        </div>

        <p class="text-xs text-on-surface-variant">
            Si no eres redirigido automáticamente,
            <a
                :href="tenantUrl"
                class="text-primary hover:underline underline-offset-2"
            >
                haz clic aquí
            </a>.
        </p>
    </div>
</template>
