<script setup lang="ts">
/**
 * BillingPage (Phase 6b) — the Owner's billing home. Subscribe to a plan (Wompi recurring),
 * open the hosted affiliation link/QR to affiliate the card, see status + invoices, and cancel.
 *
 * Owner-only: the API enforces the billing.manage gate and 403s the rest — we catch that and
 * render an owner-only notice. Activation is webhook-driven: after the owner affiliates the
 * card on Wompi's page, Wompi charges and the overview flips to `active` once the webhook lands
 * (the "Refrescar estado" button re-fetches).
 */
import { ref, computed, onMounted } from 'vue'
import {
    CreditCard, FileText, QrCode, RefreshCw, ExternalLink, Lock, Download,
} from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import BillingService from '@/services/BillingService'
import PlansService from '@/services/PlansService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import type { Plan } from '@/types/domain/Plan'
import type { Subscription, Invoice } from '@/types/domain/Billing'

const { formatCents } = useFormatCurrency()
const toast = useToast()
const { confirm } = useConfirm()

const loading = ref(true)
const ownerOnly = ref(false)
const subscription = ref<Subscription | null>(null)
const invoices = ref<Invoice[]>([])
const plans = ref<Plan[]>([])
const subscribing = ref<string | null>(null)
const refreshing = ref(false)
const cancelling = ref(false)
// Hide the affiliation QR if its image fails to load (defensive — never show a broken image).
const qrError = ref(false)

const hasActive = computed(() => subscription.value?.status === 'active')
const pendingAffiliation = computed(
    () => !!subscription.value?.affiliation_url && !hasActive.value,
)
// The plan picker is offered whenever there is no active subscription (subscribe or switch).
const showPlanPicker = computed(() => !hasActive.value)

const statusVariant = computed(() => {
    switch (subscription.value?.status) {
        case 'active': return 'success'
        case 'trialing': return 'info'
        case 'past_due': return 'warning'
        case 'suspended':
        case 'expired':
        case 'cancelled': return 'error'
        default: return 'neutral'
    }
})

function formatDate(value: string | null): string {
    if (!value) return '—'
    return new Date(value).toLocaleDateString('es', { year: 'numeric', month: 'short', day: 'numeric' })
}

async function load(): Promise<void> {
    loading.value = true
    qrError.value = false
    try {
        const [overview, planList] = await Promise.all([
            BillingService.overview(),
            PlansService.list(),
        ])
        subscription.value = overview.subscription
        invoices.value = overview.recent_invoices
        plans.value = planList
        ownerOnly.value = false
    } catch (error: unknown) {
        if (isForbidden(error)) {
            ownerOnly.value = true
        } else {
            toast.error('No se pudo cargar la información de facturación.')
        }
    } finally {
        loading.value = false
    }
}

function isForbidden(error: unknown): boolean {
    return typeof error === 'object' && error !== null && 'response' in error
        && (error as { response?: { status?: number } }).response?.status === 403
}

async function subscribe(plan: Plan): Promise<void> {
    subscribing.value = plan.slug
    try {
        await BillingService.subscribe(plan.id)
        await load()
        toast.success('Suscripción creada. Afiliá tu tarjeta para activarla.')
    } catch {
        toast.error('No se pudo crear la suscripción. Intentá de nuevo.')
    } finally {
        subscribing.value = null
    }
}

async function refresh(): Promise<void> {
    refreshing.value = true
    qrError.value = false
    try {
        const overview = await BillingService.overview()
        subscription.value = overview.subscription
        invoices.value = overview.recent_invoices
    } finally {
        refreshing.value = false
    }
}

async function cancel(): Promise<void> {
    const ok = await confirm({
        title: 'Cancelar suscripción',
        message: 'Tu suscripción se cancelará al final del período actual. Mantenés acceso hasta entonces.',
        variant: 'danger',
    })
    if (!ok) return

    cancelling.value = true
    try {
        await BillingService.cancel()
        await load()
        toast.success('Suscripción cancelada. Seguís con acceso hasta el final del período.')
    } catch {
        toast.error('No se pudo cancelar la suscripción.')
    } finally {
        cancelling.value = false
    }
}

onMounted(() => {
    document.title = 'Facturación — Eternova'
    void load()
})
</script>

