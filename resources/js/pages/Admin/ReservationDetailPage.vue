<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
    ArrowLeft,
    User,
    MapPin,
    Calendar,
    FileText,
    ChevronRight,
    CheckCircle,
    Circle,
    XCircle,
    Clock,
    AlertTriangle,
    CreditCard,
    Plus,
    Package,
    Lock,
} from 'lucide-vue-next'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCard from '@/components/base/AppCard.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ReservationService from '@/services/ReservationService'
import TeamService from '@/services/TeamService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { useToast } from '@/composables/useToast'
import {
    RESERVATION_STATUS_LABELS,
    RESERVATION_STATUS_VARIANT,
} from '@/constants/reservations'
import type {
    ReservationDetail,
    ReservationStatus,
} from '@/types/domain/Reservation'
import type { TeamMember } from '@/types/domain/Team'

// ── Route & navigation ────────────────────────────────────────────────────────

const route = useRoute()
const router = useRouter()
const reservationId = route.params.id as string

// ── Composables ───────────────────────────────────────────────────────────────

const { formatCents } = useFormatCurrency()
const { formatDate, formatDateTime } = useFormatDate()
const toast = useToast()

// ── State ─────────────────────────────────────────────────────────────────────

type PageState = 'loading' | 'loaded' | 'not-found' | 'error'

const pageState = ref<PageState>('loading')
const reservation = ref<ReservationDetail | null>(null)
const teamMembers = ref<TeamMember[]>([])

// Status transition state
const transitioningTo = ref<ReservationStatus | null>(null)
// deposit-gate: when the server returns 422 "deposit required" on inquiry→confirmed
const depositGateMessage = ref<string | null>(null)
const isForcingTransition = ref(false)
const pendingForceStatus = ref<ReservationStatus | null>(null)

// Cancel two-step confirm
const cancelConfirmVisible = ref(false)
const isCancelling = ref(false)

// Assignee
const isAssigning = ref(false)

// Payment form
const paymentFormVisible = ref(false)
const paymentAmount = ref('')
const paymentMethod = ref<'cash' | 'card' | 'transfer' | 'other'>('cash')
const paymentReference = ref('')
const isRecordingPayment = ref(false)
const paymentFieldErrors = ref<Record<string, string>>({})

// Convert to order
const isConverting = ref(false)
const convertConfirmVisible = ref(false)
// Stores the result after a successful conversion for display purposes
const convertedOrderId = ref<string | null>(null)

// ── Data loading ──────────────────────────────────────────────────────────────

async function loadReservation(): Promise<void> {
    pageState.value = 'loading'
    try {
        reservation.value = await ReservationService.get(reservationId)
        pageState.value = 'loaded'
        document.title = `Reserva ${reservation.value.reservation_number} — Eternova`
    } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status
        pageState.value = status === 404 ? 'not-found' : 'error'
    }
}

async function loadTeam(): Promise<void> {
    try {
        teamMembers.value = await TeamService.list()
    } catch {
        // Non-critical — assignee selector will be empty but page still works
    }
}

onMounted(() => {
    void Promise.all([loadReservation(), loadTeam()])
})

// ── Computed ──────────────────────────────────────────────────────────────────

// Forward-progression transitions only. 'cancelled' is excluded — cancel has its own destructive button.
const advanceTransitions = computed<ReservationStatus[]>(() => {
    if (!reservation.value) return []
    return reservation.value.allowed_transitions.filter((s) => s !== 'cancelled')
})

// Show cancel when not in a terminal state
const showCancelAction = computed<boolean>(() => {
    if (!reservation.value) return false
    const terminal: ReservationStatus[] = ['delivered', 'cancelled']
    return !terminal.includes(reservation.value.status)
})

// Show convert button when not already converted and not cancelled
const showConvertButton = computed<boolean>(() => {
    if (!reservation.value) return false
    return (
        reservation.value.converted_order_id === null &&
        reservation.value.status !== 'cancelled'
    )
})

const currentAssigneeId = computed<string>(() => {
    return reservation.value?.assignee?.id?.toString() ?? ''
})

// Timeline newest-first
const timelineEntries = computed(() => {
    if (!reservation.value?.status_history) return []
    return [...reservation.value.status_history].reverse()
})

