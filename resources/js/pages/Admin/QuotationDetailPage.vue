<script setup lang="ts">
/**
 * QuotationDetailPage — stub for S7-E6 routing.
 *
 * This page is intentionally minimal. It loads the quotation and displays
 * the number, status, and a back link so that row/card clicks from QuotationsPage
 * navigate without a 404.
 *
 * TODO(S7-E8): Replace this stub with the full detail view:
 *   - Quotation header (number, dates, customer)
 *   - Line items table
 *   - Financial summary (subtotal / discount / tax / total)
 *   - Status transition buttons (send / accept / reject)
 *   - "Convertir a pedido" action
 *   - PDF download button (QuotationService.pdfUrl)
 *   - Status history timeline
 */
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, FileText, AlertTriangle } from 'lucide-vue-next'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import QuotationService from '@/services/QuotationService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { QUOTATION_STATUS_LABELS, QUOTATION_STATUS_VARIANT } from '@/constants/quotations'
import type { Quotation } from '@/types/domain/Quotation'

const route = useRoute()
const router = useRouter()
const { formatCents } = useFormatCurrency()
const { formatDate } = useFormatDate()

const quotationId = route.params.id as string

type PageState = 'loading' | 'loaded' | 'not-found' | 'error'

const pageState = ref<PageState>('loading')
const quotation = ref<Quotation | null>(null)

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
</script>

<template>
    <!-- Loading -->
    <div
        v-if="pageState === 'loading'"
        class="flex flex-col items-center justify-center py-32 gap-4"
        aria-busy="true"
    >
        <AppSpinner size="lg" />
        <p class="text-sm text-on-surface-variant">Cargando cotización…</p>
    </div>

    <!-- Not found -->
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

    <!-- Error -->
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

    <!-- Loaded -->
    <div
        v-else-if="pageState === 'loaded' && quotation"
        class="flex flex-col gap-6"
        data-testid="quotation-detail"
    >
        <!-- Back link -->
        <button
            type="button"
            class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant
                   hover:text-primary transition-colors"
            @click="router.push({ name: 'admin.quotations' })"
        >
            <ArrowLeft :size="14" aria-hidden="true" />
            Volver a Cotizaciones
        </button>

        <!-- Header -->
        <div>
            <p class="label-gilt mb-1">Cotización</p>
            <div class="flex flex-wrap items-center gap-3 mb-2">
                <h1 class="serif text-3xl text-on-surface tracking-tighter">
                    {{ quotation.quotation_number }}
                </h1>
                <AppBadge :variant="QUOTATION_STATUS_VARIANT[quotation.status]">
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

        <!-- Summary card (stub — E8 expands this into the full detail) -->
        <div class="rounded-xl p-6 bg-surface-low dark:bg-surface-mid flex flex-col gap-5">
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0"
                    style="background: var(--primary-container); color: var(--primary)"
                >
                    <FileText :size="18" aria-hidden="true" />
                </div>
                <div>
                    <p class="text-xs label-gilt">Cliente</p>
                    <p class="text-sm font-semibold text-on-surface">
                        {{ quotation.customer?.name ?? 'Sin cliente' }}
                    </p>
                </div>
            </div>

            <div class="flex justify-between items-end">
                <div>
                    <p class="text-xs label-gilt mb-0.5">Total</p>
                    <p class="serif text-3xl text-primary tracking-tighter">
                        {{ formatCents(quotation.total_cents) }}
                    </p>
                </div>
                <div class="text-right text-xs text-on-surface-variant">
                    <p>Subtotal: {{ formatCents(quotation.subtotal_cents) }}</p>
                    <p v-if="quotation.discount_cents > 0">
                        Descuento: −{{ formatCents(quotation.discount_cents) }}
                    </p>
                    <p v-if="quotation.tax_cents > 0">
                        Impuesto: {{ formatCents(quotation.tax_cents) }}
                    </p>
                </div>
            </div>

            <!-- TODO(S7-E8): Add line items table, transition buttons, PDF download,
                 notes/terms, status history timeline. -->
            <p class="text-xs text-on-surface-variant italic text-center py-4">
                Vista completa disponible en S7-E8.
            </p>
        </div>
    </div>
</template>
