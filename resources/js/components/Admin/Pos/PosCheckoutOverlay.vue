<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { X, Wallet, CreditCard, ArrowLeftRight, Delete } from 'lucide-vue-next'
import type { PosCartLine, PosPaymentMethod } from '@/types/domain/POS'

const props = defineProps<{
    show: boolean
    lines: PosCartLine[]
    subtotalCents: number
    taxCents: number
    totalCents: number
    paymentMethod: PosPaymentMethod
    formatCents: (cents: number) => string
    submitting: boolean
}>()

const emit = defineEmits<{
    close: []
    'update:paymentMethod': [method: PosPaymentMethod]
    confirm: [payload: { amountReceivedCents: number | null }]
}>()

const methods: Array<{ id: PosPaymentMethod; label: string; icon: unknown }> = [
    { id: 'cash', label: 'Efectivo', icon: Wallet },
    { id: 'card', label: 'Tarjeta', icon: CreditCard },
    { id: 'transfer', label: 'Transferencia', icon: ArrowLeftRight },
]

const isCash = computed(() => props.paymentMethod === 'cash')

// Cash tendered, entered as a decimal string ("20", "20.5") like a calculator.
const receivedStr = ref('')

const receivedCents = computed(() => {
    const n = parseFloat(receivedStr.value || '0')
    return Number.isFinite(n) ? Math.round(n * 100) : 0
})
const changeCents = computed(() => Math.max(0, receivedCents.value - props.totalCents))
const isShort = computed(() => isCash.value && receivedCents.value < props.totalCents)
const canConfirm = computed(() => !props.submitting && (!isCash.value || !isShort.value))

const lineLabel = (l: PosCartLine): string =>
    Object.values(l.variantOptions ?? {}).join(' · ')

// Contextual quick amounts: exact, then the next few round-ups above the total.
const suggestions = computed<number[]>(() => {
    const total = props.totalCents / 100
    const ups = new Set<number>([Math.ceil(total)])
    for (const step of [5, 10, 20, 50]) ups.add(Math.ceil(total / step) * step)
    return [...ups].filter((v) => v * 100 > props.totalCents).sort((a, b) => a - b).slice(0, 4)
})

function setExact(): void {
    receivedStr.value = (props.totalCents / 100).toFixed(2)
}
function setAmount(dollars: number): void {
    receivedStr.value = String(dollars)
}
function pressDigit(d: string): void {
    // Guard against a second decimal point and runaway length.
    if (d === '.' && receivedStr.value.includes('.')) return
    if (receivedStr.value.replace('.', '').length >= 8) return
    receivedStr.value = (receivedStr.value === '0' && d !== '.') ? d : receivedStr.value + d
}
function backspace(): void {
    receivedStr.value = receivedStr.value.slice(0, -1)
}

function confirm(): void {
    if (!canConfirm.value) return
    emit('confirm', { amountReceivedCents: isCash.value ? receivedCents.value : null })
}

// Physical keyboard: digits type, Enter confirms, Esc cancels, Backspace deletes.
function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') { emit('close'); return }
    if (e.key === 'Enter') { confirm(); return }
    if (!isCash.value) return
    if (e.key === 'Backspace') { backspace(); e.preventDefault(); return }
    if (/^[0-9]$/.test(e.key) || e.key === '.') { pressDigit(e.key); e.preventDefault() }
}

watch(
    () => props.show,
    (open) => {
        if (open) {
            receivedStr.value = ''
            document.addEventListener('keydown', onKeydown)
        } else {
            document.removeEventListener('keydown', onKeydown)
        }
    },
)
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))