<template>
    <div data-testid="billing-page" class="stack" style="gap: 20px; max-width: 920px">
        <header>
            <h1 class="serif" style="font-size: 1.6rem; color: var(--on-surface)">Facturación</h1>
            <p style="color: var(--on-surface-variant)">Gestioná tu suscripción, tu tarjeta y tus facturas.</p>
        </header>

        <div v-if="loading" class="card" style="padding: 40px; display: flex; justify-content: center">
            <AppSpinner />
        </div>

        <AppEmptyState
            v-else-if="ownerOnly"
            title="Solo el propietario"
            description="La facturación de la cuenta solo está disponible para el propietario del negocio."
        >
            <template #illustration><Lock :size="32" /></template>
        </AppEmptyState>

        <template v-else>
            <!-- Current subscription -->
            <section
                v-if="subscription"
                data-testid="billing-status-card"
                class="card"
                style="padding: 24px"
            >
                <div class="flex items-start justify-between gap-4" style="flex-wrap: wrap">
                    <div class="stack" style="gap: 6px">
                        <div class="flex items-center gap-2">
                            <CreditCard :size="18" style="color: var(--primary)" />
                            <span class="serif" style="font-size: 1.15rem; color: var(--on-surface)">
                                {{ subscription.plan?.name ?? 'Plan' }}
                            </span>
                            <AppBadge :variant="statusVariant">{{ subscription.status_label }}</AppBadge>
                        </div>
                        <div style="color: var(--on-surface-variant); font-size: 0.92rem">
                            {{ formatCents(subscription.amount_cents) }}
                            <span v-if="subscription.billing_period"> / {{ subscription.billing_period }}</span>
                        </div>
                        <div
                            v-if="hasActive && subscription.next_billing_at"
                            style="color: var(--on-surface-variant); font-size: 0.88rem"
                        >
                            Próximo cobro: {{ formatDate(subscription.next_billing_at) }}
                        </div>
                        <div
                            v-if="subscription.card_last4"
                            style="color: var(--on-surface-variant); font-size: 0.88rem"
                        >
                            {{ subscription.card_brand ?? 'Tarjeta' }} •••• {{ subscription.card_last4 }}
                        </div>
                        <div
                            v-if="subscription.cancel_at_period_end"
                            style="color: var(--on-surface-variant); font-size: 0.85rem"
                        >
                            Se cancela el {{ formatDate(subscription.current_period_end) }}.
                        </div>
                    </div>

                    <AppButton
                        v-if="hasActive && !subscription.cancel_at_period_end"
                        data-testid="cancel-subscription-btn"
                        variant="danger"
                        size="sm"
                        :loading="cancelling"
                        @click="cancel"
                    >
                        Cancelar
                    </AppButton>
                </div>
            </section>

            <!-- Pending affiliation: open the hosted Wompi link / QR -->
            <section
                v-if="pendingAffiliation"
                data-testid="affiliation-panel"
                class="card"
                style="padding: 24px; background: var(--tier-mid)"
            >
                <div class="flex items-center gap-2" style="margin-bottom: 8px">
                    <QrCode :size="18" style="color: var(--primary)" />
                    <span class="serif" style="font-size: 1.05rem; color: var(--on-surface)">
                        Afiliá tu tarjeta para activar
                    </span>
                </div>
                <p style="color: var(--on-surface-variant); font-size: 0.92rem; margin-bottom: 16px">
                    Abrí el enlace seguro de Wompi y afiliá tu tarjeta. El cobro y la activación son
                    automáticos; volvé y tocá "Refrescar estado" cuando termines.
                </p>
                <div class="flex items-center gap-3" style="flex-wrap: wrap">
                    <AppButton
                        :href="subscription!.affiliation_url!"
                        data-testid="affiliation-open-link"
                        variant="primary"
                        size="md"
                        :icon="ExternalLink"
                    >
                        Abrir enlace de afiliación
                    </AppButton>
                    <AppButton
                        data-testid="affiliation-refresh"
                        variant="secondary"
                        size="md"
                        :icon="RefreshCw"
                        :loading="refreshing"
                        @click="refresh"
                    >
                        Refrescar estado
                    </AppButton>
                </div>
                <img
                    v-if="subscription!.affiliation_qr_url && !qrError"
                    :src="subscription!.affiliation_qr_url!"
                    alt="Código QR de afiliación"
                    style="margin-top: 16px; width: 160px; height: 160px; border-radius: var(--r-lg)"
                    @error="qrError = true"
                />
            </section>

            <!-- Plan picker -->
            <section v-if="showPlanPicker" class="stack" style="gap: 12px">
                <h2 class="serif" style="font-size: 1.1rem; color: var(--on-surface)">
                    {{ subscription ? 'Cambiar de plan' : 'Elegí tu plan' }}
                </h2>
                <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px">
                    <div
                        v-for="plan in plans"
                        :key="plan.id"
                        :data-testid="`plan-card-${plan.slug}`"
                        class="card stack"
                        style="padding: 20px; gap: 10px"
                    >
                        <span class="serif" style="font-size: 1.05rem; color: var(--on-surface)">{{ plan.name }}</span>
                        <span style="color: var(--primary); font-size: 1.25rem; font-weight: 600">
                            {{ formatCents(plan.price_monthly_cents) }}
                        </span>
                        <AppButton
                            :data-testid="`subscribe-btn-${plan.slug}`"
                            variant="primary"
                            size="sm"
                            :loading="subscribing === plan.slug"
                            @click="subscribe(plan)"
                        >
                            Suscribirme
                        </AppButton>
                    </div>
                </div>
            </section>

            <!-- Invoices -->
            <section class="stack" style="gap: 12px">
                <div class="flex items-center gap-2">
                    <FileText :size="18" style="color: var(--primary)" />
                    <h2 class="serif" style="font-size: 1.1rem; color: var(--on-surface)">Facturas</h2>
                </div>
                <AppEmptyState
                    v-if="invoices.length === 0"
                    title="Sin facturas"
                    description="Tus facturas aparecerán aquí después del primer cobro."
                />
                <div v-else class="card" style="padding: 8px">
                    <div
                        v-for="invoice in invoices"
                        :key="invoice.id"
                        data-testid="invoice-row"
                        class="flex items-center justify-between gap-3"
                        style="padding: 12px 14px"
                    >
                        <div class="stack" style="gap: 2px">
                            <span style="color: var(--on-surface); font-weight: 500">{{ invoice.number }}</span>
                            <span style="color: var(--on-surface-variant); font-size: 0.85rem">
                                {{ formatDate(invoice.issued_at) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3">
                            <span style="color: var(--on-surface)">{{ formatCents(invoice.total_cents) }}</span>
                            <AppBadge :variant="invoice.status === 'paid' ? 'success' : 'neutral'">
                                {{ invoice.status }}
                            </AppBadge>
                            <a
                                v-if="invoice.has_pdf"
                                :href="BillingService.downloadInvoiceUrl(invoice.id)"
                                target="_blank"
                                rel="noopener noreferrer"
                                data-testid="invoice-download"
                                style="color: var(--primary); display: inline-flex; align-items: center"
                                title="Descargar PDF"
                            >
                                <Download :size="16" />
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
