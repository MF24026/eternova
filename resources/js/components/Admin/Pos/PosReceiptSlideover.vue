<script setup lang="ts">
import { computed } from 'vue'
import { Printer, Plus, Check } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import type { PosReceipt } from '@/types/domain/POS'

interface Props {
    modelValue: boolean
    receipt: PosReceipt | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    'new-sale': []
}>()

const { formatCents } = useFormatCurrency()
const { formatDateTime } = useFormatDate()

// ── Payment method labels ─────────────────────────────────────────────────────

const paymentLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    other: 'Otro',
}

function paymentLabel(method: string): string {
    return paymentLabels[method] ?? method
}

// ── Variant options display ───────────────────────────────────────────────────

function variantOptionsLabel(options: Record<string, string>): string {
    return Object.entries(options)
        .map(([key, value]) => `${key}: ${value}`)
        .join(' · ')
}

// ── Discount / tax visibility ─────────────────────────────────────────────────

const hasDiscount = computed(
    () => (props.receipt?.discount_cents ?? 0) > 0,
)

const hasTax = computed(
    () => (props.receipt?.tax_cents ?? 0) > 0,
)

// ── Handlers ─────────────────────────────────────────────────────────────────

function handlePrint(): void {
    window.print()
}

function handleNewSale(): void {
    emit('new-sale')
    emit('update:modelValue', false)
}

