<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { X, ArrowUp, ArrowDown } from 'lucide-vue-next'
import { useFormatCurrency } from '@/composables/useFormatCurrency'

const props = defineProps<{ show: boolean; submitting?: boolean }>()
const emit = defineEmits<{
    close: []
    confirm: [{ type: 'in' | 'out'; amountCents: number; reason: string }]
}>()

const { formatCents } = useFormatCurrency()

const type = ref<'in' | 'out'>('out')
const amountText = ref('')
const reason = ref('')

const quickAmounts = [5, 10, 20, 50, 100]
const reasonsOut = ['Pago a proveedor', 'Retiro a bóveda', 'Vale de empleado', 'Cambio / feria']
const reasonsIn = ['Fondo adicional', 'Devolución de vale', 'Depósito de cambio']
const reasons = computed(() => (type.value === 'out' ? reasonsOut : reasonsIn))

function toCents(input: string): number {
    const n = parseFloat(input.trim())
    return Number.isFinite(n) ? Math.round(n * 100) : 0
}
const amountCents = computed(() => toCents(amountText.value))
const valid = computed(() => amountCents.value > 0 && reason.value.trim().length > 0)

function addQuick(n: number): void {
    amountText.value = ((toCents(amountText.value) + n * 100) / 100).toFixed(2)
}

watch(() => props.show, (open) => {
    if (open) { type.value = 'out'; amountText.value = ''; reason.value = '' }
})

function onSubmit(): void {
    if (!valid.value) return
    emit('confirm', { type: type.value, amountCents: amountCents.value, reason: reason.value.trim() })
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-[90] grid place-items-center p-0 sm:p-5" role="dialog" aria-modal="true" data-testid="cash-movement-overlay">
                <div class="absolute inset-0" style="background: var(--scrim); backdrop-filter: blur(6px)" @click="emit('close')" />
                <div class="relative flex flex-col w-full max-w-[520px] h-[100dvh] sm:h-auto sm:max-h-[92dvh] overflow-hidden bg-surface sm:rounded-[var(--r-2xl)] shadow-[var(--shadow-lifted)]">
                    <div class="flex items-center gap-3 px-6 pt-6 pb-4">
                        <div class="min-w-0">
                            <h2 class="serif text-2xl leading-tight text-on-surface">Registrar movimiento</h2>
                            <p class="text-sm text-on-surface-variant mt-0.5">Entrada o salida de efectivo de la caja</p>
                        </div>
                        <button type="button" class="btn-icon ml-auto shrink-0" aria-label="Cerrar" @click="emit('close')"><X :size="20" /></button>
                    </div>

                    <div class="overflow-y-auto px-6 pb-6 flex-1 flex flex-col gap-5">
                        <!-- type -->
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                type="button" data-testid="mv-type-out"
                                class="flex flex-col items-center gap-2 p-4 rounded-[var(--r-lg)] transition-colors"
                                :style="type === 'out' ? 'background: color-mix(in srgb, var(--error) 12%, transparent); box-shadow: 0 0 0 2px var(--error); color: var(--error)' : 'background: var(--surface-low); color: var(--on-surface-variant)'"
                                @click="type = 'out'"
                            ><ArrowUp :size="24" /><span class="text-sm font-semibold">Salida / Retiro</span></button>
                            <button
                                type="button" data-testid="mv-type-in"
                                class="flex flex-col items-center gap-2 p-4 rounded-[var(--r-lg)] transition-colors"
                                :style="type === 'in' ? 'background: color-mix(in srgb, var(--success) 14%, transparent); box-shadow: 0 0 0 2px var(--success); color: var(--success)' : 'background: var(--surface-low); color: var(--on-surface-variant)'"
                                @click="type = 'in'"
                            ><ArrowDown :size="24" /><span class="text-sm font-semibold">Entrada / Ingreso</span></button>
                        </div>

                        <!-- amount -->
                        <div>
                            <label class="field-label mb-2">Monto</label>
                            <input v-model="amountText" type="text" inputmode="decimal" placeholder="0.00" class="field text-lg text-right tabular-nums" data-testid="mv-amount" />
                            <div class="flex gap-2 mt-2.5 flex-wrap">
                                <button v-for="n in quickAmounts" :key="n" type="button" class="btn btn-tertiary text-xs" @click="addQuick(n)">+{{ n }}</button>
                            </div>
                        </div>

                        <!-- reason -->
                        <div>
                            <label class="field-label mb-2">Motivo</label>
                            <input v-model="reason" type="text" placeholder="Ej: pago a proveedor" class="field text-sm" data-testid="mv-reason" />
                            <div class="flex gap-2 mt-2.5 flex-wrap">
                                <button v-for="m in reasons" :key="m" type="button" class="btn btn-tertiary text-xs" @click="reason = m">{{ m }}</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 px-6 py-4 pb-[calc(1rem+env(safe-area-inset-bottom))]" style="background: var(--surface-low)">
                        <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">Cancelar</button>
                        <button type="button" class="btn-primary flex-1" :disabled="!valid || submitting" data-testid="mv-submit" @click="onSubmit">
                            {{ type === 'out' ? 'Registrar salida' : 'Registrar ingreso' }} · {{ formatCents(amountCents) }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
