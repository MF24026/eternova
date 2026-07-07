<script setup lang="ts">
/**
 * QuotationDetailPage (S7-E8) — full quotation detail view.
 *
 * Layout mirrors ReservationDetailPage: split grid with the line items +
 * financial summary + notes/terms on the left, and the actions + status
 * timeline on the right.
 *
 * Actions:
 *   - PDF (preview/download) — opens GET /quotations/:id/pdf in a new tab
 *   - Status transitions (send / accept / reject) gated by allowed_transitions
 *   - Accept can optionally create an Order in the same call (convert_to_order)
 *   - Edit (drafts only) — reuses QuotationBuilderOverlay in edit mode
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
    ArrowLeft,
    User,
    FileText,
    FileDown,
    Pencil,
    Send,
    CheckCircle,
    XCircle,
    Circle,
    Clock,
    Package,
    AlertTriangle,
} from 'lucide-vue-next'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCard from '@/components/base/AppCard.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import QuotationBuilderOverlay from '@/components/Admin/Quotations/QuotationBuilderOverlay.vue'
import QuotationService from '@/services/QuotationService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { useToast } from '@/composables/useToast'
import { QUOTATION_STATUS_LABELS, QUOTATION_STATUS_VARIANT } from '@/constants/quotations'
import type { Quotation, QuotationStatus } from '@/types/domain/Quotation'

// ── Route & navigation ────────────────────────────────────────────────────────

const route = useRoute()
const router = useRouter()
const quotationId = route.params.id as string

// ── Composables ───────────────────────────────────────────────────────────────

const { formatCents } = useFormatCurrency()
const { formatDate, formatDateTime } = useFormatDate()
const toast = useToast()

// ── State ─────────────────────────────────────────────────────────────────────

type PageState = 'loading' | 'loaded' | 'not-found' | 'error'

const pageState = ref<PageState>('loading')
const quotation = ref<Quotation | null>(null)

// In-flight transition target (drives per-button loading state).
const transitioningTo = ref<QuotationStatus | null>(null)

// Accept flow (two-step confirm with an optional convert-to-order toggle).
const acceptConfirmVisible = ref(false)
const convertOnAccept = ref(false)

// Reject flow (two-step confirm).
const rejectConfirmVisible = ref(false)

// Edit builder.
const builderOpen = ref(false)

// ── Data loading ──────────────────────────────────────────────────────────────

async function loadQuotation(): Promise<void> {
    pageState.value = 'loading'
    try {
        quotation.value = await QuotationService.get(quotationId)
        pageState.value = 'loaded'
        document.title = `Cotización ${quotation.value.quotation_number} — Eternova`
    } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status
        pageState.value = status === 404 ? 'not-found' : 'error'
    }
}

onMounted(() => {
    void loadQuotation()
})

// ── Computed ──────────────────────────────────────────────────────────────────

const allowedTransitions = computed<QuotationStatus[]>(
    () => quotation.value?.allowed_transitions ?? [],
)

const canSend = computed<boolean>(() => allowedTransitions.value.includes('sent'))
const canAccept = computed<boolean>(() => allowedTransitions.value.includes('accepted'))
const canReject = computed<boolean>(() => allowedTransitions.value.includes('rejected'))
const isDraft = computed<boolean>(() => quotation.value?.status === 'draft')

const hasAnyAction = computed<boolean>(
    () => canSend.value || canAccept.value || canReject.value || isDraft.value,
)

// Timeline newest-first.
const timelineEntries = computed(() => {
    if (!quotation.value?.status_history) return []
    return [...quotation.value.status_history].reverse()
})

const lineItems = computed(() => quotation.value?.items ?? [])

// ── PDF ───────────────────────────────────────────────────────────────────────

function openPdf(): void {
    if (!quotation.value) return
    window.open(QuotationService.pdfUrl(quotation.value.id), '_blank', 'noopener')
}

// ── Transitions ───────────────────────────────────────────────────────────────

async function sendQuotation(): Promise<void> {
    if (!quotation.value || transitioningTo.value !== null) return

    transitioningTo.value = 'sent'
    try {
        await QuotationService.send(quotationId)
        toast.success('Cotización enviada al cliente.')
        await loadQuotation()
    } catch (err: unknown) {
        toast.error(extractApiMessage(err) ?? 'No se pudo enviar la cotización.')
    } finally {
        transitioningTo.value = null
    }
}

async function acceptQuotation(): Promise<void> {
    if (!quotation.value || transitioningTo.value !== null) return

    transitioningTo.value = 'accepted'
    acceptConfirmVisible.value = false
    const convert = convertOnAccept.value
    try {
        await QuotationService.accept(quotationId, { convert_to_order: convert })
        toast.success(
            convert
                ? 'Cotización aceptada y convertida en pedido.'
                : 'Cotización aceptada.',
        )
        await loadQuotation()

        // If we converted, jump to the freshly created order.
        if (convert && quotation.value?.converted_order_id) {
            void router.push({
                name: 'admin.orders.detail',
                params: { id: quotation.value.converted_order_id },
            })
        }
    } catch (err: unknown) {
        toast.error(extractApiMessage(err) ?? 'No se pudo aceptar la cotización.')
    } finally {
        transitioningTo.value = null
        convertOnAccept.value = false
    }
}

async function rejectQuotation(): Promise<void> {
    if (!quotation.value || transitioningTo.value !== null) return

    transitioningTo.value = 'rejected'
    rejectConfirmVisible.value = false
    try {
        await QuotationService.reject(quotationId)
        toast.success('Cotización rechazada.')
        await loadQuotation()
    } catch (err: unknown) {
        toast.error(extractApiMessage(err) ?? 'No se pudo rechazar la cotización.')
    } finally {
        transitioningTo.value = null
    }
}

// ── Edit ──────────────────────────────────────────────────────────────────────

function onBuilderSaved(saved: Quotation): void {
    quotation.value = saved
    // Reload to refresh items + timeline + allowed_transitions from the server.
    void loadQuotation()
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractApiMessage(err: unknown): string | null {
    const body = (err as { response?: { data?: { message?: string } } })?.response?.data
    return body?.message ?? null
}

// Timeline node icon per quotation status.
function timelineIcon(status: QuotationStatus): typeof CheckCircle {
    const icons: Record<QuotationStatus, typeof CheckCircle> = {
        draft: Clock,
        sent: Send,
        accepted: CheckCircle,
        rejected: XCircle,
        expired: AlertTriangle,
    }
    return icons[status] ?? Circle
}
</script>

<template>
    <!-- ── Loading ── -->
    <div
        v-if="pageState === 'loading'"
        class="flex flex-col items-center justify-center py-32 gap-4"
        aria-busy="true"
    >
        <AppSpinner size="lg" />
        <p class="text-sm text-on-surface-variant">Cargando cotización…</p>
    </div>

    <!-- ── Not found ── -->
    <div
        v-else-if="pageState === 'not-found'"
        class="flex flex-col items-center justify-center py-32 gap-6 text-center"
    >
        <div
            class="w-16 h-16 rounded-full flex items-center justify-center"
            style="background: var(--gradient-soft)"
        >
            <AlertTriangle :size="28" class="text-primary" aria-hidden="true" />
        </div>
        <div>
            <h2 class="serif text-2xl text-on-surface tracking-tighter mb-2">
                Cotización no encontrada
            </h2>
            <p class="text-sm text-on-surface-variant">
                La cotización que buscas no existe o no tienes acceso a ella.
            </p>
        </div>
        <AppButton
            variant="secondary"
            :icon="ArrowLeft"
            @click="router.push({ name: 'admin.quotations' })"
        >
            Volver a Cotizaciones
        </AppButton>
    </div>

    <!-- ── Error ── -->
    <div
        v-else-if="pageState === 'error'"
        class="flex flex-col items-center justify-center py-32 gap-6 text-center"
    >
        <div class="w-16 h-16 rounded-full flex items-center justify-center bg-error-container">
            <AlertTriangle :size="28" class="text-error" aria-hidden="true" />
        </div>
        <div>
            <h2 class="serif text-2xl text-on-surface tracking-tighter mb-2">
                Error al cargar
            </h2>
            <p class="text-sm text-on-surface-variant">
                Ocurrió un error al obtener la cotización. Intenta de nuevo.
            </p>
        </div>
        <AppButton variant="secondary" @click="loadQuotation">
            Reintentar
        </AppButton>
    </div>

    <!-- ── Loaded ── -->
    <div
        v-else-if="pageState === 'loaded' && quotation"
        class="flex flex-col gap-6"
        data-testid="quotation-detail"
    >
        <!-- Page header -->
        <div>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant
                       hover:text-primary transition-colors mb-4"
                @click="router.push({ name: 'admin.quotations' })"
            >
                <ArrowLeft :size="14" aria-hidden="true" />
                Volver a Cotizaciones
            </button>

            <p class="label-gilt mb-1">Cotización</p>
            <div class="flex flex-wrap items-center gap-3 mb-2">
                <h1 class="serif text-3xl text-on-surface tracking-tighter">
                    {{ quotation.quotation_number }}
                </h1>
                <AppBadge
                    :variant="QUOTATION_STATUS_VARIANT[quotation.status]"
                    data-testid="quotation-status-badge"
                >
                    {{ QUOTATION_STATUS_LABELS[quotation.status] }}
                </AppBadge>
            </div>
            <p class="text-xs text-on-surface-variant">
                Emitida {{ formatDate(quotation.issue_date) }}
                <template v-if="quotation.valid_until">
                    · Válida hasta {{ formatDate(quotation.valid_until) }}
                </template>
            </p>
        </div>

        <!-- Split layout: left (content) / right (actions + timeline) -->
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">

            <!-- ── LEFT COLUMN ── -->
            <div class="flex flex-col gap-6">

                <!-- Customer card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Cliente
                    </h2>
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center shrink-0">
                            <User :size="14" class="text-primary" aria-hidden="true" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-on-surface">
                                {{ quotation.customer?.name ?? 'Sin cliente asignado' }}
                            </p>
                            <p v-if="quotation.customer?.phone" class="text-xs text-on-surface-variant">
                                {{ quotation.customer.phone }}
                            </p>
                            <p v-if="quotation.customer?.email" class="text-xs text-on-surface-variant">
                                {{ quotation.customer.email }}
                            </p>
                        </div>
                    </div>
                </AppCard>

                <!-- Line items card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Líneas
                    </h2>

                    <div
                        v-if="lineItems.length === 0"
                        class="text-sm text-on-surface-variant text-center py-6"
                    >
                        Sin líneas registradas.
                    </div>

                    <table v-else class="w-full text-sm" data-testid="quotation-items-table">
                        <thead>
                            <tr class="text-xs label-gilt">
                                <th class="text-left font-semibold pb-2">Descripción</th>
                                <th class="text-right font-semibold pb-2 w-16">Cant.</th>
                                <th class="text-right font-semibold pb-2 w-28">P. unit.</th>
                                <th class="text-right font-semibold pb-2 w-28">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in lineItems"
                                :key="item.id"
                                class="odd:bg-surface-low dark:odd:bg-surface-mid"
                            >
                                <td class="py-2.5 px-2 rounded-l-lg text-on-surface">
                                    {{ item.description }}
                                </td>
                                <td class="py-2.5 px-2 text-right text-on-surface-variant tabular-nums">
                                    {{ item.quantity }}
                                </td>
                                <td class="py-2.5 px-2 text-right text-on-surface-variant tabular-nums">
                                    {{ formatCents(item.unit_price_cents) }}
                                </td>
                                <td class="py-2.5 px-2 rounded-r-lg text-right font-semibold text-on-surface tabular-nums">
                                    {{ formatCents(item.line_total_cents) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </AppCard>

                <!-- Financial summary card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Resumen financiero
                    </h2>

                    <div class="flex flex-col gap-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-on-surface-variant">Subtotal</span>
                            <span class="text-sm font-semibold text-on-surface tabular-nums">
                                {{ formatCents(quotation.subtotal_cents) }}
                            </span>
                        </div>

                        <div v-if="quotation.discount_cents > 0" class="flex justify-between items-center">
                            <span class="text-sm text-on-surface-variant">Descuento</span>
                            <span class="text-sm font-semibold text-error tabular-nums">
                                −{{ formatCents(quotation.discount_cents) }}
                            </span>
                        </div>

                        <div v-if="quotation.tax_cents > 0" class="flex justify-between items-center">
                            <span class="text-sm text-on-surface-variant">
                                Impuesto ({{ (quotation.tax_rate_bps / 100).toFixed(0) }}%)
                            </span>
                            <span class="text-sm font-semibold text-on-surface tabular-nums">
                                {{ formatCents(quotation.tax_cents) }}
                            </span>
                        </div>

                        <div
                            class="flex justify-between items-end pt-3"
                            style="border-top: 2px solid var(--outline-soft)"
                        >
                            <span class="serif text-xl text-on-surface tracking-tighter">Total</span>
                            <span
                                class="serif text-3xl text-primary tracking-tighter tabular-nums"
                                data-testid="quotation-total"
                            >
                                {{ formatCents(quotation.total_cents) }}
                            </span>
                        </div>
                    </div>
                </AppCard>

                <!-- Notes + terms card -->
                <AppCard v-if="quotation.notes || quotation.terms">
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Notas y condiciones
                    </h2>

                    <div class="flex flex-col gap-4">
                        <div v-if="quotation.notes" class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center shrink-0">
                                <FileText :size="14" class="text-secondary" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Notas</p>
                                <p class="text-sm text-on-surface leading-relaxed">
                                    {{ quotation.notes }}
                                </p>
                            </div>
                        </div>

                        <div v-if="quotation.terms" class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-surface-high dark:bg-surface-mid flex items-center justify-center shrink-0">
                                <FileText :size="14" class="text-on-surface-variant" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Términos y condiciones</p>
                                <p class="text-sm text-on-surface leading-relaxed">
                                    {{ quotation.terms }}
                                </p>
                            </div>
                        </div>
                    </div>
                </AppCard>
            </div>

            <!-- ── RIGHT COLUMN ── -->
            <div class="flex flex-col gap-6">

                <!-- Actions card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Acciones
                    </h2>

                    <!-- PDF -->
                    <AppButton
                        variant="secondary"
                        :icon="FileDown"
                        class="w-full mb-3"
                        data-testid="btn-pdf"
                        @click="openPdf"
                    >
                        Ver / descargar PDF
                    </AppButton>

                    <!-- Edit (drafts only) -->
                    <AppButton
                        v-if="isDraft"
                        variant="ghost"
                        :icon="Pencil"
                        class="w-full mb-3"
                        data-testid="btn-edit"
                        :disabled="transitioningTo !== null"
                        @click="builderOpen = true"
                    >
                        Editar cotización
                    </AppButton>

                    <!-- Transition actions -->
                    <div v-if="hasAnyAction" class="flex flex-col gap-2">
                        <p class="text-xs label-gilt mb-1">Cambiar estado</p>

                        <!-- Send -->
                        <AppButton
                            v-if="canSend"
                            variant="primary"
                            :icon="Send"
                            class="w-full"
                            data-testid="btn-send"
                            :loading="transitioningTo === 'sent'"
                            :disabled="transitioningTo !== null"
                            @click="sendQuotation"
                        >
                            Marcar como enviada
                        </AppButton>

                        <!-- Accept -->
                        <template v-if="canAccept">
                            <div
                                v-if="acceptConfirmVisible"
                                class="rounded-xl p-4 bg-success-container space-y-3"
                                data-testid="accept-confirm"
                            >
                                <p class="text-sm font-semibold text-on-surface text-center">
                                    ¿Aceptar cotización?
                                </p>
                                <label class="flex items-center gap-2 text-xs text-on-surface cursor-pointer">
                                    <input
                                        v-model="convertOnAccept"
                                        type="checkbox"
                                        class="rounded"
                                        data-testid="convert-checkbox"
                                    />
                                    Crear un pedido a partir de esta cotización
                                </label>
                                <div class="flex gap-2">
                                    <AppButton
                                        variant="secondary"
                                        size="sm"
                                        class="flex-1"
                                        :disabled="transitioningTo !== null"
                                        @click="acceptConfirmVisible = false"
                                    >
                                        Cancelar
                                    </AppButton>
                                    <AppButton
                                        variant="primary"
                                        size="sm"
                                        class="flex-1"
                                        data-testid="btn-accept-confirm"
                                        :loading="transitioningTo === 'accepted'"
                                        :disabled="transitioningTo !== null"
                                        @click="acceptQuotation"
                                    >
                                        Sí, aceptar
                                    </AppButton>
                                </div>
                            </div>
                            <AppButton
                                v-else
                                variant="primary"
                                :icon="CheckCircle"
                                class="w-full"
                                data-testid="btn-accept"
                                :disabled="transitioningTo !== null"
                                @click="acceptConfirmVisible = true"
                            >
                                Aceptar cotización
                            </AppButton>
                        </template>

                        <!-- Reject -->
                        <template v-if="canReject">
                            <div
                                v-if="rejectConfirmVisible"
                                class="rounded-xl p-4 bg-error-container dark:bg-error/10 space-y-3"
                                data-testid="reject-confirm"
                            >
                                <p class="text-sm font-semibold text-error text-center">
                                    ¿Rechazar cotización?
                                </p>
                                <p class="text-xs text-on-surface-variant text-center">
                                    Esta acción no se puede deshacer.
                                </p>
                                <div class="flex gap-2">
                                    <AppButton
                                        variant="secondary"
                                        size="sm"
                                        class="flex-1"
                                        :disabled="transitioningTo !== null"
                                        @click="rejectConfirmVisible = false"
                                    >
                                        Cancelar
                                    </AppButton>
                                    <AppButton
                                        variant="danger"
                                        size="sm"
                                        class="flex-1"
                                        data-testid="btn-reject-confirm"
                                        :loading="transitioningTo === 'rejected'"
                                        :disabled="transitioningTo !== null"
                                        @click="rejectQuotation"
                                    >
                                        Sí, rechazar
                                    </AppButton>
                                </div>
                            </div>
                            <AppButton
                                v-else
                                variant="danger"
                                :icon="XCircle"
                                class="w-full"
                                data-testid="btn-reject"
                                :disabled="transitioningTo !== null"
                                @click="rejectConfirmVisible = true"
                            >
                                Rechazar cotización
                            </AppButton>
                        </template>
                    </div>

                    <!-- Already converted: link to the order -->
                    <div
                        v-if="quotation.converted_order_id"
                        class="mt-4 flex items-center gap-2 rounded-xl p-3 bg-success-container"
                        data-testid="converted-banner"
                    >
                        <Package :size="16" class="text-success shrink-0" aria-hidden="true" />
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-on-surface font-semibold">Convertida en pedido</p>
                            <button
                                type="button"
                                class="text-xs text-primary hover:underline"
                                @click="router.push({ name: 'admin.orders.detail', params: { id: quotation!.converted_order_id! } })"
                            >
                                Ver pedido →
                            </button>
                        </div>
                    </div>

                    <!-- No actions available (terminal state, not converted) -->
                    <p
                        v-if="!hasAnyAction && !quotation.converted_order_id"
                        class="text-xs text-on-surface-variant text-center mt-2"
                    >
                        No hay acciones disponibles para este estado.
                    </p>
                </AppCard>

                <!-- Timeline card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Historial
                    </h2>

                    <div
                        v-if="timelineEntries.length === 0"
                        class="text-sm text-on-surface-variant text-center py-4"
                    >
                        Sin registros aún.
                    </div>

                    <ol
                        v-else
                        class="flex flex-col"
                        aria-label="Historial de estados de la cotización"
                    >
                        <li
                            v-for="(entry, index) in timelineEntries"
                            :key="entry.id"
                            class="flex gap-4"
                        >
                            <div class="flex flex-col items-center shrink-0">
                                <div
                                    :class="[
                                        'w-8 h-8 rounded-full flex items-center justify-center shrink-0 z-10',
                                        index === 0
                                            ? 'bg-primary text-on-primary'
                                            : 'bg-surface-high text-on-surface-variant dark:bg-surface-mid',
                                    ]"
                                >
                                    <component
                                        :is="timelineIcon(entry.to_status)"
                                        :size="14"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div
                                    v-if="index < timelineEntries.length - 1"
                                    class="w-0.5 flex-1 my-1"
                                    style="background: linear-gradient(to bottom, var(--primary-container), var(--surface-high))"
                                    aria-hidden="true"
                                />
                            </div>

                            <div
                                :class="[
                                    'pb-5 flex-1 min-w-0',
                                    index === timelineEntries.length - 1 ? 'pb-0' : '',
                                ]"
                            >
                                <p
                                    :class="[
                                        'text-sm font-semibold',
                                        index === 0 ? 'text-primary' : 'text-on-surface',
                                    ]"
                                >
                                    {{ QUOTATION_STATUS_LABELS[entry.to_status] }}
                                    <AppBadge
                                        v-if="index === 0"
                                        :variant="QUOTATION_STATUS_VARIANT[entry.to_status]"
                                        size="sm"
                                        class="ml-1.5"
                                    >
                                        Actual
                                    </AppBadge>
                                </p>
                                <p class="text-xs text-on-surface-variant mt-0.5">
                                    {{ formatDateTime(entry.created_at) }}
                                </p>
                                <p v-if="entry.user" class="text-xs text-on-surface-variant mt-0.5">
                                    por {{ entry.user.name }}
                                </p>
                                <p
                                    v-if="entry.note"
                                    class="text-xs text-on-surface mt-1 italic leading-relaxed"
                                >
                                    "{{ entry.note }}"
                                </p>
                            </div>
                        </li>
                    </ol>
                </AppCard>
            </div>
        </div>

        <!-- Edit builder (drafts) -->
        <QuotationBuilderOverlay
            v-model="builderOpen"
            :quotation="quotation"
            @saved="onBuilderSaved"
        />
    </div>
</template>
