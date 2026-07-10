<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { X, LockOpen, Lock, Check } from 'lucide-vue-next'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import type { CashRegisterSession } from '@/types/domain/POS'

const props = defineProps<{
    show: boolean
    mode: 'open' | 'close'
    session: CashRegisterSession | null
    submitting?: boolean
    result?: CashRegisterSession | null // the closed session — shows the arqueo celebration
}>()

const emit = defineEmits<{
    close: []
    confirm: [{ amountCents: number; notes: string }]
    done: []
}>()

const { formatCents } = useFormatCurrency()

const amountText = ref('')
const notes = ref('')

// Common USD bill denominations for the quick-add pad.
const denoms = [100, 50, 20, 10, 5, 1]

function toCents(input: string): number {
    const n = parseFloat(input.trim())
    return Number.isFinite(n) ? Math.round(n * 100) : 0
}
const countedCents = computed(() => toCents(amountText.value))

const expectedCents = computed(() => props.session?.expected_cash_cents ?? 0)
const differenceCents = computed(() => countedCents.value - expectedCents.value)
const counted = computed(() => amountText.value.trim() !== '')

// Arqueo state: idle until counted, then cuadra / sobrante / faltante.
type DiffState = 'idle' | 'ok' | 'over' | 'short'
const diffState = computed<DiffState>(() => {
    if (!counted.value) return 'idle'
    if (differenceCents.value === 0) return 'ok'
    return differenceCents.value > 0 ? 'over' : 'short'
})
const diffMeta = computed(() => ({
    idle: { color: 'var(--on-surface-variant)', bg: 'var(--surface-mid)', label: 'Diferencia', hint: 'Contá el efectivo físico para comparar.' },
    ok: { color: 'var(--success)', bg: 'color-mix(in srgb, var(--success) 12%, transparent)', label: 'Sin diferencia', hint: 'Cuadra: el efectivo coincide con lo esperado.' },
    over: { color: 'var(--warning)', bg: 'color-mix(in srgb, var(--warning) 14%, transparent)', label: 'Sobrante', hint: 'Hay más efectivo del esperado en el cajón.' },
    short: { color: 'var(--error)', bg: 'color-mix(in srgb, var(--error) 12%, transparent)', label: 'Faltante', hint: 'Hay menos efectivo del esperado en el cajón.' },
}[diffState.value]))

function addAmount(dollars: number): void {
    amountText.value = ((toCents(amountText.value) + dollars * 100) / 100).toFixed(2)
}
function useExpected(): void {
    amountText.value = (expectedCents.value / 100).toFixed(2)
}
function clearAmount(): void {
    amountText.value = ''
}

// ESC dismisses: the celebration screen when present, otherwise the overlay.
function onKeydown(e: KeyboardEvent): void {
    if (e.key !== 'Escape') return
    if (props.result) emit('done')
    else emit('close')
}

watch(() => props.show, (open) => {
    if (open) {
        amountText.value = ''
        notes.value = ''
        document.addEventListener('keydown', onKeydown)
    } else {
        document.removeEventListener('keydown', onKeydown)
    }
})
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

const title = computed(() => (props.mode === 'open' ? 'Abrir caja' : 'Cerrar caja · Arqueo'))
const subtitle = computed(() =>
    props.mode === 'open'
        ? 'Efectivo con el que inicia el turno'
        : props.session ? `Sesión #${props.session.session_number}` : 'Arqueo del turno',
)

function onSubmit(): void {
    emit('confirm', { amountCents: countedCents.value, notes: notes.value })
}

