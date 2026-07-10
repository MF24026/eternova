<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Wallet, Lock, LockOpen, User as UserIcon, Clock, Plus, ArrowUp, ArrowDown, Receipt, CreditCard } from 'lucide-vue-next'
import CashRegisterOverlay from '@/components/Admin/Pos/CashRegisterOverlay.vue'
import CashMovementOverlay from '@/components/Admin/Pos/CashMovementOverlay.vue'
import { usePosStore } from '@/stores/pos'
import { useBranches } from '@/composables/useBranches'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useCashRegister } from '@/composables/useCashRegister'
import { useToast } from '@/composables/useToast'
import { useAuth } from '@/composables/useAuth'
import type { CashRegisterSession } from '@/types/domain/POS'

const store = usePosStore()
const { branches, loadBranches } = useBranches()
const { formatCents } = useFormatCurrency()
const { currentUser } = useAuth()
const cashRegister = useCashRegister()
const toast = useToast()

const branchName = computed(() => branches.value.find((b) => b.id === store.branchId)?.name ?? '')
const cashierName = computed(() => currentUser.value?.name ?? 'Cajero')
const session = computed(() => cashRegister.session.value)
const isOpen = computed(() => session.value !== null)

const durationLabel = computed(() => {
    if (!session.value?.opened_at) return ''
    const mins = Math.max(0, Math.round((Date.now() - new Date(session.value.opened_at).getTime()) / 60000))
    return mins >= 60 ? `${(mins / 60).toFixed(1)} h` : `${mins} min`
})

const paymentMethods = computed(() => {
    const s = session.value
    if (!s) return []
    const total = s.total_sales_cents || 1
    return [
        { label: 'Efectivo', cents: s.cash_sales_cents, color: 'var(--success)' },
        { label: 'Tarjeta', cents: s.card_sales_cents, color: 'var(--info)' },
        { label: 'Transferencia', cents: s.transfer_sales_cents, color: 'var(--primary)' },
    ].map((m) => ({ ...m, pct: Math.round((m.cents / total) * 100) }))
})

// ── Overlays ────────────────────────────────────────────────────────────────
const crOverlayOpen = ref(false)
const crMode = ref<'open' | 'close'>('open')
const crSubmitting = ref(false)
// The closed session drives the overlay's arqueo celebration; kept until the
// cashier dismisses it, so the session banner below has already flipped to closed.
const crResult = ref<CashRegisterSession | null>(null)
const mvOverlayOpen = ref(false)
const mvSubmitting = ref(false)

function openRegister(): void { crMode.value = 'open'; crResult.value = null; crOverlayOpen.value = true }
function closeRegister(): void { crMode.value = 'close'; crResult.value = null; crOverlayOpen.value = true }

async function handleRegisterConfirm(payload: { amountCents: number; notes: string }): Promise<void> {
    crSubmitting.value = true
    try {
        if (crMode.value === 'open') {
            await cashRegister.open(store.branchId, payload.amountCents, payload.notes)
            toast.success('Caja abierta')
            crOverlayOpen.value = false
        } else {
            const closed = await cashRegister.close(payload.amountCents, payload.notes)
            // Hold the overlay open on its celebration screen until "Listo".
            crResult.value = closed
        }
    } catch {
        toast.error('No se pudo completar la operación de caja.')
    } finally {
        crSubmitting.value = false
    }
}

function handleRegisterDone(): void {
    crOverlayOpen.value = false
    crResult.value = null
}

async function handleMovementConfirm(payload: { type: 'in' | 'out'; amountCents: number; reason: string }): Promise<void> {
    mvSubmitting.value = true
    try {
        await cashRegister.addMovement(payload.type, payload.amountCents, payload.reason)
        toast.success(payload.type === 'in' ? 'Ingreso registrado' : 'Salida registrada')
        mvOverlayOpen.value = false
    } catch {
        toast.error('No se pudo registrar el movimiento.')
    } finally {
        mvSubmitting.value = false
    }
}

onMounted(async () => {
    document.title = 'Caja — Eternova'
    await loadBranches()
    if (store.branchId === '') {
        const main = branches.value.find((b) => b.is_main) ?? branches.value[0]
        if (main) store.branchId = main.id
    }
    void cashRegister.refresh(store.branchId)
})
</script>

