<script setup lang="ts">
/**
 * SuperAdminTenantsPage (7b) — every tenant on the platform with its status + current
 * subscription. Rows link to the detail page. Super-admin only.
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import SuperAdminService from '@/services/SuperAdminService'
import { useToast } from '@/composables/useToast'
import type { SuperAdminTenantRow } from '@/types/domain/SuperAdmin'

const router = useRouter()
const toast = useToast()

const loading = ref(true)
const tenants = ref<SuperAdminTenantRow[]>([])

function statusVariant(status: string): 'success' | 'info' | 'warning' | 'error' | 'neutral' {
    switch (status) {
        case 'active': return 'success'
        case 'trialing': return 'info'
        case 'past_due': return 'warning'
        case 'suspended': return 'error'
        default: return 'neutral'
    }
}

function openTenant(id: string): void {
    void router.push({ name: 'super-admin.tenants.detail', params: { id } })
}

async function load(): Promise<void> {
    loading.value = true
    try {
        tenants.value = await SuperAdminService.tenants()
    } catch {
        toast.error('No se pudo cargar la lista de tenants.')
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    document.title = 'Tenants — Super Admin'
    void load()
})
</script>

<template>
    <div data-testid="superadmin-tenants-page" class="stack" style="gap: 20px">
        <header>
            <h1 class="serif" style="font-size: 1.6rem; color: var(--on-surface)">Tenants</h1>
            <p style="color: var(--on-surface-variant)">Todos los negocios de la plataforma.</p>
        </header>

        <div v-if="loading" class="card" style="padding: 40px; display: flex; justify-content: center">
            <AppSpinner />
        </div>

        <AppEmptyState
            v-else-if="tenants.length === 0"
            title="Sin tenants"
            description="Todavía no hay negocios registrados en la plataforma."
        />

        <div v-else class="card" style="padding: 8px">
            <button
                v-for="tenant in tenants"
                :key="tenant.id"
                type="button"
                data-testid="tenant-row"
                class="flex items-center justify-between gap-3 w-full text-left"
                style="padding: 14px; border-radius: var(--r-lg); background: transparent"
                @click="openTenant(tenant.id)"
            >
                <div class="stack" style="gap: 2px">
                    <span style="color: var(--on-surface); font-weight: 500">{{ tenant.name }}</span>
                    <span style="color: var(--on-surface-variant); font-size: 0.85rem">{{ tenant.slug }}</span>
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="tenant.subscription" style="color: var(--on-surface-variant); font-size: 0.85rem">
                        {{ tenant.subscription.plan ?? 'Plan' }}
                    </span>
                    <AppBadge :variant="statusVariant(tenant.subscription?.status ?? tenant.status)">
                        {{ tenant.subscription?.status ?? tenant.status }}
                    </AppBadge>
                </div>
            </button>
        </div>
    </div>
</template>
