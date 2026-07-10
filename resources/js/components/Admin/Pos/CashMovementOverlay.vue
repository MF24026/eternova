<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { X, ArrowUpFromLine, ArrowDownToLine } from 'lucide-vue-next'
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

// Tone: retiro is danger-hued, ingreso is success-hued. Drives header icon + accents.
const tone = computed(() => (type.value === 'out'
    ? { color: 'var(--error)', soft: 'color-mix(in srgb, var(--error) 12%, transparent)', label: 'Registrar salida' }
    : { color: 'var(--success)', soft: 'color-mix(in srgb, var(--success) 14%, transparent)', label: 'Registrar ingreso' }))

function toCents(input: string): number {
    const n = parseFloat(input.trim())
    return Number.isFinite(n) ? Math.round(n * 100) : 0
}
const amountCents = computed(() => toCents(amountText.value))
const valid = computed(() => amountCents.value > 0 && reason.value.trim().length > 0)

function addQuick(n: number): void {
    amountText.value = ((toCents(amountText.value) + n * 100) / 100).toFixed(2)
}
function clearAmount(): void {
    amountText.value = ''
}

// ESC closes the overlay while it is open.
function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') emit('close')
}

watch(() => props.show, (open) => {
    if (open) {
        type.value = 'out'
        amountText.value = ''
        reason.value = ''
        document.addEventListener('keydown', onKeydown)
    } else {
        document.removeEventListener('keydown', onKeydown)
    }
})
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

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
            <div v-if="show" class="fixed inset-0 z-[90]" role="dialog" aria-modal="true" data-testid="cash-movement-overlay">
                <div class="mv-sheet absolute inset-0 flex flex-col bg-surface">
                    <!-- Header -->
                    <header class="flex items-center gap-3.5 px-4 sm:px-6 py-3 shrink-0" style="border-bottom: 1px solid var(--outline-variant)">
                        <button type="button" class="btn-icon shrink-0" aria-label="Cerrar" @click="emit('close')"><X :size="21" /></button>
                        <span class="w-11 h-11 rounded-[var(--r-md)] grid place-items-center shrink-0 transition-colors" :style="{ background: tone.soft, color: tone.color }">
                            <component :is="type === 'out' ? ArrowUpFromLine : ArrowDownToLine" :size="22" />
                        </span>
                        <div class="min-w-0">
                            <div class="serif text-xl leading-tight text-on-surface truncate">Registrar movimiento</div>
                            <div class="text-xs text-on-surface-variant font-medium mt-0.5">Entrada o salida de efectivo de la caja</div>
                        </div>
                    </header>

                    <!-- Body -->
                    <main class="flex-1 min-h-0 overflow-y-auto px-4 sm:px-7 py-6">
                        <div class="mx-auto max-w-[520px] flex flex-col gap-6">
                            <!-- Type -->
                            <div class="grid grid-cols-2 gap-3">
                                <button type="button" data-testid="mv-type-out" class="mv-type"
                                        :style="type === 'out' ? { background: tone.soft, boxShadow: '0 0 0 2px var(--error)', color: 'var(--error)' } : ''" @click="type = 'out'">
                                    <ArrowUpFromLine :size="24" /><span>Salida / Retiro</span>
                                </button>
                                <button type="button" data-testid="mv-type-in" class="mv-type"
                                        :style="type === 'in' ? { background: 'color-mix(in srgb, var(--success) 14%, transparent)', boxShadow: '0 0 0 2px var(--success)', color: 'var(--success)' } : ''" @click="type = 'in'">
                                    <ArrowDownToLine :size="24" /><span>Entrada / Ingreso</span>
                                </button>
                            </div>

                            <!-- Amount -->
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.05em] text-on-surface-variant mb-2">Monto</p>
                                <div class="flex items-center gap-2 px-5 rounded-[var(--r-lg)]" style="height: 76px; background: var(--surface-low)"
                                     :style="{ boxShadow: `0 0 0 2px ${tone.color}` }">
                                    <span class="serif text-on-surface-variant" style="font-size: 30px">$</span>
                                    <input v-model="amountText" type="text" inputmode="decimal" placeholder="0.00" data-testid="mv-amount"
                                           class="grow bg-transparent border-0 outline-none serif text-on-surface tabular-nums text-right" style="font-size: 40px" />
                                </div>
                                <div class="flex gap-2 mt-3 flex-wrap">
                                    <button v-for="n in quickAmounts" :key="n" type="button" class="mv-chip tabular-nums" @click="addQuick(n)">+{{ n }}</button>
                                    <button v-if="amountText.trim()" type="button" class="mv-chip" @click="clearAmount">Borrar</button>
                                </div>
                            </div>

                            <!-- Reason -->
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.05em] text-on-surface-variant mb-2">Motivo</p>
                                <input v-model="reason" type="text" placeholder="Ej: pago a proveedor" class="field text-sm" data-testid="mv-reason" />
                                <div class="flex gap-2 mt-3 flex-wrap">
                                    <button v-for="m in reasons" :key="m" type="button" class="mv-chip" @click="reason = m">{{ m }}</button>
                                </div>
                            </div>
                        </div>
                    </main>

                    <!-- Footer -->
                    <footer class="flex gap-3 px-4 sm:px-7 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shrink-0" style="border-top: 1px solid var(--outline-variant); background: var(--surface-low)">
                        <div class="mx-auto w-full max-w-[520px] flex gap-3">
                            <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">Cancelar</button>
                            <button type="button" class="btn-primary flex-[1.4]" :style="{ background: tone.color }" :disabled="!valid || submitting" data-testid="mv-submit" @click="onSubmit">
                                {{ tone.label }} · {{ formatCents(amountCents) }}
                            </button>
                        </div>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.mv-sheet { animation: mv-rise .32s cubic-bezier(.2, .9, .3, 1); }
@keyframes mv-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
.mv-type {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 18px;
    border-radius: var(--r-lg);
    background: var(--surface-low);
    color: var(--on-surface-variant);
    font-size: 13.5px;
    font-weight: 650;
    cursor: pointer;
    transition: background .15s, color .15s, box-shadow .15s;
}
.mv-chip {
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    background: var(--surface-low);
    color: var(--on-surface-variant);
    font-weight: 650;
    font-size: 13.5px;
    box-shadow: inset 0 0 0 1.5px var(--outline-soft);
    cursor: pointer;
    transition: background .15s, color .15s;
}
.mv-chip:hover { color: var(--on-surface); background: var(--surface-mid); }
@media (prefers-reduced-motion: reduce) {
    .mv-sheet { animation: none; }
}
</style>
