<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import {
    CheckCircle2,
    Clock3,
    Truck,
    PackageCheck,
    XCircle,
    Package,
    MapPin,
    MessageCircle,
    AlertTriangle,
} from 'lucide-vue-next'
import AppSpinner from '@/components/base/AppSpinner.vue'
import PublicTrackingService from '@/services/PublicTrackingService'
import { ORDER_STATUS_LABELS, STATUS_ORDER } from '@/constants/orders'
import type { OrderTracking, TrackingBrand } from '@/types/domain/Tracking'
import type { OrderStatus } from '@/types/domain/Order'

// ── Route ─────────────────────────────────────────────────────────────────────

const route = useRoute()
const token = route.params.token as string

// ── State ─────────────────────────────────────────────────────────────────────

type PageState = 'loading' | 'loaded' | 'not-found' | 'error'

const pageState = ref<PageState>('loading')
const tracking = ref<OrderTracking | null>(null)

// ── Data loading ──────────────────────────────────────────────────────────────

async function loadTracking(): Promise<void> {
    pageState.value = 'loading'
    try {
        tracking.value = await PublicTrackingService.track(token)
        pageState.value = 'loaded'
        if (tracking.value?.brand.business_name) {
            document.title = `Seguimiento — ${tracking.value.brand.business_name}`
        }
    } catch (err: unknown) {
        const status = (err as { response?: { status?: number } })?.response?.status
        pageState.value = status === 404 ? 'not-found' : 'error'
    }
}

onMounted(() => {
    void loadTracking()
})

// ── Branding helpers ──────────────────────────────────────────────────────────

// Design-system primary as the absolute fallback when the tenant hasn't set colours.
const DESIGN_SYSTEM_PRIMARY = '#7c545d'

function resolvedPrimary(brand: TrackingBrand): string {
    return brand.primary_color ?? DESIGN_SYSTEM_PRIMARY
}

function accentStyle(brand: TrackingBrand): string {
    const colour = resolvedPrimary(brand)
    return `color: ${colour}`
}

function accentBgStyle(brand: TrackingBrand): string {
    const colour = resolvedPrimary(brand)
    return `background-color: ${colour}`
}

function heroBandStyle(brand: TrackingBrand): string {
    const colour = resolvedPrimary(brand)
    // Soft gradient from the tenant's primary toward a near-white tint for contrast.
    return `background: linear-gradient(135deg, ${colour}18, ${colour}08)`
}

// ── Stepper logic ─────────────────────────────────────────────────────────────

// Icons per status in the forward flow (not cancelled).
const STATUS_ICONS: Record<OrderStatus, typeof Clock3> = {
    pending: Clock3,
    preparing: Package,
    ready: PackageCheck,
    dispatched: Truck,
    delivered: CheckCircle2,
    cancelled: XCircle,
}

type StepperNodeState = 'done' | 'current' | 'upcoming'

interface StepperNode {
    status: OrderStatus
    label: string
    state: StepperNodeState
}

const stepperNodes = computed<StepperNode[]>(() => {
    if (!tracking.value) return []

    const currentStatus = tracking.value.status
    const currentIndex = STATUS_ORDER.indexOf(currentStatus)

    return STATUS_ORDER.map((status, index) => {
        let state: StepperNodeState
        if (index < currentIndex) {
            state = 'done'
        } else if (index === currentIndex) {
            state = 'current'
        } else {
            state = 'upcoming'
        }
        return { status, label: ORDER_STATUS_LABELS[status], state }
    })
})

const isCancelled = computed<boolean>(() => tracking.value?.status === 'cancelled')

// ── Timeline ──────────────────────────────────────────────────────────────────

// Most-recent-first: reverse a copy so the array origin stays immutable.
const timelineEntries = computed(() => {
    if (!tracking.value?.timeline) return []
    return [...tracking.value.timeline].reverse()
})

// ── Date formatting ───────────────────────────────────────────────────────────