<template>
    <div class="flex flex-col gap-4 max-w-[1080px]">
        <!-- Session banner -->
        <div class="card flex items-center gap-4 flex-wrap" style="padding: 18px 22px">
            <span class="w-12 h-12 rounded-[var(--r-lg)] grid place-items-center shrink-0"
                  :style="isOpen ? 'background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success)' : 'background: var(--surface-mid); color: var(--on-surface-variant)'">
                <Wallet :size="26" />
            </span>
            <div class="grow min-w-[220px]">
                <div class="flex items-center gap-3">
                    <h1 class="serif text-2xl text-on-surface">Gestión de caja</h1>
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full"
                          :style="isOpen ? 'background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success)' : 'background: var(--surface-mid); color: var(--on-surface-variant)'"
                          data-testid="caja-status">
                        <span class="w-2 h-2 rounded-full" style="background: currentColor" />{{ isOpen ? 'Caja abierta' : 'Caja cerrada' }}
                    </span>
                </div>
                <div v-if="session" class="text-sm text-on-surface-variant mt-1 flex gap-4 flex-wrap">
                    <span>Sesión <b class="text-on-surface tabular-nums">#{{ session.session_number }}</b></span>
                    <span class="inline-flex items-center gap-1"><UserIcon :size="14" />{{ cashierName }}</span>
                    <span class="inline-flex items-center gap-1"><Clock :size="14" /><b class="text-on-surface tabular-nums">{{ durationLabel }}</b></span>
                    <span v-if="branchName">· {{ branchName }}</span>
                </div>
                <p v-else class="text-sm text-on-surface-variant mt-1">Abrí la caja para empezar el turno en {{ branchName || 'tu sucursal' }}.</p>
            </div>
            <button v-if="!isOpen" type="button" class="btn-primary gap-2" data-testid="caja-abrir" @click="openRegister"><LockOpen :size="16" /> Abrir caja</button>
        </div>

        <template v-if="session">
            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="card" style="padding: 15px 17px"><p class="text-xs font-bold uppercase tracking-[0.04em] text-on-surface-variant mb-1.5">Fondo inicial</p><p class="serif text-2xl text-on-surface tabular-nums">{{ formatCents(session.opening_amount_cents) }}</p></div>
                <div class="card" style="padding: 15px 17px"><p class="text-xs font-bold uppercase tracking-[0.04em] text-on-surface-variant mb-1.5">Ventas efectivo</p><p class="serif text-2xl tabular-nums" style="color: var(--success)">{{ formatCents(session.cash_sales_cents) }}</p></div>
                <div class="card" style="padding: 15px 17px"><p class="text-xs font-bold uppercase tracking-[0.04em] text-on-surface-variant mb-1.5">Total ventas</p><p class="serif text-2xl text-on-surface tabular-nums">{{ formatCents(session.total_sales_cents) }}</p></div>
                <div class="card" style="padding: 15px 17px"><p class="text-xs font-bold uppercase tracking-[0.04em] text-on-surface-variant mb-1.5">Transacciones</p><p class="serif text-2xl text-on-surface tabular-nums">{{ session.order_count }}</p></div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[1.15fr_1fr] gap-4 items-start">
                <!-- Arqueo hero -->
                <div class="card overflow-hidden" style="padding: 0; box-shadow: 0 0 0 2px var(--primary), var(--shadow-ambient)">
                    <div class="flex items-center gap-2 px-5 py-4" style="border-bottom: 1px solid var(--outline-variant)">
                        <p class="font-semibold text-on-surface">Arqueo · efectivo esperado en caja</p>
                        <button type="button" class="btn btn-tertiary text-xs ml-auto gap-1.5" data-testid="caja-movimiento" @click="mvOverlayOpen = true"><Plus :size="14" /> Movimiento</button>
                    </div>
                    <div class="px-5 py-4 flex flex-col gap-2.5">
                        <div class="flex justify-between text-sm"><span class="text-on-surface-variant">Fondo de apertura</span><span class="tabular-nums text-on-surface">+ {{ formatCents(session.opening_amount_cents) }}</span></div>
                        <div class="flex justify-between text-sm"><span class="text-on-surface-variant">Ventas en efectivo</span><span class="tabular-nums" style="color: var(--success)">+ {{ formatCents(session.cash_sales_cents) }}</span></div>
                        <div v-if="session.cash_in_cents > 0" class="flex justify-between text-sm"><span class="text-on-surface-variant">Ingresos manuales</span><span class="tabular-nums" style="color: var(--success)">+ {{ formatCents(session.cash_in_cents) }}</span></div>
                        <div v-if="session.cash_out_cents > 0" class="flex justify-between text-sm"><span class="text-on-surface-variant">Salidas / retiros</span><span class="tabular-nums" style="color: var(--error)">− {{ formatCents(session.cash_out_cents) }}</span></div>
                        <div class="flex items-baseline justify-between pt-3 mt-1" style="border-top: 2px solid var(--outline-variant)">
                            <div><p class="text-xs font-bold uppercase tracking-[0.05em] text-on-surface-variant">Esperado en caja</p><p class="text-xs text-on-surface-variant">debería haber físicamente</p></div>
                            <p class="serif tabular-nums" style="font-size: 40px; line-height: 1; color: var(--primary)" data-testid="caja-esperado">{{ formatCents(session.expected_cash_cents) }}</p>
                        </div>
                        <button type="button" class="btn-primary gap-2 mt-2" style="min-height: 52px" data-testid="caja-cerrar" @click="closeRegister"><Lock :size="18" /> Cerrar caja y hacer corte</button>
                    </div>
                </div>

                <!-- Payment breakdown -->
                <div class="card" style="padding: 16px 20px">
                    <p class="font-semibold text-on-surface mb-4">Desglose por método de pago</p>
                    <div class="flex flex-col gap-3.5">
                        <div v-for="m in paymentMethods" :key="m.label">
                            <div class="flex justify-between items-baseline mb-1.5">
                                <span class="text-sm font-medium text-on-surface-variant">{{ m.label }} <span class="tabular-nums text-on-surface-variant/70">· {{ m.pct }}%</span></span>
                                <span class="tabular-nums font-semibold text-sm text-on-surface">{{ formatCents(m.cents) }}</span>
                            </div>
                            <div class="h-2 rounded-full overflow-hidden" style="background: var(--surface-mid)"><div class="h-full rounded-full transition-[width]" :style="{ width: `${m.pct}%`, background: m.color }" /></div>
                        </div>
                    </div>
                    <div class="flex justify-between items-baseline mt-4 pt-3" style="border-top: 1px solid var(--outline-variant)">
                        <span class="font-semibold text-on-surface">Total ventas</span>
                        <span class="serif text-xl tabular-nums" style="color: var(--success)">{{ formatCents(session.total_sales_cents) }}</span>
                    </div>
                </div>
            </div>

            <!-- Movements -->
            <div class="card" style="padding: 16px 20px">
                <div class="flex items-center gap-2 mb-2">
                    <p class="font-semibold text-on-surface">Movimientos de caja</p>
                    <button type="button" class="btn btn-tertiary text-xs ml-auto gap-1.5" @click="mvOverlayOpen = true"><Plus :size="14" /> Registrar</button>
                </div>
                <div class="flex items-center gap-3 py-3" style="border-bottom: 1px solid var(--outline-variant)">
                    <span class="w-9 h-9 rounded-[var(--r-md)] grid place-items-center shrink-0" style="background: var(--surface-mid); color: var(--on-surface-variant)"><Wallet :size="18" /></span>
                    <div class="grow"><p class="text-sm font-medium text-on-surface">Apertura de caja</p><p class="text-xs text-on-surface-variant">Fondo inicial</p></div>
                    <span class="tabular-nums font-semibold text-sm" style="color: var(--success)">+ {{ formatCents(session.opening_amount_cents) }}</span>
                </div>
                <div v-if="session.cash_sales_cents > 0" class="flex items-center gap-3 py-3" style="border-bottom: 1px solid var(--outline-variant)">
                    <span class="w-9 h-9 rounded-[var(--r-md)] grid place-items-center shrink-0" style="background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success)"><Receipt :size="18" /></span>
                    <div class="grow"><p class="text-sm font-medium text-on-surface">Ventas en efectivo</p><p class="text-xs text-on-surface-variant">{{ session.order_count }} transacciones</p></div>
                    <span class="tabular-nums font-semibold text-sm" style="color: var(--success)">+ {{ formatCents(session.cash_sales_cents) }}</span>
                </div>
                <div v-for="mv in session.movements" :key="mv.id" class="flex items-center gap-3 py-3" style="border-bottom: 1px solid var(--outline-variant)">
                    <span class="w-9 h-9 rounded-[var(--r-md)] grid place-items-center shrink-0"
                          :style="mv.type === 'out' ? 'background: color-mix(in srgb, var(--error) 12%, transparent); color: var(--error)' : 'background: color-mix(in srgb, var(--success) 14%, transparent); color: var(--success)'">
                        <component :is="mv.type === 'out' ? ArrowUp : ArrowDown" :size="18" />
                    </span>
                    <div class="grow min-w-0"><p class="text-sm font-medium text-on-surface truncate">{{ mv.reason }}</p><p class="text-xs text-on-surface-variant">{{ mv.type === 'out' ? 'Salida' : 'Ingreso' }} de efectivo</p></div>
                    <span class="tabular-nums font-semibold text-sm" :style="{ color: mv.type === 'out' ? 'var(--error)' : 'var(--success)' }">{{ mv.type === 'out' ? '−' : '+' }} {{ formatCents(mv.amount_cents) }}</span>
                </div>
                <div class="flex justify-between items-baseline pt-3.5">
                    <span class="font-semibold text-on-surface-variant">Efectivo esperado</span>
                    <span class="serif text-xl tabular-nums" style="color: var(--primary)">{{ formatCents(session.expected_cash_cents) }}</span>
                </div>
            </div>

            <div class="flex gap-2 flex-wrap">
                <button type="button" class="btn btn-tertiary gap-2" @click="$router.push('/admin/pos')"><CreditCard :size="16" /> Ir al POS</button>
            </div>
        </template>

        <CashRegisterOverlay :show="crOverlayOpen" :mode="crMode" :session="session" :result="crResult" :submitting="crSubmitting" @close="crOverlayOpen = false" @confirm="handleRegisterConfirm" @done="handleRegisterDone" />
        <CashMovementOverlay :show="mvOverlayOpen" :submitting="mvSubmitting" @close="mvOverlayOpen = false" @confirm="handleMovementConfirm" />
    </div>
</template>