function close(): void {
    emit('update:modelValue', false)
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Comprobante de venta"
        :subtitle="receipt ? `Orden #${receipt.order_number}` : ''"
        side="right"
        width="480px"
        @update:model-value="close"
    >
        <!-- ── Receipt body ──────────────────────────────────────────────── -->
        <div v-if="receipt" class="pos-ticket flex flex-col gap-4" data-testid="pos-receipt-panel">

            <!-- Business header -->
            <div class="flex flex-col items-center gap-2 text-center py-4"
                 style="background: var(--surface-low); border-radius: var(--r-xl)">
                <img
                    v-if="receipt.business.logo_url"
                    :src="receipt.business.logo_url"
                    :alt="receipt.business.name"
                    class="h-12 object-contain"
                />
                <h3
                    class="serif text-xl"
                    style="color: var(--on-surface)"
                    data-testid="pos-receipt-business-name"
                >
                    {{ receipt.business.name }}
                </h3>
                <div
                    v-if="receipt.branch"
                    class="text-xs"
                    style="color: var(--on-surface-variant)"
                >
                    {{ receipt.branch.name }}
                    <template v-if="receipt.branch.address">
                        &nbsp;· {{ receipt.branch.address }}
                    </template>
                    <template v-if="receipt.branch.phone">
                        &nbsp;· {{ receipt.branch.phone }}
                    </template>
                </div>
            </div>

            <!-- Order meta -->
            <div
                class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm"
                style="padding: 16px; background: var(--surface-mid); border-radius: var(--r-lg)"
            >
                <div style="color: var(--on-surface-variant)">Orden</div>
                <div
                    class="font-semibold text-right"
                    style="color: var(--on-surface)"
                    data-testid="pos-receipt-order-number"
                >
                    #{{ receipt.order_number }}
                </div>

                <div style="color: var(--on-surface-variant)">Fecha</div>
                <div class="text-right" style="color: var(--on-surface)">
                    {{ formatDateTime(receipt.created_at) }}
                </div>

                <div style="color: var(--on-surface-variant)">Cajero</div>
                <div class="text-right" style="color: var(--on-surface)">
                    {{ receipt.cashier.name }}
                </div>

                <template v-if="receipt.customer">
                    <div style="color: var(--on-surface-variant)">Cliente</div>
                    <div class="text-right" style="color: var(--on-surface)">
                        {{ receipt.customer.name }}
                    </div>
                </template>

                <div style="color: var(--on-surface-variant)">Pago</div>
                <div class="text-right" style="color: var(--on-surface)">
                    {{ paymentLabel(receipt.payment_method) }}
                </div>
            </div>

            <!-- Line items -->
            <div>
                <p class="label-gilt mb-2">Productos</p>
                <div class="flex flex-col gap-2" data-testid="pos-receipt-items">
                    <div
                        v-for="(item, idx) in receipt.items"
                        :key="idx"
                        class="flex justify-between items-start gap-3 text-sm py-2"
                        style="border-bottom: 1px solid var(--outline-variant)"
                    >
                        <div class="flex-1 min-w-0">
                            <div class="font-medium" style="color: var(--on-surface)">
                                {{ item.name }}
                            </div>
                            <div
                                v-if="Object.keys(item.variant_options).length > 0"
                                class="text-xs mt-0.5"
                                style="color: var(--on-surface-variant)"
                            >
                                {{ variantOptionsLabel(item.variant_options) }}
                            </div>
                            <div class="text-xs mt-0.5" style="color: var(--on-surface-variant)">
                                {{ item.quantity }} × {{ formatCents(item.unit_price_cents) }}
                            </div>
                        </div>
                        <div class="font-semibold tabular-nums shrink-0" style="color: var(--on-surface)">
                            {{ formatCents(item.total_cents) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div
                class="flex flex-col gap-2 text-sm"
                style="padding: 16px; background: var(--surface-mid); border-radius: var(--r-lg)"
            >
                <div class="flex justify-between" style="color: var(--on-surface-variant)">
                    <span>Subtotal</span>
                    <span class="tabular-nums">{{ formatCents(receipt.subtotal_cents) }}</span>
                </div>
                <div
                    v-if="hasTax"
                    class="flex justify-between"
                    style="color: var(--on-surface-variant)"
                >
                    <span>IVA</span>
                    <span class="tabular-nums">{{ formatCents(receipt.tax_cents) }}</span>
                </div>
                <div
                    v-if="hasDiscount"
                    class="flex justify-between"
                    style="color: var(--success)"
                >
                    <span>Descuento</span>
                    <span class="tabular-nums">-{{ formatCents(receipt.discount_cents) }}</span>
                </div>
                <div class="flex justify-between items-center pt-2" style="border-top: 1px solid var(--outline-soft)">
                    <span class="font-semibold text-base" style="color: var(--on-surface)">Total</span>
                    <span
                        class="serif text-2xl tabular-nums"
                        style="color: var(--primary)"
                        data-testid="pos-receipt-total"
                    >
                        {{ formatCents(receipt.total_cents) }}
                    </span>
                </div>
            </div>

            <!-- Success badge -->
            <div
                class="flex items-center justify-center gap-2 py-3 rounded-xl text-sm font-semibold"
                style="background: var(--success-container); color: var(--success)"
            >
                <Check :size="16" aria-hidden="true" />
                Venta registrada correctamente
            </div>
        </div>

        <!-- Loading / empty state — shown only during the brief moment receipt is null
             but the slideover is already open (edge case). -->
        <div
            v-else
            class="flex items-center justify-center py-16 text-sm"
            style="color: var(--on-surface-variant)"
        >
            Cargando comprobante...
        </div>

        <!-- ── Footer actions ────────────────────────────────────────────── -->
        <template #footer>
            <div class="flex gap-3">
                <button
                    type="button"
                    class="btn btn-tertiary flex-1 justify-center"
                    data-testid="pos-receipt-print-btn"
                    @click="handlePrint"
                >
                    <Printer :size="16" aria-hidden="true" />
                    Imprimir
                </button>
                <button
                    type="button"
                    class="btn btn-primary flex-1 justify-center"
                    data-testid="pos-receipt-new-sale-btn"
                    @click="handleNewSale"
                >
                    <Plus :size="16" aria-hidden="true" />
                    Nueva venta
                </button>
            </div>
        </template>
    </AppSlideover>

    <!-- ── Print-only ticket ─────────────────────────────────────────────────
         This subtree is hidden on screen but revealed by @media print rules.
         It is always rendered (not gated by v-if) so that window.print() finds
         it immediately without waiting for a DOM update after the button click.

         Isolation strategy:
           @media print { body * { visibility: hidden } }
           .pos-print-ticket, .pos-print-ticket * { visibility: visible }
           .pos-print-ticket { position: fixed; top: 0; left: 0 }

         This keeps the entire app chrome invisible and only surfaces the ticket.
         Force-light colors via explicit inline styles so dark mode tokens do not
         bleed into the printed output.
    ─────────────────────────────────────────────────────────────────────────── -->
    <div
        v-if="receipt"
        class="pos-print-ticket"
        aria-hidden="true"
    >
        <!-- Business header -->
        <div style="text-align: center; margin-bottom: 12px">
            <img
                v-if="receipt.business.logo_url"
                :src="receipt.business.logo_url"
                :alt="receipt.business.name"
                style="height: 48px; object-fit: contain; margin: 0 auto 8px; display: block"
            />
            <div style="font-size: 16px; font-weight: 700; margin-bottom: 2px">
                {{ receipt.business.name }}
            </div>
            <div v-if="receipt.branch" style="font-size: 11px; color: #555">
                {{ receipt.branch.name }}
                <template v-if="receipt.branch.address">&nbsp;· {{ receipt.branch.address }}</template>
                <template v-if="receipt.branch.phone"><br>{{ receipt.branch.phone }}</template>
            </div>
        </div>

        <div style="border-top: 1px dashed #999; margin: 8px 0" />

        <!-- Meta -->
        <div style="font-size: 11px; margin-bottom: 8px">
            <div><strong>Orden:</strong> #{{ receipt.order_number }}</div>
            <div><strong>Fecha:</strong> {{ formatDateTime(receipt.created_at) }}</div>
            <div><strong>Cajero:</strong> {{ receipt.cashier.name }}</div>
            <div v-if="receipt.customer"><strong>Cliente:</strong> {{ receipt.customer.name }}</div>
            <div><strong>Pago:</strong> {{ paymentLabel(receipt.payment_method) }}</div>
        </div>

        <div style="border-top: 1px dashed #999; margin: 8px 0" />

        <!-- Items -->
        <div style="font-size: 11px">
            <div
                v-for="(item, idx) in receipt.items"
                :key="idx"
                style="margin-bottom: 6px"
            >
                <div style="display: flex; justify-content: space-between">
                    <span style="font-weight: 600">{{ item.name }}</span>
                    <span>{{ formatCents(item.total_cents) }}</span>
                </div>
                <div
                    v-if="Object.keys(item.variant_options).length > 0"
                    style="font-size: 10px; color: #666"
                >
                    {{ variantOptionsLabel(item.variant_options) }}
                </div>
                <div style="font-size: 10px; color: #666">
                    {{ item.quantity }} × {{ formatCents(item.unit_price_cents) }}
                </div>
            </div>
        </div>

        <div style="border-top: 1px dashed #999; margin: 8px 0" />

        <!-- Totals -->
        <div style="font-size: 11px; margin-bottom: 4px">
            <div style="display: flex; justify-content: space-between">
                <span>Subtotal</span>
                <span>{{ formatCents(receipt.subtotal_cents) }}</span>
            </div>
            <div v-if="hasTax" style="display: flex; justify-content: space-between">
                <span>IVA</span>
                <span>{{ formatCents(receipt.tax_cents) }}</span>
            </div>
            <div v-if="hasDiscount" style="display: flex; justify-content: space-between">
                <span>Descuento</span>
                <span>-{{ formatCents(receipt.discount_cents) }}</span>
            </div>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; margin-top: 6px">
            <span>TOTAL</span>
            <span>{{ formatCents(receipt.total_cents) }}</span>
        </div>

        <div style="border-top: 1px dashed #999; margin: 10px 0 6px" />
        <div style="text-align: center; font-size: 10px; color: #888">
            Gracias por su compra
        </div>
    </div>
</template>

<style scoped>
/* ── Screen: hide the print-only ticket ─────────────────────────────────── */
.pos-print-ticket {
    display: none;
}

/* ── Print: show ONLY the thermal ticket ────────────────────────────────── */
@media print {
    /*
     * Hide the entire page chrome and the on-screen slideover content.
     * The scoped selector :global() reaches outside the component's shadow.
     * We use the standard body/* trick; then un-hide the ticket subtree.
     */
    :global(body *) {
        visibility: hidden !important;
    }

    .pos-print-ticket {
        display: block !important;
        visibility: visible !important;

        /*
         * Fixed positioning at top-left means the browser renders the ticket
         * at the start of the print page regardless of scroll position.
         */
        position: fixed;
        top: 0;
        left: 0;

        /* ~80 mm — standard thermal receipt width */
        width: 80mm;
        padding: 8mm;

        /* Always light — prevent dark-mode CSS variables from affecting print */
        background: #ffffff !important;
        color: #000000 !important;

        font-family: 'Courier New', Courier, monospace;
        font-size: 11px;
        line-height: 1.4;
    }

    .pos-print-ticket * {
        visibility: visible !important;
        color: inherit !important;
        background: transparent !important;
    }

    /*
     * Remove the top-margin that @page sometimes adds.
     */
    @page {
        margin: 0;
        size: 80mm auto;
    }
}
</style>
