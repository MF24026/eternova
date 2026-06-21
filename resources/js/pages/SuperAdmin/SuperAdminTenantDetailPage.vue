<script setup lang="ts">
/**
 * SuperAdminTenantDetailPage (7b) — a single tenant with its subscription summary, audit trail,
 * and the operator actions (extend trial / suspend / reactivate, each requiring a reason). The
 * API enforces super_admin + records the reason in the audit log. Super-admin only.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft } from 'lucide-vue-next'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import SuperAdminService from '@/services/SuperAdminService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useToast } from '@/composables/useToast'
import type { SuperAdminTenantDetail } from '@/types/domain/SuperAdmin'

type ActionKind = 'extend' | 'suspend' | 'reactivate'

const route = useRoute()
const { formatCents } = useFormatCurrency()
const toast = useToast()

const tenantId = String(route.params.id)
const loading = ref(true)
const tenant = ref<SuperAdminTenantDetail | null>(null)

const activeAction = ref<ActionKind | null>(null)
const reason = ref('')
const days = ref(7)
const submitting = ref(false)

const actionLabel = computed(() => ({
    extend: 'Extender prueba',
    suspend: 'Suspender',
    reactivate: 'Reactivar',
}[activeAction.value ?? 'suspend']))

function startAction(kind: ActionKind): void {
    activeAction.value = kind
    reason.value = ''
    days.value = 7
}

function cancelAction(): void {
    activeAction.value = null
}

function formatDate(value: string | null): string {
    if (!value) return '—'
    return new Date(value).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' })
}

async function load(): Promise<void> {
    loading.value = true
    try {
        tenant.value = await SuperAdminService.tenant(tenantId)
    } catch {
        toast.error('No se pudo cargar el tenant.')
    } finally {
        loading.value = false
    }
}

async function submitAction(): Promise<void> {
    if (activeAction.value === null) return
    if (reason.value.trim() === '') {
        toast.error('La razón es obligatoria.')
        return
    }

    submitting.value = true
    try {
        if (activeAction.value === 'extend') {
            await SuperAdminService.extendTrial(tenantId, days.value, reason.value)
        } else if (activeAction.value === 'suspend') {
            await SuperAdminService.suspend(tenantId, reason.value)
        } else {
            await SuperAdminService.reactivate(tenantId, reason.value)
        }
        toast.success('Acción aplicada.')
        activeAction.value = null
        await load()
    } catch {
        toast.error('No se pudo aplicar la acción.')
    } finally {
        submitting.value = false
    }
}

onMounted(() => {
    document.title = 'Tenant — Super Admin'
    void load()
})
</script>

<template>
    <div data-testid="superadmin-tenant-detail" class="stack" style="gap: 20px; max-width: 820px">
        <AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: 'super-admin.tenants' }">
            Volver a tenants
        </AppButton>

        <div v-if="loading" class="card" style="padding: 40px; display: flex; justify-content: center">
            <AppSpinner />
        </div>

        <template v-else-if="tenant">
            <header class="stack" style="gap: 4px">
                <div class="flex items-center gap-2">
                    <h1 class="serif" style="font-size: 1.6rem; color: var(--on-surface)">{{ tenant.name }}</h1>
                    <AppBadge variant="neutral">{{ tenant.status }}</AppBadge>
                </div>
                <p style="color: var(--on-surface-variant)">{{ tenant.slug }} · {{ tenant.email ?? 'sin email' }}</p>
            </header>

            <!-- Subscription summary -->
            <section class="card stack" style="padding: 20px; gap: 8px">
                <h2 class="serif" style="font-size: 1.05rem; color: var(--on-surface)">Suscripción</h2>
                <template v-if="tenant.subscription">
                    <div class="flex items-center gap-2">
                        <span style="color: var(--on-surface)">{{ tenant.subscription.plan ?? 'Plan' }}</span>
                        <AppBadge variant="info">{{ tenant.subscription.status }}</AppBadge>
                    </div>
                    <div style="color: var(--on-surface-variant); font-size: 0.9rem">
                        {{ formatCents(tenant.subscription.amount_cents) }}
                        · fin de prueba: {{ formatDate(tenant.subscription.trial_ends_at) }}
                        · fin de período: {{ formatDate(tenant.subscription.current_period_end) }}
                    </div>
                </template>
                <p v-else style="color: var(--on-surface-variant)">Sin suscripción activa.</p>
            </section>

            <!-- Operator actions -->
            <section class="card stack" style="padding: 20px; gap: 12px">
                <h2 class="serif" style="font-size: 1.05rem; color: var(--on-surface)">Acciones</h2>
                <div class="flex items-center gap-2" style="flex-wrap: wrap">
                    <AppButton data-testid="extend-trial-btn" variant="secondary" size="sm" @click="startAction('extend')">
                        Extender prueba
                    </AppButton>
                    <AppButton data-testid="suspend-btn" variant="danger" size="sm" @click="startAction('suspend')">
                        Suspender
                    </AppButton>
                    <AppButton data-testid="reactivate-btn" variant="secondary" size="sm" @click="startAction('reactivate')">
                        Reactivar
                    </AppButton>
                </div>

                <div
                    v-if="activeAction"
                    data-testid="action-form"
                    class="stack"
                    style="gap: 10px; padding: 16px; background: var(--tier-mid); border-radius: var(--r-lg)"
                >
                    <span style="color: var(--on-surface); font-weight: 500">{{ actionLabel }}</span>
                    <AppInput
                        v-if="activeAction === 'extend'"
                        v-model.number="days"
                        type="number"
                        data-testid="action-days"
                        placeholder="Días"
                    />
                    <AppInput
                        v-model="reason"
                        data-testid="action-reason"
                        placeholder="Razón (obligatoria)"
                    />
                    <div class="flex items-center gap-2">
                        <AppButton
                            data-testid="action-submit"
                            variant="primary"
                            size="sm"
                            :loading="submitting"
                            @click="submitAction"
                        >
                            Confirmar
                        </AppButton>
                        <AppButton variant="ghost" size="sm" :disabled="submitting" @click="cancelAction">
                            Cancelar
                        </AppButton>
                    </div>
                </div>
            </section>

            <!-- Audit log -->
            <section class="stack" style="gap: 12px">
                <h2 class="serif" style="font-size: 1.1rem; color: var(--on-surface)">Historial</h2>
                <p v-if="tenant.audit_log.length === 0" style="color: var(--on-surface-variant)">Sin eventos.</p>
                <div v-else class="card" style="padding: 8px">
                    <div
                        v-for="entry in tenant.audit_log"
                        :key="entry.id"
                        data-testid="audit-row"
                        class="flex items-center justify-between gap-3"
                        style="padding: 12px 14px"
                    >
                        <span style="color: var(--on-surface)">{{ entry.event_type }}</span>
                        <span style="color: var(--on-surface-variant); font-size: 0.85rem">{{ formatDate(entry.occurred_at) }}</span>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
