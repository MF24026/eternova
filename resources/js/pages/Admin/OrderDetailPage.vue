<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
    ArrowLeft,
    User,
    MapPin,
    CreditCard,
    FileText,
    ChevronRight,
    CheckCircle,
    Circle,
    XCircle,
    Clock,
    AlertTriangle,
    Link,
} from 'lucide-vue-next'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppCard from '@/components/base/AppCard.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import OrderService from '@/services/OrderService'
import TeamService from '@/services/TeamService'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import { useFormatDate } from '@/composables/useFormatDate'
import { useToast } from '@/composables/useToast'
import {
    ORDER_STATUS_LABELS,
    ORDER_STATUS_VARIANT,
    ORDER_SOURCE_LABELS,
    PAYMENT_METHOD_LABELS,
    PAYMENT_STATUS_LABELS,
    PAYMENT_STATUS_VARIANT,
} from '@/constants/orders'
import type { OrderDetail, OrderStatus } from '@/types/domain/Order'
import type { TeamMember } from '@/types/domain/Team'

// ── Route & navigation ────────────────────────────────────────────────────────

const route = useRoute()
const router = useRouter()
const orderId = route.params.id as string

// ── Composables ───────────────────────────────────────────────────────────────

const { formatCents } = useFormatCurrency()
const { formatDateTime } = useFormatDate()
const toast = useToast()

// ── State ─────────────────────────────────────────────────────────────────────

type PageState = 'loading' | 'loaded' | 'not-found' | 'error'

const pageState = ref<PageState>('loading')
const order = ref<OrderDetail | null>(null)
const teamMembers = ref<TeamMember[]>([])

// Tracks which transition button is in-flight (status value, or null).
const transitioningTo = ref<OrderStatus | null>(null)
// Whether the cancel confirm step is visible.
const cancelConfirmVisible = ref(false)
// Whether a cancel operation is in-flight.
const isCancelling = ref(false)
// Whether an assign operation is in-flight.
const isAssigning = ref(false)

// ── Data loading ──────────────────────────────────────────────────────────────

async function loadOrder(): Promise<void> {
    pageState.value = 'loading'
    try {
        order.value = await OrderService.get(orderId)
        pageState.value = 'loaded'
        document.title = `Pedido ${order.value.order_number} — Eternova`
    } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status
        pageState.value = status === 404 ? 'not-found' : 'error'
    }
}

async function loadTeam(): Promise<void> {
    try {
        teamMembers.value = await TeamService.list()
    } catch {
        // Non-critical — assignee selector will be empty but page still works.
    }
}

onMounted(() => {
    void Promise.all([loadOrder(), loadTeam()])
})

// ── Actions ───────────────────────────────────────────────────────────────────

async function advanceToStatus(status: OrderStatus): Promise<void> {
    if (!order.value || transitioningTo.value !== null) return

    transitioningTo.value = status
    try {
        order.value = await OrderService.transition(orderId, status)
        toast.success(`Pedido marcado como "${ORDER_STATUS_LABELS[status]}"`)
    } catch (err: unknown) {
        const message = extractApiMessage(err) ?? 'No se pudo cambiar el estado del pedido.'
        toast.error(message)
    } finally {
        transitioningTo.value = null
    }
}

async function cancelOrder(): Promise<void> {
    if (!order.value || isCancelling.value) return

    isCancelling.value = true
    cancelConfirmVisible.value = false
    try {
        order.value = await OrderService.cancel(orderId)
        toast.success('Pedido cancelado.')
    } catch (err: unknown) {
        const message = extractApiMessage(err) ?? 'No se pudo cancelar el pedido.'
        toast.error(message)
    } finally {
        isCancelling.value = false
    }
}

