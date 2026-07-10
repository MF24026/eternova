<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { X, LockOpen, Lock } from 'lucide-vue-next'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import type { CashRegisterSession } from '@/types/domain/POS'

const props = defineProps<{
    show: boolean
    mode: 'open' | 'close'
    session: CashRegisterSession | null
    submitting?: boolean
}>()

const emit = defineEmits<{
    close: []
    confirm: [{ amountCents: number; notes: string }]
}>()

const { formatCents } = useFormatCurrency()

// Money inputs are entered in the tenant currency's main unit; we store cents.
const amountText = ref('')
const notes = ref('')

function toCents(input: string): number {
    const n = parseFloat(input.trim())
    return Number.isFinite(n) ? Math.round(n * 100) : 0
}

const countedCents = computed(() => toCents(amountText.value))

// Arqueo (close mode): expected = opening + cash sales + cash-in − cash-out (the ladder,
// computed server-side as expected_cash_cents). Difference = counted − expected.
const expectedCents = computed(() => props.session?.expected_cash_cents ?? 0)
const differenceCents = computed(() => countedCents.value - expectedCents.value)

function useExpected(): void {
    amountText.value = (expectedCents.value / 100).toFixed(2)
}

watch(
    () => props.show,
    (open) => {
        if (open) {
            amountText.value = ''
            notes.value = ''
        }
    },
)

function onSubmit(): void {
    emit('confirm', { amountCents: countedCents.value, notes: notes.value })
}
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
                data-testid="cash-register-overlay"
            >
                <div
                    class="absolute inset-0"
                    style="background: var(--scrim); backdrop-filter: blur(6px)"
                    @click="emit('close')"
                />

                <div
                    class="relative flex flex-col w-full max-w-[460px] h-[100dvh] sm:h-auto sm:max-h-[90dvh]
                           overflow-hidden bg-surface sm:rounded-[var(--r-2xl)] shadow-[var(--shadow-lifted)]"
                >
                    <div class="flex items-start gap-4 px-6 pt-6 pb-4">
                        <span class="w-10 h-10 rounded-full grid place-items-center shrink-0"
                              style="background: var(--surface-low); color: var(--primary)">
                            <component :is="mode === 'open' ? LockOpen : Lock" :size="20" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="serif text-2xl leading-tight text-on-surface">
                                {{ mode === 'open' ? 'Abrir caja' : 'Cerrar caja' }}
                            </h2>
                            <p class="text-sm text-on-surface-variant mt-0.5">
                                {{ mode === 'open' ? 'Efectivo con el que inicia el turno' : 'Contá el efectivo para el arqueo' }}
                            </p>
                        </div>
                        <button type="button" class="btn-icon ml-auto shrink-0" aria-label="Cerrar" data-testid="cr-close-overlay" @click="emit('close')">
                            <X :size="20" />
                        </button>
                    </div>

                    <div class="overflow-y-auto px-6 pb-6 flex-1 flex flex-col gap-4">
                        <!-- Close mode: arqueo ladder -->
                        <div v-if="mode === 'close' && session" class="rounded-[var(--r-lg)] p-4 flex flex-col gap-2" style="background: var(--surface-low)">
                            <div class="flex justify-between text-sm"><span class="text-on-surface-variant">Fondo de apertura</span><span class="tabular-nums text-on-surface">+ {{ formatCents(session.opening_amount_cents) }}</span></div>
                            <div class="flex justify-between text-sm"><span class="text-on-surface-variant">Ventas en efectivo</span><span class="tabular-nums" style="color: var(--success)">+ {{ formatCents(session.cash_sales_cents) }}</span></div>
                            <div v-if="session.cash_in_cents > 0" class="flex justify-between text-sm"><span class="text-on-surface-variant">Ingresos manuales</span><span class="tabular-nums" style="color: var(--success)">+ {{ formatCents(session.cash_in_cents) }}</span></div>
                            <div v-if="session.cash_out_cents > 0" class="flex justify-between text-sm"><span class="text-on-surface-variant">Salidas / retiros</span><span class="tabular-nums" style="color: var(--error)">− {{ formatCents(session.cash_out_cents) }}</span></div>
                            <div class="flex justify-between text-sm font-semibold pt-2 mt-1" style="border-top: 1px solid var(--outline-variant)"><span class="text-on-surface">Efectivo esperado</span><span class="tabular-nums text-on-surface" data-testid="cr-expected">{{ formatCents(expectedCents) }}</span></div>
                        </div>

                        <label class="field-label">{{ mode === 'open' ? 'Fondo inicial' : '¿Cuánto contaste en el cajón?' }}</label>
                        <input
                            v-model="amountText"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                            class="field text-lg text-right tabular-nums"
                            data-testid="cr-amount"
                        />
                        <button v-if="mode === 'close'" type="button" class="btn btn-tertiary text-xs self-start" data-testid="cr-use-expected" @click="useExpected">Usar esperado</button>

                        <!-- Live difference (close mode) -->
                        <div v-if="mode === 'close'" class="flex justify-between items-center px-1">
                            <span class="text-sm text-on-surface-variant">Diferencia</span>
                            <span
                                class="text-lg font-bold tabular-nums"
                                data-testid="cr-difference"
                                :style="{ color: differenceCents === 0 ? 'var(--success)' : differenceCents > 0 ? 'var(--primary)' : 'var(--error)' }"
                            >
                                {{ differenceCents > 0 ? '+' : '' }}{{ formatCents(differenceCents) }}
                            </span>
                        </div>

                        <label class="field-label">Notas (opcional)</label>
                        <textarea v-model="notes" rows="2" class="field text-sm" data-testid="cr-notes" />
                    </div>

                    <div class="flex gap-3 px-6 py-4 pb-[calc(1rem+env(safe-area-inset-bottom))]" style="background: var(--surface-low)">
                        <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">Cancelar</button>
                        <button
                            type="button"
                            class="btn-primary flex-1"
                            :disabled="submitting"
                            data-testid="cr-submit"
                            @click="onSubmit"
                        >
                            {{ mode === 'open' ? 'Abrir caja' : 'Cerrar caja' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