// Parse payment amount input to cents
function parsePaymentCents(raw: string): number {
    const cleaned = raw.replace(/[^0-9.]/g, '')
    const n = parseFloat(cleaned)
    if (isNaN(n) || n <= 0) return 0
    return Math.round(n * 100)
}

// ── Status transitions ────────────────────────────────────────────────────────

async function advanceToStatus(status: ReservationStatus, force = false): Promise<void> {
    if (!reservation.value || transitioningTo.value !== null) return

    // Clear any prior deposit-gate message when retrying
    if (force) {
        depositGateMessage.value = null
        isForcingTransition.value = true
        pendingForceStatus.value = null
    }

    transitioningTo.value = status
    try {
        reservation.value = await ReservationService.transition(reservationId, status, undefined, force)
        toast.success(`Reserva marcada como "${RESERVATION_STATUS_LABELS[status]}"`)
        depositGateMessage.value = null
    } catch (err: unknown) {
        const apiErr = err as { response?: { status?: number; data?: { message?: string; error_code?: string } } }
        const message = apiErr.response?.data?.message ?? 'No se pudo cambiar el estado.'

        // Deposit-gate: backend returns 422 with a specific message when the deposit isn't covered
        if (apiErr.response?.status === 422) {
            depositGateMessage.value = message
            pendingForceStatus.value = status
        } else {
            toast.error(message)
        }
    } finally {
        transitioningTo.value = null
        isForcingTransition.value = false
    }
}

function dismissDepositGate(): void {
    depositGateMessage.value = null
    pendingForceStatus.value = null
}

// ── Cancel ────────────────────────────────────────────────────────────────────

async function cancelReservation(): Promise<void> {
    if (!reservation.value || isCancelling.value) return

    isCancelling.value = true
    cancelConfirmVisible.value = false
    try {
        reservation.value = await ReservationService.cancel(reservationId)
        toast.success('Reserva cancelada.')
    } catch (err: unknown) {
        const message = extractApiMessage(err) ?? 'No se pudo cancelar la reserva.'
        toast.error(message)
    } finally {
        isCancelling.value = false
    }
}

// ── Assignee ──────────────────────────────────────────────────────────────────

async function onAssigneeChange(event: Event): Promise<void> {
    if (!reservation.value || isAssigning.value) return

    const select = event.target as HTMLSelectElement
    const rawValue = select.value
    const assignedTo: number | null = rawValue === '' ? null : Number(rawValue)

    isAssigning.value = true
    try {
        reservation.value = await ReservationService.assign(reservationId, assignedTo)
        const name = assignedTo === null
            ? 'Sin asignar'
            : (teamMembers.value.find((m) => m.id === assignedTo)?.name ?? 'equipo')
        toast.success(`Reserva asignada a ${name}.`)
    } catch (err: unknown) {
        const message = extractApiMessage(err) ?? 'No se pudo actualizar la asignación.'
        toast.error(message)
        // Reload to reset the select to the real current state
        void loadReservation()
    } finally {
        isAssigning.value = false
    }
}

// ── Payments ──────────────────────────────────────────────────────────────────

function openPaymentForm(): void {
    paymentAmount.value = ''
    paymentMethod.value = 'cash'
    paymentReference.value = ''
    paymentFieldErrors.value = {}
    paymentFormVisible.value = true
}

function cancelPaymentForm(): void {
    paymentFormVisible.value = false
}

async function submitPayment(): Promise<void> {
    paymentFieldErrors.value = {}

    const amountCents = parsePaymentCents(paymentAmount.value)
    if (amountCents <= 0) {
        paymentFieldErrors.value.amount = 'Ingresa un monto válido mayor a 0.'
        return
    }

    isRecordingPayment.value = true
    try {
        reservation.value = await ReservationService.recordPayment(reservationId, {
            amount_cents: amountCents,
            payment_method: paymentMethod.value,
            reference: paymentReference.value.trim() || null,
        })
        toast.success('Pago registrado correctamente.')
        paymentFormVisible.value = false
    } catch (err: unknown) {
        const apiErr = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } }

        if (apiErr.response?.status === 422) {
            const serverErrors = apiErr.response.data?.errors ?? {}
            for (const [field, messages] of Object.entries(serverErrors)) {
                paymentFieldErrors.value[field] = messages[0] ?? 'Campo inválido.'
            }
            const msg = apiErr.response.data?.message ?? 'No se pudo registrar el pago.'
            toast.error(msg)
        } else {
            const msg = extractApiMessage(err) ?? 'No se pudo registrar el pago.'
            toast.error(msg)
        }
    } finally {
        isRecordingPayment.value = false
    }
}