// The public page cannot rely on useTenantStore (no Pinia session), so we format
// dates directly using the brand's language field from the payload.
function formatDateTime(isoString: string, language: string): string {
    const date = new Date(isoString)
    if (isNaN(date.getTime())) return isoString
    return date.toLocaleString(language, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

function formatDate(isoString: string, language: string): string {
    const date = new Date(isoString)
    if (isNaN(date.getTime())) return isoString
    return date.toLocaleDateString(language, {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    })
}

// ── WhatsApp link ─────────────────────────────────────────────────────────────

function whatsappHref(number: string): string {
    const digits = number.replace(/\D/g, '')
    return `https://wa.me/${digits}`
}
</script>

<template>
    <!-- ── Loading state ── -->
    <div
        v-if="pageState === 'loading'"
        class="min-h-screen flex flex-col items-center justify-center gap-4 bg-surface"
        aria-busy="true"
    >
        <AppSpinner size="lg" />
        <p class="text-sm text-on-surface-variant">Cargando seguimiento…</p>
    </div>

    <!-- ── Not-found state ── -->
    <div
        v-else-if="pageState === 'not-found'"
        class="min-h-screen flex flex-col items-center justify-center gap-6 text-center px-6 bg-surface"
    >
        <div
            class="w-20 h-20 rounded-full flex items-center justify-center"
            style="background: var(--gradient-soft)"
        >
            <AlertTriangle :size="32" class="text-primary" aria-hidden="true" />
        </div>
        <div class="max-w-xs">
            <h1 class="serif text-2xl text-on-surface tracking-tighter mb-3">
                No encontramos este pedido
            </h1>
            <p class="text-sm text-on-surface-variant leading-relaxed">
                El enlace puede ser inválido o haber expirado.
                Si recibiste este link de tu proveedor, pídele que lo verifique.
            </p>
        </div>
    </div>

    <!-- ── Error state ── -->
    <div
        v-else-if="pageState === 'error'"
        class="min-h-screen flex flex-col items-center justify-center gap-6 text-center px-6 bg-surface"
    >
        <div class="w-20 h-20 rounded-full flex items-center justify-center bg-error-container">
            <AlertTriangle :size="32" class="text-error" aria-hidden="true" />
        </div>
        <div class="max-w-xs">
            <h1 class="serif text-2xl text-on-surface tracking-tighter mb-3">
                Error al cargar
            </h1>
            <p class="text-sm text-on-surface-variant">
                Ocurrió un problema al obtener el seguimiento. Intenta recargar la página.
            </p>
        </div>
        <button
            type="button"
            class="btn btn-tertiary text-sm"
            @click="loadTracking"
        >
            Reintentar
        </button>
    </div>

    <!-- ── Loaded state ── -->
    <div
        v-else-if="pageState === 'loaded' && tracking"
        class="min-h-screen flex flex-col bg-surface dark:bg-surface"
    >

        <!-- ══ BRANDED HEADER ══════════════════════════════════════════════════ -->
        <header
            class="px-4 py-6 sm:px-8"
            :style="heroBandStyle(tracking.brand)"
        >
            <div class="max-w-2xl mx-auto flex flex-col items-center gap-4 text-center">

                <!-- Logo or business name -->
                <div class="flex flex-col items-center gap-3">
                    <img
                        v-if="tracking.brand.logo_url"
                        :src="tracking.brand.logo_url"
                        :alt="tracking.brand.business_name"
                        class="h-12 w-auto object-contain"
                    />
                    <div
                        v-else
                        class="w-12 h-12 rounded-full flex items-center justify-center shrink-0"
                        :style="accentBgStyle(tracking.brand)"
                        aria-hidden="true"
                    >
                        <Package :size="22" class="text-white dark:text-on-primary" />
                    </div>

                    <h2
                        class="serif text-xl tracking-tighter"
                        :style="accentStyle(tracking.brand)"
                    >
                        {{ tracking.brand.business_name }}
                    </h2>
                </div>

                <!-- Order identity block -->
                <div>
                    <p class="label-gilt mb-2">Seguimiento de pedido</p>
                    <h1 class="serif text-4xl sm:text-5xl text-on-surface tracking-tighter">
                        {{ tracking.order_number }}
                    </h1>
                </div>

                <!-- Meta: branch + date -->
                <div class="flex flex-wrap items-center justify-center gap-4 mt-1">
                    <div
                        v-if="tracking.branch_name"
                        class="flex items-center gap-1.5 text-xs text-on-surface-variant"
                    >
                        <MapPin :size="13" aria-hidden="true" />
                        {{ tracking.branch_name }}
                    </div>
                    <div class="flex items-center gap-1.5 text-xs text-on-surface-variant">
                        <Clock3 :size="13" aria-hidden="true" />
                        Pedido el {{ formatDate(tracking.created_at, tracking.brand.language) }}
                    </div>
                </div>
            </div>
        </header>

        <!-- ══ MAIN CONTENT ═══════════════════════════════════════════════════ -->
        <main class="flex-1 w-full max-w-2xl mx-auto px-4 sm:px-8 py-8 flex flex-col gap-8">

            <!-- ── Cancelled state banner ── -->
            <div
                v-if="isCancelled"
                class="rounded-xl p-6 bg-error-container dark:bg-error-container
                       flex flex-col items-center gap-3 text-center"
                role="status"
                aria-label="Estado del pedido: Cancelado"
            >
                <XCircle :size="36" class="text-error" aria-hidden="true" />
                <div>
                    <p class="text-base font-semibold text-error">Pedido cancelado</p>
                    <p class="text-sm text-on-surface-variant mt-1">
                        Este pedido fue cancelado. Contacta al negocio para más información.
                    </p>
                </div>
            </div>

            <!-- ── Status stepper (only for non-cancelled orders) ── -->
            <section v-else aria-label="Estado del pedido">
                <p class="label-gilt mb-5 text-center">Estado actual</p>

                <!--
                    Mobile: vertical stepper (default).
                    Desktop (sm+): horizontal stepper.
                    The stepper ALWAYS derives node completion from current status position
                    in the STATUS_ORDER flow — it does NOT rely on the timeline array.
                -->

                <!-- VERTICAL (mobile default) -->
                <ol
                    class="flex flex-col gap-0 sm:hidden"
                    aria-label="Pasos del pedido"
                >
                    <li
                        v-for="(node, index) in stepperNodes"
                        :key="node.status"
                        class="flex gap-4"
                    >
                        <!-- Node + connector column -->
                        <div class="flex flex-col items-center shrink-0">
                            <!-- Node circle -->
                            <div
                                :class="[
                                    'w-10 h-10 rounded-full flex items-center justify-center z-10 transition-all duration-300',
                                    node.state === 'done' || node.state === 'current'
                                        ? 'text-white dark:text-on-primary'
                                        : 'bg-surface-high text-on-surface-variant dark:bg-surface-mid',
                                ]"
                                :style="node.state === 'done' || node.state === 'current'
                                    ? accentBgStyle(tracking.brand)
                                    : ''"
                                :aria-label="`${node.label}: ${node.state === 'done' ? 'completado' : node.state === 'current' ? 'estado actual' : 'pendiente'}`"
                            >
                                <component
                                    :is="node.state === 'done' ? CheckCircle2 : STATUS_ICONS[node.status]"
                                    :size="16"
                                    aria-hidden="true"
                                />
                            </div>

                            <!-- Connector line between nodes -->
                            <div
                                v-if="index < stepperNodes.length - 1"
                                class="w-0.5 h-8 my-1 rounded-full"
                                :class="node.state === 'done'
                                    ? 'opacity-60'
                                    : 'bg-surface-high dark:bg-surface-mid'"
                                :style="node.state === 'done' ? accentBgStyle(tracking.brand) : ''"
                                aria-hidden="true"
                            />
                        </div>

                        <!-- Label + current indicator -->
                        <div class="pb-6 flex-1 flex items-start pt-2 min-w-0">
                            <div>
                                <p
                                    :class="[
                                        'text-sm font-semibold leading-tight',
                                        node.state === 'current'
                                            ? ''
                                            : node.state === 'done'
                                                ? 'text-on-surface-variant'
                                                : 'text-on-surface-variant opacity-60',
                                    ]"
                                    :style="node.state === 'current' ? accentStyle(tracking.brand) : ''"
                                >
                                    {{ node.label }}
                                </p>
                                <span
                                    v-if="node.state === 'current'"
                                    class="label-gilt text-[9px]"
                                    :style="accentStyle(tracking.brand)"
                                >
                                    Ahora
                                </span>
                            </div>
                        </div>
                    </li>
                </ol>

                <!-- HORIZONTAL (sm and up) -->
                <ol
                    class="hidden sm:flex items-start gap-0"
                    aria-label="Pasos del pedido"
                >
                    <li
                        v-for="(node, index) in stepperNodes"
                        :key="node.status"
                        class="flex flex-col items-center flex-1 min-w-0"
                    >
                        <!-- Node row: connector-left + circle + connector-right -->
                        <div class="flex items-center w-full">
                            <!-- Left connector (full-width half) -->
                            <div
                                class="flex-1 h-0.5 rounded-full"
                                :class="index === 0 ? 'invisible' : ''"
                                :style="index > 0 && (stepperNodes[index - 1].state === 'done' || node.state === 'done' || node.state === 'current')
                                    ? accentBgStyle(tracking.brand)
                                    : 'background-color: var(--surface-high)'"
                                aria-hidden="true"
                            />

                            <!-- Node circle -->
                            <div
                                :class="[
                                    'w-10 h-10 rounded-full flex items-center justify-center shrink-0 z-10 transition-all duration-300',
                                    node.state === 'done' || node.state === 'current'
                                        ? 'text-white dark:text-on-primary'
                                        : 'bg-surface-high text-on-surface-variant dark:bg-surface-mid',
                                ]"
                                :style="node.state === 'done' || node.state === 'current'
                                    ? accentBgStyle(tracking.brand)
                                    : ''"
                                :aria-label="`${node.label}: ${node.state === 'done' ? 'completado' : node.state === 'current' ? 'estado actual' : 'pendiente'}`"
                            >
                                <component
                                    :is="node.state === 'done' ? CheckCircle2 : STATUS_ICONS[node.status]"
                                    :size="16"
                                    aria-hidden="true"
                                />
                            </div>

                            <!-- Right connector (full-width half) -->
                            <div
                                class="flex-1 h-0.5 rounded-full"
                                :class="index === stepperNodes.length - 1 ? 'invisible' : ''"
                                :style="node.state === 'done'
                                    ? accentBgStyle(tracking.brand)
                                    : 'background-color: var(--surface-high)'"
                                aria-hidden="true"
                            />
                        </div>

                        <!-- Label below the node -->
                        <div class="mt-2 text-center px-1">
                            <p
                                :class="[
                                    'text-xs font-semibold leading-tight',
                                    node.state === 'upcoming' ? 'opacity-50' : '',
                                    node.state === 'done' ? 'text-on-surface-variant' : '',
                                ]"
                                :style="node.state === 'current' ? accentStyle(tracking.brand) : ''"
                            >
                                {{ node.label }}
                            </p>
                            <span
                                v-if="node.state === 'current'"
                                class="label-gilt text-[9px] block mt-0.5"
                                :style="accentStyle(tracking.brand)"
                            >
                                Ahora
                            </span>
                        </div>
                    </li>
                </ol>
            </section>

            <!-- ── Timeline ── -->
            <section
                class="rounded-xl bg-surface-low dark:bg-surface-mid"
                aria-label="Historial de cambios"
                data-testid="tracking-timeline"
            >
                <div class="px-5 pt-5 pb-1">
                    <p class="label-gilt">Historial</p>
                </div>

                <div
                    v-if="timelineEntries.length === 0"
                    class="px-5 pb-5 pt-3 text-sm text-on-surface-variant"
                >
                    Sin registros aún.
                </div>

                <!-- Timeline entries — newest-first -->
                <ol class="px-5 pb-5 pt-3 flex flex-col" aria-label="Cambios de estado cronológicos">
                    <li
                        v-for="(entry, index) in timelineEntries"
                        :key="entry.at + entry.status"
                        class="flex gap-3"
                    >
                        <!-- Node + connector column -->
                        <div class="flex flex-col items-center shrink-0">
                            <div
                                :class="[
                                    'w-7 h-7 rounded-full flex items-center justify-center shrink-0',
                                    index === 0
                                        ? 'text-white dark:text-on-primary'
                                        : 'bg-surface-high text-on-surface-variant dark:bg-surface-mid',
                                ]"
                                :style="index === 0 ? accentBgStyle(tracking.brand) : ''"
                                aria-hidden="true"
                            >
                                <component
                                    :is="STATUS_ICONS[entry.status]"
                                    :size="13"
                                    aria-hidden="true"
                                />
                            </div>
                            <div
                                v-if="index < timelineEntries.length - 1"
                                class="w-0.5 flex-1 my-1 rounded-full bg-surface-high dark:bg-surface-mid"
                                style="min-height: 1.5rem"
                                aria-hidden="true"
                            />
                        </div>

                        <!-- Entry content -->
                        <div
                            :class="[
                                'flex-1 min-w-0',
                                index < timelineEntries.length - 1 ? 'pb-4' : '',
                            ]"
                        >
                            <p
                                :class="[
                                    'text-sm font-semibold leading-tight',
                                    index === 0 ? '' : 'text-on-surface',
                                ]"
                                :style="index === 0 ? accentStyle(tracking.brand) : ''"
                            >
                                {{ ORDER_STATUS_LABELS[entry.status] }}
                            </p>
                            <time
                                :datetime="entry.at"
                                class="text-xs text-on-surface-variant mt-0.5 block"
                            >
                                {{ formatDateTime(entry.at, tracking.brand.language) }}
                            </time>
                        </div>
                    </li>
                </ol>
            </section>
        </main>

        <!-- ══ BRANDED FOOTER ═════════════════════════════════════════════════ -->
        <footer class="px-4 py-6 sm:px-8 bg-surface-low dark:bg-surface-mid">
            <div class="max-w-2xl mx-auto flex flex-col items-center gap-2 text-center">
                <p
                    class="serif text-base tracking-tighter"
                    :style="accentStyle(tracking.brand)"
                >
                    {{ tracking.brand.business_name }}
                </p>
                <p
                    v-if="tracking.brand.tagline"
                    class="text-xs text-on-surface-variant"
                >
                    {{ tracking.brand.tagline }}
                </p>
                <a
                    v-if="tracking.brand.whatsapp_number"
                    :href="whatsappHref(tracking.brand.whatsapp_number)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold mt-1 transition-opacity hover:opacity-80"
                    :style="accentStyle(tracking.brand)"
                    aria-label="Contactar por WhatsApp"
                >
                    <MessageCircle :size="13" aria-hidden="true" />
                    Contactar por WhatsApp
                </a>
            </div>
        </footer>

    </div>
</template>