// Deterministic scatter for the celebration confetti (no external lib).
const CR_CONFETTI = ['var(--primary)', 'var(--success)', 'var(--secondary)', 'var(--warning)']
function confettiStyle(i: number): Record<string, string> {
    const left = (i * 37) % 100
    const delay = (i % 8) * 0.09
    const dur = 1 + ((i * 13) % 9) / 10
    const rot = (i * 47) % 360
    return {
        left: `${left}%`,
        background: CR_CONFETTI[i % CR_CONFETTI.length],
        animationDelay: `${delay}s`,
        animationDuration: `${dur}s`,
        transform: `rotate(${rot}deg)`,
    }
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-[90]" role="dialog" aria-modal="true" data-testid="cash-register-overlay">
                <!-- ── Celebration (after close) ─────────────────────────────── -->
                <div v-if="result" class="absolute inset-0 grid place-items-center p-5" style="background: var(--scrim); backdrop-filter: blur(6px)">
                    <div class="cr-pop relative w-[min(420px,92vw)] rounded-[var(--r-2xl)] p-9 text-center bg-surface shadow-[var(--shadow-lifted)] overflow-hidden">
                        <span v-for="i in 24" :key="i" class="cr-confetti" :style="confettiStyle(i)" aria-hidden="true" />
                        <span class="cr-badge w-[76px] h-[76px] rounded-full grid place-items-center mx-auto mb-4" style="background: var(--success); color: #fff"><Lock :size="38" /></span>
                        <h2 class="serif text-2xl text-on-surface">Caja cerrada</h2>
                        <p class="text-sm text-on-surface-variant mt-1.5">Sesión #{{ result.session_number }} · corte de {{ formatCents(result.closing_amount_cents ?? 0) }}</p>
                        <span class="inline-flex items-center gap-1.5 mt-4 px-3.5 py-1.5 rounded-full text-sm font-semibold"
                              :style="{ color: (result.difference_cents ?? 0) === 0 ? 'var(--success)' : (result.difference_cents ?? 0) > 0 ? 'var(--warning)' : 'var(--error)',
                                        background: (result.difference_cents ?? 0) === 0 ? 'color-mix(in srgb, var(--success) 12%, transparent)' : (result.difference_cents ?? 0) > 0 ? 'color-mix(in srgb, var(--warning) 14%, transparent)' : 'color-mix(in srgb, var(--error) 12%, transparent)' }">
                            <Check :size="16" />
                            {{ (result.difference_cents ?? 0) === 0 ? 'Sin diferencias' : `${(result.difference_cents ?? 0) > 0 ? 'Sobrante' : 'Faltante'} de ${formatCents(Math.abs(result.difference_cents ?? 0))}` }}
                        </span>
                        <button type="button" class="btn-primary w-full mt-6" data-testid="cr-done" @click="emit('done')">Listo</button>
                    </div>
                </div>

                <!-- ── Full-screen counting/opening ──────────────────────────── -->
                <div v-else class="cr-sheet absolute inset-0 flex flex-col bg-surface">
                    <!-- Header -->
                    <header class="flex items-center gap-3.5 px-4 sm:px-6 py-3 shrink-0" style="border-bottom: 1px solid var(--outline-variant)">
                        <button type="button" class="btn-icon shrink-0" aria-label="Cerrar" data-testid="cr-close-overlay" @click="emit('close')"><X :size="21" /></button>
                        <span class="w-11 h-11 rounded-[var(--r-md)] grid place-items-center shrink-0" style="background: var(--surface-low); color: var(--primary)">
                            <component :is="mode === 'open' ? LockOpen : Lock" :size="22" />
                        </span>
                        <div class="min-w-0">
                            <div class="serif text-xl leading-tight text-on-surface truncate">{{ title }}</div>
                            <div class="text-xs text-on-surface-variant font-medium mt-0.5">{{ subtitle }}</div>
                        </div>
                    </header>

                    <!-- Body: 2-col on desktop for close, single column for open -->
                    <div class="flex-1 min-h-0 flex" :class="mode === 'close' ? 'flex-col lg:flex-row' : 'flex-col'">
                        <main class="flex-1 min-h-0 overflow-y-auto px-4 sm:px-7 py-6">
                            <div class="mx-auto max-w-[560px] flex flex-col gap-6">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.05em] text-on-surface-variant mb-2">
                                        {{ mode === 'open' ? 'Fondo inicial' : '¿Cuánto efectivo contaste en el cajón?' }}
                                    </p>
                                    <!-- Big money input -->
                                    <div class="flex items-center gap-2 px-5 rounded-[var(--r-lg)]" style="height: 80px; background: var(--surface-low)"
                                         :style="{ boxShadow: `0 0 0 2px ${counted && mode === 'close' ? diffMeta.color : 'var(--primary)'}` }">
                                        <span class="serif text-on-surface-variant" style="font-size: 34px">$</span>
                                        <input v-model="amountText" type="text" inputmode="decimal" placeholder="0.00" data-testid="cr-amount"
                                               class="grow bg-transparent border-0 outline-none serif text-on-surface tabular-nums text-right" style="font-size: 44px" />
                                    </div>
                                    <!-- Quick pad -->
                                    <div class="flex gap-2 mt-3 flex-wrap">
                                        <button v-if="mode === 'close'" type="button" class="cr-chip cr-chip--accent" data-testid="cr-use-expected" @click="useExpected">Usar esperado</button>
                                        <button v-for="d in denoms" :key="d" type="button" class="cr-chip tabular-nums" @click="addAmount(d)">+{{ d }}</button>
                                        <button v-if="counted" type="button" class="cr-chip" @click="clearAmount">Borrar</button>
                                    </div>
                                </div>

                                <!-- Difference card (close) -->
                                <div v-if="mode === 'close'" class="rounded-[var(--r-lg)] px-5 py-4 flex items-center justify-between gap-3"
                                     :style="{ background: diffMeta.bg, boxShadow: diffState === 'idle' ? 'none' : `0 0 0 1.5px ${diffMeta.color}` }">
                                    <div class="min-w-0">
                                        <p class="text-xs font-bold uppercase tracking-[0.04em]" :style="{ color: diffMeta.color }">{{ diffMeta.label }}</p>
                                        <p class="text-sm text-on-surface-variant mt-1 max-w-[280px]">{{ diffMeta.hint }}</p>
                                    </div>
                                    <span class="serif tabular-nums shrink-0" style="font-size: 34px" :style="{ color: diffMeta.color }" data-testid="cr-difference">
                                        {{ counted ? (differenceCents > 0 ? '+' : '') + formatCents(differenceCents) : '—' }}
                                    </span>
                                </div>

                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[0.05em] text-on-surface-variant mb-2">Notas (opcional)</p>
                                    <textarea v-model="notes" rows="2" class="field text-sm" data-testid="cr-notes" placeholder="Explicá una diferencia, un pendiente…" />
                                </div>

                                <!-- Open-mode footer inline (single column) -->
                                <button v-if="mode === 'open'" type="button" class="btn-primary gap-2 self-stretch" style="min-height: 54px; font-size: 16px"
                                        :disabled="submitting" data-testid="cr-submit" @click="onSubmit">
                                    <LockOpen :size="18" /> Abrir caja · {{ formatCents(countedCents) }}
                                </button>
                            </div>
                        </main>

                        <!-- Ladder sidebar (close, desktop) -->
                        <aside v-if="mode === 'close' && session" class="hidden lg:flex flex-col gap-4 w-[340px] shrink-0 px-6 py-6 overflow-y-auto" style="border-left: 1px solid var(--outline-variant); background: var(--surface-low)">
                            <p class="text-xs font-bold uppercase tracking-[0.05em] text-on-surface-variant">Efectivo esperado</p>
                            <div class="flex flex-col gap-2.5">
                                <div class="flex justify-between text-sm"><span class="text-on-surface-variant">Fondo de apertura</span><span class="tabular-nums text-on-surface">+ {{ formatCents(session.opening_amount_cents) }}</span></div>
                                <div class="flex justify-between text-sm"><span class="text-on-surface-variant">Ventas en efectivo</span><span class="tabular-nums" style="color: var(--success)">+ {{ formatCents(session.cash_sales_cents) }}</span></div>
                                <div v-if="session.cash_in_cents > 0" class="flex justify-between text-sm"><span class="text-on-surface-variant">Ingresos</span><span class="tabular-nums" style="color: var(--success)">+ {{ formatCents(session.cash_in_cents) }}</span></div>
                                <div v-if="session.cash_out_cents > 0" class="flex justify-between text-sm"><span class="text-on-surface-variant">Retiros</span><span class="tabular-nums" style="color: var(--error)">− {{ formatCents(session.cash_out_cents) }}</span></div>
                            </div>
                            <div class="flex items-baseline justify-between pt-3" style="border-top: 1px solid var(--outline-variant)">
                                <span class="font-semibold text-on-surface">Esperado</span>
                                <span class="serif tabular-nums text-on-surface" style="font-size: 30px" data-testid="cr-expected">{{ formatCents(expectedCents) }}</span>
                            </div>
                            <div class="mt-auto flex flex-col gap-2.5">
                                <button type="button" class="btn-primary gap-2" style="min-height: 52px" :disabled="!counted || submitting" data-testid="cr-submit" @click="onSubmit"><Lock :size="18" /> Confirmar cierre</button>
                                <button type="button" class="btn btn-secondary" @click="emit('close')">Cancelar</button>
                            </div>
                        </aside>
                    </div>

                    <!-- Mobile footer (close) -->
                    <footer v-if="mode === 'close'" class="lg:hidden flex gap-3 px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] shrink-0" style="border-top: 1px solid var(--outline-variant); background: var(--surface-low)">
                        <button type="button" class="btn btn-secondary flex-1" @click="emit('close')">Cancelar</button>
                        <button type="button" class="btn-primary flex-[1.4] gap-2" :disabled="!counted || submitting" @click="onSubmit"><Lock :size="18" /> Confirmar cierre</button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.cr-chip {
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
.cr-chip:hover { color: var(--on-surface); background: var(--surface-mid); }
.cr-chip--accent {
    color: var(--primary);
    background: var(--primary-container);
    box-shadow: none;
}
.cr-sheet { animation: cr-rise .34s cubic-bezier(.2, .9, .3, 1); }
@keyframes cr-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
.cr-pop { animation: cr-pop .35s cubic-bezier(.3, 1.4, .5, 1); }
@keyframes cr-pop { from { opacity: 0; transform: scale(.9); } to { opacity: 1; transform: none; } }
.cr-badge { animation: cr-pop .45s cubic-bezier(.3, 1.5, .5, 1); }
.cr-confetti {
    position: absolute;
    top: -12px;
    width: 8px;
    height: 12px;
    border-radius: 2px;
    animation-name: cr-fall;
    animation-timing-function: ease-in;
    animation-iteration-count: 1;
    opacity: .9;
}
@keyframes cr-fall {
    to { transform: translateY(360px) rotate(360deg); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .cr-sheet, .cr-pop, .cr-badge, .cr-confetti { animation: none; }
    .cr-confetti { display: none; }
}
</style>
