<script setup lang="ts">
/**
 * SuperAdminMetricsPage (7b) — platform billing dashboard: MRR, ARPU, tenants, churn, plus
 * subscription counts by status and the active-plan distribution. Super-admin only (the API 403s
 * the rest; the route guard bounces non-super users).
 */
import { ref, computed, onMounted } from 'vue'
import { TrendingUp, Users, Activity, Percent } from 'lucide-vue-next'
import AppSpinner from '@/components/base/AppSpinner.vue'
import SuperAdminService from '@/services/SuperAdminService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useToast } from '@/composables/useToast'
import type { BillingMetrics } from '@/types/domain/SuperAdmin'

const { formatCents } = useFormatCurrency()
const toast = useToast()

const loading = ref(true)
const metrics = ref<BillingMetrics | null>(null)

const churnPercent = computed(() =>
    metrics.value ? `${(metrics.value.churn_rate * 100).toFixed(1)}%` : '—',
)
const statusEntries = computed(() => Object.entries(metrics.value?.counts_by_status ?? {}))
const planEntries = computed(() => Object.entries(metrics.value?.plan_distribution ?? {}))

async function load(): Promise<void> {
    loading.value = true
    try {
        metrics.value = await SuperAdminService.metrics()
    } catch {
        toast.error('No se pudieron cargar las métricas.')
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    document.title = 'Métricas — Super Admin'
    void load()
})
</script>

<template>
    <div data-testid="superadmin-metrics-page" class="stack" style="gap: 20px">
        <header>
            <h1 class="serif" style="font-size: 1.6rem; color: var(--on-surface)">Métricas</h1>
            <p style="color: var(--on-surface-variant)">Salud de facturación de la plataforma.</p>
        </header>

        <div v-if="loading" class="card" style="padding: 40px; display: flex; justify-content: center">
            <AppSpinner />
        </div>

        <template v-else-if="metrics">
            <!-- KPI cards -->
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px">
                <div class="card stack" style="padding: 20px; gap: 6px">
                    <div class="flex items-center gap-2" style="color: var(--on-surface-variant)">
                        <TrendingUp :size="16" /><span style="font-size: 0.85rem">MRR</span>
                    </div>
                    <span class="serif" style="font-size: 1.5rem; color: var(--on-surface)">{{ formatCents(metrics.mrr_cents) }}</span>
                </div>
                <div class="card stack" style="padding: 20px; gap: 6px">
                    <div class="flex items-center gap-2" style="color: var(--on-surface-variant)">
                        <Activity :size="16" /><span style="font-size: 0.85rem">ARPU</span>
                    </div>
                    <span class="serif" style="font-size: 1.5rem; color: var(--on-surface)">{{ formatCents(metrics.arpu_cents) }}</span>
                </div>
                <div class="card stack" style="padding: 20px; gap: 6px">
                    <div class="flex items-center gap-2" style="color: var(--on-surface-variant)">
                        <Users :size="16" /><span style="font-size: 0.85rem">Tenants</span>
                    </div>
                    <span class="serif" style="font-size: 1.5rem; color: var(--on-surface)">{{ metrics.total_tenants }}</span>
                </div>
                <div class="card stack" style="padding: 20px; gap: 6px">
                    <div class="flex items-center gap-2" style="color: var(--on-surface-variant)">
                        <Percent :size="16" /><span style="font-size: 0.85rem">Churn</span>
                    </div>
                    <span class="serif" style="font-size: 1.5rem; color: var(--on-surface)">{{ churnPercent }}</span>
                </div>
            </div>

            <!-- Breakdown blocks -->
            <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px">
                <section class="card stack" style="padding: 20px; gap: 10px">
                    <h2 class="serif" style="font-size: 1.05rem; color: var(--on-surface)">Suscripciones por estado</h2>
                    <p v-if="statusEntries.length === 0" style="color: var(--on-surface-variant)">Sin datos.</p>
                    <div
                        v-for="[status, count] in statusEntries"
                        :key="status"
                        class="flex items-center justify-between"
                        style="padding: 4px 0"
                    >
                        <span style="color: var(--on-surface-variant)">{{ status }}</span>
                        <span style="color: var(--on-surface); font-weight: 600">{{ count }}</span>
                    </div>
                </section>

                <section class="card stack" style="padding: 20px; gap: 10px">
                    <h2 class="serif" style="font-size: 1.05rem; color: var(--on-surface)">Distribución por plan</h2>
                    <p v-if="planEntries.length === 0" style="color: var(--on-surface-variant)">Sin datos.</p>
                    <div
                        v-for="[plan, count] in planEntries"
                        :key="plan"
                        class="flex items-center justify-between"
                        style="padding: 4px 0"
                    >
                        <span style="color: var(--on-surface-variant)">{{ plan }}</span>
                        <span style="color: var(--on-surface); font-weight: 600">{{ count }}</span>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>