const keypad = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', 'back']
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-[90] grid place-items-center p-0 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="pos-checkout-title"
                data-testid="pos-checkout-overlay"
            >
                <div
                    class="absolute inset-0"
                    style="background: rgba(61,47,50,.42); backdrop-filter: blur(6px)"
                    @click="emit('close')"
                />

                <div
                    class="relative flex flex-col w-full max-w-[1040px] h-[100dvh] sm:h-[min(90dvh,760px)]
                           overflow-hidden bg-surface sm:rounded-[var(--r-2xl)] shadow-[var(--shadow-lifted)]"
                >
                    <!-- Header -->
                    <div class="flex items-start gap-4 px-6 sm:px-8 pt-6 pb-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold tracking-[0.16em] uppercase text-primary mb-2">
                                Punto de venta · Cobrar
                            </p>
                            <h2 id="pos-checkout-title" class="serif text-2xl sm:text-3xl leading-tight text-on-surface">
                                Total {{ formatCents(totalCents) }}
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="btn-icon ml-auto shrink-0"
                            aria-label="Cerrar"
                            data-testid="pos-checkout-close"
                            @click="emit('close')"
                        >
                            <X :size="20" />
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-[1fr_1.1fr] min-h-0 flex-1">
                        <!-- Left: sale recap (hidden on mobile to keep the keypad reachable) -->
                        <div class="hidden md:flex flex-col min-h-0 bg-surface-low">
                            <div class="overflow-y-auto px-6 pt-5 pb-3 flex-1">
                                <h3 class="serif text-lg text-on-surface mb-3">Venta</h3>
                                <div class="flex flex-col gap-2">
                                    <div
                                        v-for="l in lines"
                                        :key="l.variantId"
                                        class="flex items-center gap-3 p-3 rounded-[var(--r-md)] bg-surface-lowest shadow-[var(--shadow-ambient)]"
                                    >
                                        <span class="grid place-items-center w-7 h-7 rounded-full text-xs font-bold tabular-nums shrink-0"
                                              style="background: var(--primary-container); color: var(--primary-dim)">
                                            {{ l.quantity }}
                                        </span>
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-sm text-on-surface truncate">{{ l.productName }}</p>
                                            <p v-if="lineLabel(l)" class="text-xs text-on-surface-variant truncate">{{ lineLabel(l) }}</p>
                                        </div>
                                        <span class="font-semibold text-sm tabular-nums text-on-surface">
                                            {{ formatCents(l.priceCents * l.quantity) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div class="px-6 py-4 flex flex-col gap-1.5" style="background: var(--surface-mid)">
                                <div class="flex justify-between text-sm text-on-surface-variant">
                                    <span>Subtotal</span><span class="tabular-nums">{{ formatCents(subtotalCents) }}</span>
                                </div>
                                <div v-if="taxCents > 0" class="flex justify-between text-sm text-on-surface-variant">
                                    <span>IVA</span><span class="tabular-nums">{{ formatCents(taxCents) }}</span>
                                </div>
                                <div class="flex justify-between items-baseline pt-1">
                                    <span class="serif text-lg text-on-surface">Total</span>
                                    <span class="serif text-2xl font-semibold tabular-nums text-on-surface">{{ formatCents(totalCents) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Right: payment panel -->
                        <div class="flex flex-col min-h-0">
                            <div class="overflow-y-auto px-6 sm:px-8 pt-4 pb-3 flex-1">
                                <!-- Method -->
                                <p class="label-gilt mb-2">Método de pago</p>
                                <div class="grid grid-cols-3 gap-2 mb-5">
                                    <button
                                        v-for="m in methods"
                                        :key="m.id"
                                        type="button"
                                        class="py-3 rounded-xl flex flex-col items-center gap-1.5 text-xs font-semibold transition-all"
                                        :style="paymentMethod === m.id
                                            ? 'background: var(--primary-container); color: var(--primary-dim)'
                                            : 'background: var(--surface-lowest); color: var(--on-surface-variant)'"
                                        :data-testid="`pos-pay-${m.id}`"
                                        @click="emit('update:paymentMethod', m.id)"
                                    >
                                        <component :is="m.icon" :size="18" aria-hidden="true" />
                                        {{ m.label }}
                                    </button>
                                </div>

                                <!-- Cash: received + keypad + change -->
                                <template v-if="isCash">
                                    <div class="flex items-end justify-between gap-4 mb-3">
                                        <div>
                                            <p class="label-gilt mb-1">Recibí</p>
                                            <p class="serif text-3xl tabular-nums text-on-surface" data-testid="pos-received">
                                                {{ formatCents(receivedCents) }}
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="label-gilt mb-1">Vuelto</p>
                                            <p
                                                class="serif text-4xl font-semibold tabular-nums leading-none"
                                                :style="isShort ? 'color: var(--on-surface-variant)' : 'color: var(--primary)'"
                                                data-testid="pos-change"
                                            >
                                                {{ isShort ? '—' : formatCents(changeCents) }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap gap-2 mb-3">
                                        <button
                                            type="button"
                                            class="px-4 py-2 rounded-full text-sm font-semibold transition-[filter] hover:brightness-95"
                                            style="background: var(--primary-container); color: var(--primary-dim)"
                                            data-testid="pos-exact"
                                            @click="setExact"
                                        >
                                            Exacto
                                        </button>
                                        <button
                                            v-for="s in suggestions"
                                            :key="s"
                                            type="button"
                                            class="px-4 py-2 rounded-full text-sm font-semibold text-on-surface-variant transition-colors hover:text-on-surface"
                                            style="background: var(--surface-lowest)"
                                            @click="setAmount(s)"
                                        >
                                            {{ formatCents(s * 100) }}
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-3 gap-2">
                                        <button
                                            v-for="k in keypad"
                                            :key="k"
                                            type="button"
                                            class="h-14 rounded-[var(--r-lg)] grid place-items-center text-xl font-semibold text-on-surface bg-surface-lowest shadow-[var(--shadow-ambient)] transition-colors hover:bg-surface-mid"
                                            :aria-label="k === 'back' ? 'Borrar' : k"
                                            :data-testid="`pos-key-${k}`"
                                            @click="k === 'back' ? backspace() : pressDigit(k)"
                                        >
                                            <Delete v-if="k === 'back'" :size="18" aria-hidden="true" />
                                            <template v-else>{{ k }}</template>
                                        </button>
                                    </div>
                                    <p class="text-[11px] text-on-surface-variant text-center mt-3">
                                        Teclado: 0-9 . escribir · Enter cobrar · Esc cancelar
                                    </p>
                                </template>

                                <p v-else class="text-sm text-on-surface-variant py-6 text-center">
                                    Cobro con {{ paymentMethod === 'card' ? 'tarjeta' : 'transferencia' }}. Confirmá para registrar la venta.
                                </p>
                            </div>

                            <!-- Footer -->
                            <div class="flex items-center gap-3 px-6 sm:px-8 py-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                                <button
                                    type="button"
                                    class="btn btn-tertiary"
                                    :disabled="submitting"
                                    @click="emit('close')"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    class="btn-primary flex-1 justify-center disabled:opacity-45"
                                    :disabled="!canConfirm"
                                    data-testid="pos-checkout-confirm"
                                    @click="confirm"
                                >
                                    {{ submitting ? 'Procesando…' : `Confirmar ${formatCents(totalCents)}` }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