async function onAssigneeChange(event: Event): Promise<void> {
    if (!order.value || isAssigning.value) return

    const select = event.target as HTMLSelectElement
    const rawValue = select.value
    const assignedTo: number | null = rawValue === '' ? null : Number(rawValue)

    isAssigning.value = true
    try {
        order.value = await OrderService.assign(orderId, assignedTo)
        const name = assignedTo === null
            ? 'Sin asignar'
            : (teamMembers.value.find((m) => m.id === assignedTo)?.name ?? 'equipo')
        toast.success(`Pedido asignado a ${name}.`)
    } catch (err: unknown) {
        const message = extractApiMessage(err) ?? 'No se pudo actualizar la asignación.'
        toast.error(message)
        // Reset select to current value so it doesn't show a stale selection.
        void loadOrder()
    } finally {
        isAssigning.value = false
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractApiMessage(err: unknown): string | null {
    const body = (err as { response?: { data?: { message?: string } } })?.response?.data
    return body?.message ?? null
}

// Whether "cancel" should appear in the actions. We show it when 'cancelled' is
// in allowed_transitions OR when the status is not a terminal state — the server
// guards the 422 case; prefer showing so staff can attempt it.
const showCancelAction = computed<boolean>(() => {
    if (!order.value) return false
    const terminal: OrderStatus[] = ['delivered', 'cancelled']
    return !terminal.includes(order.value.status)
})

// The current assignee id as a string for the <select> v-model-like default.
const currentAssigneeId = computed<string>(() => {
    return order.value?.assignee?.id?.toString() ?? ''
})

// Timeline entries sorted newest-first.
const timelineEntries = computed(() => {
    if (!order.value?.status_history) return []
    return [...order.value.status_history].reverse()
})

// Variant options formatted as "Color: Rojo, Talla: M".
function formatVariantOptions(options: Record<string, string>): string {
    return Object.entries(options)
        .map(([key, value]) => `${key}: ${value}`)
        .join(', ')
}

// Role labels for team members.
const ROLE_LABELS: Record<string, string> = {
    owner: 'Propietario',
    admin: 'Administrador',
    staff: 'Personal',
}

// ── Tracking link ─────────────────────────────────────────────────────────────

// Visible when the order has a tracking_token and is not cancelled.
// We guard cancelled so there is no point sharing a tracking link for a
// terminal order that shows a "cancelled" banner.
const showTrackingLink = computed<boolean>(() => {
    if (!order.value?.tracking_token) return false
    return order.value.status !== 'cancelled'
})

async function copyTrackingLink(): Promise<void> {
    if (!order.value?.tracking_token) return
    const url = `${window.location.origin}/track/${order.value.tracking_token}`
    try {
        await navigator.clipboard.writeText(url)
        toast.success('Link copiado')
    } catch {
        // Clipboard API can fail in non-secure contexts (HTTP dev without HTTPS).
        // Fall back to the legacy execCommand approach.
        const el = document.createElement('textarea')
        el.value = url
        el.style.position = 'fixed'
        el.style.opacity = '0'
        document.body.appendChild(el)
        el.select()
        document.execCommand('copy')
        document.body.removeChild(el)
        toast.success('Link copiado')
    }
}

// ── Timeline node Lucide icon per status ──────────────────────────────────────

// Timeline node Lucide icon per status.
function timelineIcon(status: OrderStatus) {
    const icons: Record<OrderStatus, typeof CheckCircle> = {
        pending: Clock,
        preparing: Circle,
        ready: Circle,
        dispatched: Circle,
        delivered: CheckCircle,
        cancelled: XCircle,
    }
    return icons[status] ?? Circle
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
        <p class="text-sm text-on-surface-variant">Cargando pedido…</p>
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
                Pedido no encontrado
            </h2>
            <p class="text-sm text-on-surface-variant">
                El pedido que buscas no existe o no tienes acceso a él.
            </p>
        </div>
        <AppButton
            variant="secondary"
            :icon="ArrowLeft"
            @click="router.push({ name: 'admin.orders' })"
        >
            Volver a Pedidos
        </AppButton>
    </div>

    <!-- ── Error state ── -->
    <div
        v-else-if="pageState === 'error'"
        class="flex flex-col items-center justify-center py-32 gap-6 text-center"
    >
        <div
            class="w-16 h-16 rounded-full flex items-center justify-center bg-error-container"
        >
            <AlertTriangle :size="28" class="text-error" aria-hidden="true" />
        </div>
        <div>
            <h2 class="serif text-2xl text-on-surface tracking-tighter mb-2">
                Error al cargar
            </h2>
            <p class="text-sm text-on-surface-variant">
                Ocurrió un error al obtener el pedido. Intenta de nuevo.
            </p>
        </div>
        <AppButton variant="secondary" @click="loadOrder">
            Reintentar
        </AppButton>
    </div>

    <!-- ── Loaded state ── -->
    <div v-else-if="pageState === 'loaded' && order" class="flex flex-col gap-6">

        <!-- Page header -->
        <div>
            <!-- Back link -->
            <button
                type="button"
                class="inline-flex items-center gap-1.5 text-sm text-on-surface-variant
                       hover:text-primary transition-colors mb-4"
                @click="router.push({ name: 'admin.orders' })"
            >
                <ArrowLeft :size="14" aria-hidden="true" />
                Volver a Pedidos
            </button>

            <!-- Eyebrow + number + badges -->
            <p class="label-gilt mb-1">Pedido</p>
            <div class="flex flex-wrap items-center gap-3 mb-2">
                <h1 class="serif text-3xl text-on-surface tracking-tighter">
                    {{ order.order_number }}
                </h1>
                <AppBadge :variant="ORDER_STATUS_VARIANT[order.status]">
                    {{ ORDER_STATUS_LABELS[order.status] }}
                </AppBadge>
                <AppBadge variant="neutral" size="sm">
                    {{ ORDER_SOURCE_LABELS[order.source] ?? order.source }}
                </AppBadge>
            </div>
            <p class="text-xs text-on-surface-variant">
                Creado {{ formatDateTime(order.created_at) }}
            </p>
        </div>

        <!-- Split layout: left column (main) / right column (actions + timeline) -->
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">

            <!-- ── LEFT COLUMN ── -->
            <div class="flex flex-col gap-6">

                <!-- Items card -->
                <AppCard padding="none">
                    <div class="p-6 pb-0">
                        <h2 class="serif text-lg text-on-surface tracking-tighter mb-4">
                            Artículos
                        </h2>
                    </div>

                    <!-- Item rows -->
                    <div
                        v-for="item in order.items"
                        :key="item.id"
                        class="px-6 py-4 odd:bg-surface-low dark:odd:bg-surface-mid
                               flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                    >
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-on-surface truncate">
                                {{ item.product_snapshot.name ?? 'Producto eliminado' }}
                            </p>
                            <p
                                v-if="Object.keys(item.product_snapshot.variant_options).length > 0"
                                class="text-xs text-on-surface-variant mt-0.5"
                            >
                                {{ formatVariantOptions(item.product_snapshot.variant_options) }}
                            </p>
                            <p
                                v-if="item.product_snapshot.sku"
                                class="text-xs text-on-surface-variant font-mono"
                            >
                                SKU: {{ item.product_snapshot.sku }}
                            </p>
                        </div>
                        <div class="flex items-center gap-4 shrink-0">
                            <span class="text-xs text-on-surface-variant">
                                {{ item.quantity }} ×
                                {{ formatCents(item.unit_price_cents) }}
                            </span>
                            <span class="text-sm font-semibold text-on-surface">
                                {{ formatCents(item.total_cents) }}
                            </span>
                        </div>
                    </div>

                    <!-- Totals footer -->
                    <div class="px-6 pt-4 pb-6 bg-surface-low dark:bg-surface-mid mt-2 rounded-b-xl space-y-2">
                        <div class="flex justify-between text-sm text-on-surface-variant">
                            <span>Subtotal</span>
                            <span>{{ formatCents(order.subtotal_cents) }}</span>
                        </div>
                        <div
                            v-if="order.tax_cents > 0"
                            class="flex justify-between text-sm text-on-surface-variant"
                        >
                            <span>Impuesto</span>
                            <span>{{ formatCents(order.tax_cents) }}</span>
                        </div>
                        <div
                            v-if="order.discount_cents > 0"
                            class="flex justify-between text-sm text-success"
                        >
                            <span>Descuento</span>
                            <span>− {{ formatCents(order.discount_cents) }}</span>
                        </div>
                        <div
                            class="flex justify-between items-center pt-3"
                            style="border-top: 2px solid var(--outline-soft)"
                        >
                            <span class="serif text-xl text-on-surface tracking-tighter">Total</span>
                            <span class="serif text-2xl text-primary tracking-tighter">
                                {{ formatCents(order.total_cents) }}
                            </span>
                        </div>
                    </div>
                </AppCard>

                <!-- Customer & payment card -->
                <AppCard>
                    <h2 class="serif text-lg text-on-surface tracking-tighter mb-5">
                        Cliente y pago
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <!-- Customer -->
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center shrink-0">
                                <User :size="14" class="text-primary" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Cliente</p>
                                <p class="text-sm font-semibold text-on-surface">
                                    {{ order.customer?.name ?? 'Cliente de mostrador' }}
                                </p>
                                <p
                                    v-if="order.customer?.phone"
                                    class="text-xs text-on-surface-variant"
                                >
                                    {{ order.customer.phone }}
                                </p>
                            </div>
                        </div>

                        <!-- Branch -->
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-secondary-container flex items-center justify-center shrink-0">
                                <MapPin :size="14" class="text-secondary" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Sucursal</p>
                                <p class="text-sm font-semibold text-on-surface">
                                    {{ order.branch?.name ?? '—' }}
                                </p>
                            </div>
                        </div>

                        <!-- Payment method -->
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-full bg-surface-high flex items-center justify-center shrink-0 dark:bg-surface-mid">
                                <CreditCard :size="14" class="text-on-surface-variant" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-0.5">Método de pago</p>
                                <p class="text-sm font-semibold text-on-surface">
                                    {{ order.payment_method
                                        ? (PAYMENT_METHOD_LABELS[order.payment_method] ?? order.payment_method)
                                        : '—' }}
                                </p>
                            </div>
                        </div>

                        <!-- Payment status -->
                        <div class="flex gap-3 items-start">
                            <div class="w-8 h-8 rounded-full bg-surface-high flex items-center justify-center shrink-0 dark:bg-surface-mid">
                                <CheckCircle :size="14" class="text-on-surface-variant" aria-hidden="true" />
                            </div>
                            <div>
                                <p class="text-xs label-gilt mb-1">Estado de pago</p>
                                <AppBadge :variant="PAYMENT_STATUS_VARIANT[order.payment_status]">
                                    {{ PAYMENT_STATUS_LABELS[order.payment_status] ?? order.payment_status }}
                                </AppBadge>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <!-- Notes card (only when present) -->
                <AppCard v-if="order.notes">
                    <div class="flex gap-3">
                        <div class="w-8 h-8 rounded-full bg-surface-high flex items-center justify-center shrink-0 dark:bg-surface-mid">
                            <FileText :size="14" class="text-on-surface-variant" aria-hidden="true" />
                        </div>
                        <div>
                            <p class="text-xs label-gilt mb-1">Notas del pedido</p>
                            <p class="text-sm text-on-surface leading-relaxed">{{ order.notes }}</p>
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

                    <!-- Advance status buttons -->
                    <div
                        v-if="order.allowed_transitions.length > 0"
                        class="flex flex-col gap-2 mb-5"
                    >
                        <p class="text-xs label-gilt mb-1">Avanzar estado</p>
                        <AppButton
                            v-for="next in order.allowed_transitions"
                            :key="next"
                            variant="primary"
                            :icon="ChevronRight"
                            icon-position="right"
                            :loading="transitioningTo === next"
                            :disabled="transitioningTo !== null || isCancelling"
                            class="w-full justify-between"
                            @click="advanceToStatus(next)"
                        >
                            {{ `Marcar como ${ORDER_STATUS_LABELS[next]}` }}
                        </AppButton>
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
                                aria-label="Asignar pedido a un miembro del equipo"
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

                    <!-- Tracking link (share with customer) -->
                    <div v-if="showTrackingLink" class="mb-4">
                        <AppButton
                            variant="secondary"
                            :icon="Link"
                            class="w-full"
                            @click="copyTrackingLink"
                        >
                            Copiar link de seguimiento
                        </AppButton>
                    </div>

                    <!-- Cancel action -->
                    <div v-if="showCancelAction">
                        <!-- Initial cancel button -->
                        <div v-if="!cancelConfirmVisible">
                            <AppButton
                                variant="danger"
                                class="w-full"
                                :disabled="transitioningTo !== null || isCancelling"
                                @click="cancelConfirmVisible = true"
                            >
                                Cancelar pedido
                            </AppButton>
                        </div>

                        <!-- Confirm step (two-click guard) -->
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
                                    @click="cancelOrder"
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
                    <ol class="flex flex-col" aria-label="Historial de estados del pedido">
                        <li
                            v-for="(entry, index) in timelineEntries"
                            :key="entry.id"
                            class="flex gap-4"
                        >
                            <!-- Connector column -->
                            <div class="flex flex-col items-center shrink-0">
                                <!-- Node icon -->
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
                                <!-- Connecting line (decorative, not a section divider) -->
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
                                    {{ ORDER_STATUS_LABELS[entry.to_status] }}
                                    <AppBadge
                                        v-if="index === 0"
                                        :variant="ORDER_STATUS_VARIANT[entry.to_status]"
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