// ── Convert to order ──────────────────────────────────────────────────────────

async function convertToOrder(): Promise<void> {
    if (!reservation.value || isConverting.value) return

    isConverting.value = true
    convertConfirmVisible.value = false
    try {
        const result = await ReservationService.convert(reservationId)
        convertedOrderId.value = result.order_id
        toast.success(`Reserva convertida al pedido ${result.order_number}.`)
        // Reload the reservation detail to reflect the delivered status + converted_order_id
        await loadReservation()
        // Navigate to the new order
        void router.push({ name: 'admin.orders.detail', params: { id: result.order_id } })
    } catch (err: unknown) {
        const message = extractApiMessage(err) ?? 'No se pudo convertir la reserva.'
        toast.error(message)
    } finally {
        isConverting.value = false
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractApiMessage(err: unknown): string | null {
    const body = (err as { response?: { data?: { message?: string } } })?.response?.data
    return body?.message ?? null
}

// Timeline node icon per reservation status
function timelineIcon(status: ReservationStatus): typeof CheckCircle {
    const icons: Record<ReservationStatus, typeof CheckCircle> = {
        inquiry: Clock,
        confirmed: Circle,
        in_progress: Circle,
        ready: Circle,
        delivered: CheckCircle,
        cancelled: XCircle,
    }
    return icons[status] ?? Circle
}

const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    other: 'Otro',
}

const ROLE_LABELS: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    staff: 'Personal',
}
</script>

<template>
    <!-- ── Loading state ── -->
    <div
        v-if="pageState === 'loading'"
        class="flex flex-col items-center justify-center py-32 gap-4"
        aria-busy="true"
    >
        <AppSpinner size="lg" />
        <p class="text-sm text-on-surface-variant">Cargando reserva…</p>
    </div>

    <!-- ── Not-found state ── -->
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
                Reserva no encontrada
            </h2>
            <p class="text-sm text-on-surface-variant">
                La reserva que buscas no existe o no tienes acceso a ella.
            </p>
        </div>
        <AppButton
            variant="secondary"
            :icon="ArrowLeft"
            @click="router.push({ name: 'admin.reservations' })"
        >
            Volver a Reservas
        </AppButton>
    </div>

    <!-- ── Error state ── -->
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
                Ocurrió un error al obtener la reserva. Intenta de nuevo.
            </p>
        </div>
        <AppButton variant="secondary" @click="loadReservation">
            Reintentar
        </AppButton>
    </div>

    <!-- ── Loaded state ── -->
    <div v-else-if="pageState === 'loaded' && reservation" class="flex flex-col gap-6">

        <!-- Page header -->
        <div>
            <!-- Back link -->
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant
                       hover:text-primary transition-colors mb-4"
                @click="router.push({ name: 'admin.reservations' })"
            >
                <ArrowLeft :size="14" aria-hidden="true" />
                Volver a Reservas
            </button>

            <!-- Eyebrow + number + badges -->
            <p class="label-gilt mb-1">Reserva</p>
            <div class="flex flex-wrap items-center gap-3 mb-2">
                <h1 class="serif text-3xl text-on-surface tracking-tighter" :id="`res-${reservation.id}`">
                    {{ reservation.reservation_number }}
                </h1>
                <AppBadge :variant="RESERVATION_STATUS_VARIANT[reservation.status]">
                    {{ RESERVATION_STATUS_LABELS[reservation.status] }}
                </AppBadge>
                <AppBadge v-if="reservation.occasion" variant="neutral" size="sm">
                    {{ reservation.occasion }}
                </AppBadge>
            </div>
            <p class="text-xs text-on-surface-variant">
                Creada {{ formatDateTime(reservation.created_at) }}
            </p>
        </div>

        <!-- Split layout: left (main content) / right (actions + timeline) -->
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">

            <!-- ── LEFT COLUMN ── -->
            <div class="flex flex-col gap-6">

                <!-- Details card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Detalles de la reserva
                    </h2>

                    <div class="flex flex-col gap-4">
                        <!-- Description -->
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center shrink-0">
                                <FileText :size="14" class="text-primary" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Descripción</p>
                                <p class="text-sm text-on-surface leading-relaxed">
                                    {{ reservation.description ?? '—' }}
                                </p>
                            </div>
                        </div>

                        <!-- Occasion + Event date (side by side on sm+) -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center shrink-0">
                                    <Calendar :size="14" class="text-secondary" aria-hidden="true" />
                                </div>
                                <div>
                                    <p class="text-xs label-gilt mb-0.5">Ocasión</p>
                                    <p class="text-sm font-semibold text-on-surface">
                                        {{ reservation.occasion ?? '—' }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center shrink-0">
                                    <Calendar :size="14" class="text-secondary" aria-hidden="true" />
                                </div>
                                <div>
                                    <p class="text-xs label-gilt mb-0.5">Fecha del evento</p>
                                    <p class="text-sm font-semibold text-on-surface">
                                        {{ reservation.event_date ? formatDate(reservation.event_date) : '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Customer + Branch -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center shrink-0">
                                    <User :size="14" class="text-primary" aria-hidden="true" />
                                </div>
                                <div>
                                    <p class="text-xs label-gilt mb-0.5">Cliente</p>
                                    <p class="text-sm font-semibold text-on-surface">
                                        {{ reservation.customer?.name ?? 'Cliente de mostrador' }}
                                    </p>
                                    <p
                                        v-if="reservation.customer?.phone"
                                        class="text-xs text-on-surface-variant"
                                    >
                                        {{ reservation.customer.phone }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center shrink-0">
                                    <MapPin :size="14" class="text-secondary" aria-hidden="true" />
                                </div>
                                <div>
                                    <p class="text-xs label-gilt mb-0.5">Sucursal</p>
                                    <p class="text-sm font-semibold text-on-surface">
                                        {{ reservation.branch?.name ?? '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Special instructions -->
                        <div v-if="reservation.special_instructions" class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-surface-high flex items-center justify-center shrink-0 dark:bg-surface-mid">
                                <FileText :size="14" class="text-on-surface-variant" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Instrucciones especiales</p>
                                <p class="text-sm text-on-surface leading-relaxed">
                                    {{ reservation.special_instructions }}
                                </p>
                            </div>
                        </div>

                        <!-- Admin notes (internal) -->
                        <div v-if="reservation.admin_notes" class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-warning-container flex items-center justify-center shrink-0">
                                <Lock :size="14" class="text-warning" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Notas internas</p>
                                <p class="text-sm text-on-surface leading-relaxed italic">
                                    {{ reservation.admin_notes }}
                                </p>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <!-- Financials card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Resumen financiero
                    </h2>

                    <!-- Total (primary serif) -->
                    <div class="flex items-end justify-between mb-5 pb-4" style="border-bottom: 2px solid var(--outline-soft)">
                        <span class="text-sm text-on-surface-variant">Total de la reserva</span>
                        <span class="serif text-3xl text-primary tracking-tighter">
                            {{ formatCents(reservation.total_cents) }}
                        </span>
                    </div>

                    <!-- Deposit summary rows -->
                    <div class="flex flex-col gap-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-on-surface-variant">Anticipo requerido</span>
                            <span class="text-sm font-semibold text-on-surface">
                                {{ formatCents(reservation.deposit_required_cents) }}
                            </span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-sm text-on-surface-variant">Anticipo pagado</span>
                            <span
                                :class="[
                                    'text-sm font-semibold',
                                    reservation.deposit_paid_cents >= reservation.deposit_required_cents
                                        ? 'text-success'
                                        : 'text-warning',
                                ]"
                            >
                                {{ formatCents(reservation.deposit_paid_cents) }}
                            </span>
                        </div>

                        <div
                            v-if="reservation.deposit_outstanding_cents > 0"
                            class="flex justify-between items-center"
                        >
                            <span class="text-sm text-on-surface-variant">Anticipo pendiente</span>
                            <span class="text-sm font-semibold text-error">
                                {{ formatCents(reservation.deposit_outstanding_cents) }}
                            </span>
                        </div>

                        <!-- Balance separator -->
                        <div
                            class="flex justify-between items-center pt-3"
                            style="border-top: 2px solid var(--outline-soft)"
                        >
                            <span class="serif text-xl text-on-surface tracking-tighter">Saldo pendiente</span>
                            <span
                                :class="[
                                    'serif text-2xl tracking-tighter',
                                    reservation.balance_cents > 0 ? 'text-warning' : 'text-success',
                                ]"
                            >
                                {{ formatCents(reservation.balance_cents) }}
                            </span>
                        </div>
                    </div>
                </AppCard>

                <!-- Payments card -->
                <AppCard>
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="serif text-lg text-on-surface tracking-tighter">
                            Pagos y abonos
                        </h2>
                        <AppButton
                            v-if="!paymentFormVisible && reservation.status !== 'cancelled'"
                            variant="ghost"
                            size="sm"
                            :icon="Plus"
                            @click="openPaymentForm"
                        >
                            Registrar pago
                        </AppButton>
                    </div>

                    <!-- Payment registration form (inline) -->
                    <div
                        v-if="paymentFormVisible"
                        class="mb-5 rounded-xl p-4 bg-surface-low dark:bg-surface-mid flex flex-col gap-4"
                    >
                        <p class="text-xs label-gilt">Nuevo pago / abono</p>

                        <!-- Amount -->
                        <div class="flex flex-col gap-1.5">
                            <label
                                for="payment-amount"
                                class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                            >
                                Monto <span class="text-error" aria-hidden="true">*</span>
                            </label>
                            <input
                                id="payment-amount"
                                v-model="paymentAmount"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                :class="[
                                    'w-full px-4 py-2.5 rounded-xl bg-surface-lowest text-on-surface text-sm',
                                    'focus:outline-none focus:ring-2 focus:ring-primary/30',
                                    'dark:bg-surface-low dark:text-on-surface',
                                    paymentFieldErrors.amount ? 'ring-2 ring-error/60' : '',
                                ]"
                                aria-required="true"
                            />
                            <p v-if="paymentFieldErrors.amount" class="text-xs text-error" role="alert">
                                {{ paymentFieldErrors.amount }}
                            </p>
                        </div>

                        <!-- Method -->
                        <div class="flex flex-col gap-1.5">
                            <label
                                for="payment-method"
                                class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                            >
                                Método de pago
                            </label>
                            <select
                                id="payment-method"
                                v-model="paymentMethod"
                                class="w-full px-4 py-2.5 rounded-xl bg-surface-lowest text-on-surface text-sm
                                       focus:outline-none focus:ring-2 focus:ring-primary/30
                                       dark:bg-surface-low dark:text-on-surface appearance-none"
                                aria-label="Método de pago"
                            >
                                <option value="cash">Efectivo</option>
                                <option value="card">Tarjeta</option>
                                <option value="transfer">Transferencia</option>
                                <option value="other">Otro</option>
                            </select>
                        </div>

                        <!-- Reference (optional) -->
                        <div class="flex flex-col gap-1.5">
                            <label
                                for="payment-reference"
                                class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                            >
                                Referencia
                                <span class="font-normal normal-case tracking-normal text-on-surface-variant ml-1">
                                    (opcional)
                                </span>
                            </label>
                            <input
                                id="payment-reference"
                                v-model="paymentReference"
                                type="text"
                                placeholder="N.° comprobante, transacción..."
                                class="w-full px-4 py-2.5 rounded-xl bg-surface-lowest text-on-surface text-sm
                                       placeholder:text-on-surface-variant/50
                                       focus:outline-none focus:ring-2 focus:ring-primary/30
                                       dark:bg-surface-low dark:text-on-surface"
                            />
                        </div>

                        <!-- Form actions -->
                        <div class="flex gap-2">
                            <AppButton
                                variant="secondary"
                                size="sm"
                                class="flex-1"
                                :disabled="isRecordingPayment"
                                @click="cancelPaymentForm"
                            >
                                Cancelar
                            </AppButton>
                            <AppButton
                                variant="primary"
                                size="sm"
                                class="flex-1"
                                :loading="isRecordingPayment"
                                :disabled="isRecordingPayment"
                                @click="submitPayment"
                            >
                                Registrar
                            </AppButton>
                        </div>
                    </div>

                    <!-- Existing payments list -->
                    <div v-if="reservation.payments.length > 0" class="flex flex-col">
                        <div
                            v-for="payment in reservation.payments"
                            :key="payment.id"
                            class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2
                                   py-3 odd:bg-surface-low dark:odd:bg-surface-mid rounded-lg px-3"
                        >
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-success-container flex items-center justify-center shrink-0">
                                    <CreditCard :size="12" class="text-success" aria-hidden="true" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-on-surface">
                                        {{ formatCents(payment.amount_cents) }}
                                    </p>
                                    <p class="text-xs text-on-surface-variant">
                                        {{ PAYMENT_METHOD_LABELS[payment.payment_method] ?? payment.payment_method }}
                                        <template v-if="payment.reference"> · {{ payment.reference }}</template>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-on-surface-variant">
                                    {{ formatDateTime(payment.paid_at) }}
                                </p>
                                <p v-if="payment.recorded_by" class="text-xs text-on-surface-variant">
                                    por {{ payment.recorded_by.name }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else-if="!paymentFormVisible"
                        class="text-sm text-on-surface-variant text-center py-6"
                    >
                        Sin pagos registrados aún.
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

                    <!-- Advance status buttons -->
                    <div
                        v-if="advanceTransitions.length > 0"
                        class="flex flex-col gap-2 mb-5"
                    >
                        <p class="text-xs label-gilt mb-1">Avanzar estado</p>
                        <AppButton
                            v-for="next in advanceTransitions"
                            :key="next"
                            variant="primary"
                            :icon="ChevronRight"
                            icon-position="right"
                            :loading="transitioningTo === next"
                            :disabled="transitioningTo !== null || isCancelling"
                            class="w-full justify-between"
                            @click="advanceToStatus(next)"
                        >
                            {{ `Marcar como ${RESERVATION_STATUS_LABELS[next]}` }}
                        </AppButton>
                    </div>

                    <!-- Deposit gate banner (shown when backend blocks inquiry→confirmed) -->
                    <div
                        v-if="depositGateMessage"
                        class="mb-4 rounded-xl p-4 bg-warning-container"
                    >
                        <p class="text-sm font-semibold text-warning mb-1">Anticipo requerido</p>
                        <p class="text-xs text-on-surface mb-3">{{ depositGateMessage }}</p>
                        <div class="flex gap-2">
                            <AppButton
                                variant="secondary"
                                size="sm"
                                class="flex-1"
                                :disabled="isForcingTransition"
                                @click="dismissDepositGate"
                            >
                                Entendido
                            </AppButton>
                            <AppButton
                                v-if="pendingForceStatus"
                                variant="primary"
                                size="sm"
                                class="flex-1"
                                :loading="isForcingTransition"
                                :disabled="isForcingTransition"
                                @click="advanceToStatus(pendingForceStatus!, true)"
                            >
                                Confirmar de todos modos
                            </AppButton>
                        </div>
                    </div>

                    <!-- Convert to order -->
                    <div v-if="showConvertButton" class="mb-5">
                        <!-- Confirm step -->
                        <div
                            v-if="convertConfirmVisible"
                            class="rounded-xl p-4 bg-primary-container space-y-3"
                        >
                            <p class="text-sm font-semibold text-on-surface text-center">
                                ¿Convertir a pedido?
                            </p>
                            <p class="text-xs text-on-surface-variant text-center">
                                Se creará un pedido a partir de esta reserva. Esta acción no se puede deshacer.
                            </p>
                            <div class="flex gap-2">
                                <AppButton
                                    variant="secondary"
                                    size="sm"
                                    class="flex-1"
                                    :disabled="isConverting"
                                    @click="convertConfirmVisible = false"
                                >
                                    Cancelar
                                </AppButton>
                                <AppButton
                                    variant="primary"
                                    size="sm"
                                    class="flex-1"
                                    :loading="isConverting"
                                    :disabled="isConverting"
                                    @click="convertToOrder"
                                >
                                    Sí, convertir
                                </AppButton>
                            </div>
                        </div>
                        <AppButton
                            v-else
                            variant="secondary"
                            :icon="Package"
                            class="w-full"
                            :disabled="transitioningTo !== null || isCancelling"
                            @click="convertConfirmVisible = true"
                        >
                            Convertir a pedido
                        </AppButton>
                    </div>

                    <!-- Already converted: link to the order -->
                    <div
                        v-if="reservation.converted_order_id"
                        class="mb-5 flex items-center gap-2 rounded-xl p-3 bg-success-container"
                    >
                        <CheckCircle :size="16" class="text-success shrink-0" aria-hidden="true" />
                        <div class="flex-1 min-w-0">
                            <p class="text-xs text-on-surface font-semibold">Convertida a pedido</p>
                            <button
                                type="button"
                                class="text-xs text-primary hover:underline"
                                @click="router.push({ name: 'admin.orders.detail', params: { id: reservation!.converted_order_id } })"
                            >
                                Ver pedido →
                            </button>
                        </div>
                    </div>

                    <!-- Assignee selector -->
                    <div class="mb-5">
                        <p class="text-xs label-gilt mb-2">Asignar a</p>
                        <div class="relative">
                            <select
                                :value="currentAssigneeId"
                                :disabled="isAssigning"
                                class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface
                                       text-sm focus:outline-none focus:ring-2 focus:ring-primary/30
                                       appearance-none pr-8
                                       dark:bg-surface-mid dark:text-on-surface
                                       disabled:opacity-50 disabled:pointer-events-none"
                                aria-label="Asignar reserva a un miembro del equipo"
                                @change="onAssigneeChange"
                            >
                                <option value="">Sin asignar</option>
                                <option
                                    v-for="member in teamMembers"
                                    :key="member.id"
                                    :value="String(member.id)"
                                >
                                    {{ member.name }}
                                    ({{ ROLE_LABELS[member.role] ?? member.role }})
                                </option>
                            </select>
                            <AppSpinner
                                v-if="isAssigning"
                                size="sm"
                                class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none"
                            />
                        </div>
                    </div>

                    <!-- Cancel action -->
                    <div v-if="showCancelAction">
                        <div v-if="!cancelConfirmVisible">
                            <AppButton
                                variant="danger"
                                class="w-full"
                                :disabled="transitioningTo !== null || isCancelling"
                                @click="cancelConfirmVisible = true"
                            >
                                Cancelar reserva
                            </AppButton>
                        </div>
                        <div
                            v-else
                            class="rounded-xl p-4 bg-error-container dark:bg-error/10 space-y-3"
                        >
                            <p class="text-sm font-semibold text-error text-center">
                                ¿Confirmar cancelación?
                            </p>
                            <p class="text-xs text-on-surface-variant text-center">
                                Esta acción no se puede deshacer.
                            </p>
                            <div class="flex gap-2">
                                <AppButton
                                    variant="secondary"
                                    size="sm"
                                    class="flex-1"
                                    :disabled="isCancelling"
                                    @click="cancelConfirmVisible = false"
                                >
                                    No, volver
                                </AppButton>
                                <AppButton
                                    variant="danger"
                                    size="sm"
                                    class="flex-1"
                                    :loading="isCancelling"
                                    :disabled="isCancelling"
                                    @click="cancelReservation"
                                >
                                    Sí, cancelar
                                </AppButton>
                            </div>
                        </div>
                    </div>
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

                    <!-- Vertical stepper -->
                    <ol
                        class="flex flex-col"
                        aria-label="Historial de estados de la reserva"
                    >
                        <li
                            v-for="(entry, index) in timelineEntries"
                            :key="entry.id"
                            class="flex gap-4"
                        >
                            <!-- Connector column -->
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
                                <!-- Gradient connecting line — decorative, not a section divider -->
                                <div
                                    v-if="index < timelineEntries.length - 1"
                                    class="w-0.5 flex-1 my-1"
                                    style="background: linear-gradient(to bottom, var(--primary-container), var(--surface-high))"
                                    aria-hidden="true"
                                />
                            </div>

                            <!-- Entry content -->
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
                                    {{ RESERVATION_STATUS_LABELS[entry.to_status] }}
                                    <AppBadge
                                        v-if="index === 0"
                                        :variant="RESERVATION_STATUS_VARIANT[entry.to_status]"
                                        size="sm"
                                        class="ml-1.5"
                                    >
                                        Actual
                                    </AppBadge>
                                </p>
                                <p class="text-xs text-on-surface-variant mt-0.5">
                                    {{ formatDateTime(entry.created_at) }}
                                </p>
                                <p
                                    v-if="entry.user"
                                    class="text-xs text-on-surface-variant mt-0.5"
                                >
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
    </div>
</template>
