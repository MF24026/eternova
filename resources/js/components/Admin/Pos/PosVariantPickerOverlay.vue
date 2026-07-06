<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { X, Minus, Plus } from 'lucide-vue-next'
import { groupVariantsByFirstOption } from '@/composables/pos/useVariantGrouping'
import type { PosProduct, PosProductVariant } from '@/types/domain/POS'

const props = defineProps<{
    show: boolean
    product: PosProduct | null
    formatCents: (cents: number) => string
}>()

const emit = defineEmits<{
    close: []
    confirm: [picks: Array<{ variant: PosProductVariant; quantity: number }>]
}>()

// Local selection: variantId -> quantity. Reset on each open.
const qty = ref<Record<number, number>>({})

const groups = computed(() =>
    props.product ? groupVariantsByFirstOption(props.product.variants) : [],
)

const variantLabel = (v: PosProductVariant): string =>
    Object.values(v.options ?? {}).join(' · ') || v.sku

function stockLabel(v: PosProductVariant): { text: string; low: boolean } {
    if (v.available_quantity <= 0) return { text: 'Sin stock', low: true }
    if (v.available_quantity <= 3) return { text: `Quedan ${v.available_quantity}`, low: true }
    return { text: `${v.available_quantity} disp.`, low: false }
}

function inc(v: PosProductVariant): void {
    const next = Math.min((qty.value[v.id] ?? 0) + 1, v.available_quantity)
    qty.value = { ...qty.value, [v.id]: next }
}

function dec(v: PosProductVariant): void {
    const next = Math.max((qty.value[v.id] ?? 0) - 1, 0)
    const copy = { ...qty.value }
    if (next === 0) delete copy[v.id]
    else copy[v.id] = next
    qty.value = copy
}

function clearAll(): void {
    qty.value = {}
}

const picks = computed(() => {
    if (!props.product) return []
    return props.product.variants
        .filter((v) => (qty.value[v.id] ?? 0) > 0)
        .map((v) => ({ variant: v, quantity: qty.value[v.id] }))
})

const totalCents = computed(() =>
    picks.value.reduce((sum, p) => sum + p.variant.price_cents * p.quantity, 0),
)
const unitCount = computed(() => picks.value.reduce((sum, p) => sum + p.quantity, 0))

function confirm(): void {
    if (picks.value.length === 0) return
    emit('confirm', picks.value)
}

// ESC closes the overlay while it is open.
function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') emit('close')
}

watch(
    () => props.show,
    (open) => {
        if (open) {
            qty.value = {}
            document.addEventListener('keydown', onKeydown)
        } else {
            document.removeEventListener('keydown', onKeydown)
        }
    },
)

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
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
                v-if="show && product"
                class="fixed inset-0 z-[60] grid place-items-center p-0 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="pos-variant-title"
                data-testid="pos-variant-overlay"
            >
                <!-- Scrim -->
                <div
                    class="absolute inset-0"
                    style="background: rgba(61,47,50,.42); backdrop-filter: blur(6px)"
                    @click="emit('close')"
                />

                <!-- Panel -->
                <div
                    class="relative flex flex-col w-full max-w-[1080px] h-[100dvh] sm:h-[min(88dvh,780px)]
                           overflow-hidden bg-surface sm:rounded-[var(--r-2xl)] shadow-[var(--shadow-lifted)]"
                >
                    <!-- Header -->
                    <div class="flex items-start gap-4 px-6 sm:px-8 pt-6 pb-5">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold tracking-[0.16em] uppercase text-primary mb-2">
                                Punto de venta · Elegí variante
                            </p>
                            <h2
                                id="pos-variant-title"
                                class="serif text-2xl sm:text-3xl leading-tight text-on-surface"
                            >
                                {{ product.name }}
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="btn-icon ml-auto shrink-0"
                            aria-label="Cerrar"
                            data-testid="pos-variant-close"
                            @click="emit('close')"
                        >
                            <X :size="20" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="grid grid-cols-1 md:grid-cols-[1.55fr_1fr] min-h-0 flex-1">
                        <!-- Left: grouped variant grid -->
                        <div class="overflow-y-auto px-6 sm:px-8 pb-6">
                            <section
                                v-for="group in groups"
                                :key="group.label"
                                class="mt-1 first:mt-0 [&+section]:mt-6"
                            >
                                <div class="flex items-baseline justify-between gap-3 mb-3">
                                    <h3 class="serif text-lg text-on-surface">
                                        {{ group.label || 'Variantes' }}
                                    </h3>
                                    <span class="text-[11px] tracking-[0.12em] uppercase text-on-surface-variant">
                                        Elegí cantidades
                                    </span>
                                </div>

                                <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr))">
                                    <div
                                        v-for="v in group.variants"
                                        :key="v.id"
                                        class="flex flex-col gap-3 p-4 rounded-[var(--r-lg)] bg-surface-lowest
                                               shadow-[var(--shadow-ambient)] outline outline-2 transition-[outline-color]"
                                        :class="(qty[v.id] ?? 0) > 0 ? 'outline-primary' : 'outline-transparent'"
                                        :style="v.available_quantity <= 0 ? 'opacity:.55' : ''"
                                        :data-testid="`pos-variant-card-${v.id}`"
                                    >
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="font-semibold text-sm text-on-surface truncate">
                                                    {{ variantLabel(v) }}
                                                </p>
                                            </div>
                                            <span class="font-bold text-primary tabular-nums whitespace-nowrap">
                                                {{ formatCents(v.price_cents) }}
                                            </span>
                                        </div>

                                        <span
                                            class="self-start text-[11px] font-semibold px-2.5 py-0.5 rounded-full"
                                            :style="stockLabel(v).low
                                                ? 'background: var(--error-container); color: var(--error)'
                                                : 'background: var(--secondary-container); color: var(--secondary)'"
                                        >
                                            {{ stockLabel(v).text }}
                                        </span>

                                        <div class="flex items-center justify-between">
                                            <template v-if="v.available_quantity > 0">
                                                <div
                                                    class="inline-flex items-center gap-0.5 rounded-full p-0.5"
                                                    style="background: var(--surface-mid)"
                                                >
                                                    <button
                                                        type="button"
                                                        class="w-8 h-8 grid place-items-center rounded-full transition-colors hover:bg-surface-high disabled:opacity-30"
                                                        :disabled="(qty[v.id] ?? 0) === 0"
                                                        :aria-label="`Quitar uno de ${variantLabel(v)}`"
                                                        :data-testid="`pos-variant-dec-${v.id}`"
                                                        @click="dec(v)"
                                                    >
                                                        <Minus :size="14" />
                                                    </button>
                                                    <span
                                                        class="min-w-[26px] text-center font-bold tabular-nums text-on-surface"
                                                        :data-testid="`pos-variant-qty-${v.id}`"
                                                    >
                                                        {{ qty[v.id] ?? 0 }}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        class="w-8 h-8 grid place-items-center rounded-full transition-colors hover:bg-surface-high disabled:opacity-30"
                                                        :disabled="(qty[v.id] ?? 0) >= v.available_quantity"
                                                        :aria-label="`Agregar uno de ${variantLabel(v)}`"
                                                        :data-testid="`pos-variant-inc-${v.id}`"
                                                        @click="inc(v)"
                                                    >
                                                        <Plus :size="14" />
                                                    </button>
                                                </div>
                                            </template>
                                            <span v-else class="text-sm text-on-surface-variant">No disponible</span>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <!-- Right: summary + CTA -->
                        <aside
                            class="flex flex-col min-h-0 bg-surface-low
                                   max-md:shadow-[0_-12px_32px_rgba(61,47,50,0.08)]"
                        >
                            <div class="hidden md:block overflow-y-auto px-6 pt-6 pb-2 flex-1">
                                <h3 class="serif text-xl text-on-surface mb-1">Tu selección</h3>
                                <p class="text-sm text-on-surface-variant mb-4">
                                    <template v-if="unitCount === 0">Todavía no elegiste ninguna variante.</template>
                                    <template v-else>
                                        {{ unitCount }} {{ unitCount === 1 ? 'unidad' : 'unidades' }} ·
                                        {{ picks.length }} {{ picks.length === 1 ? 'variante' : 'variantes' }}
                                    </template>
                                </p>

                                <div v-if="picks.length === 0" class="text-center text-sm text-on-surface-variant py-6">
                                    <span class="block font-semibold text-on-surface mb-1">Sin variantes elegidas</span>
                                    Tocá una tarjeta para sumarla a la venta.
                                </div>
                                <div v-else class="flex flex-col gap-2.5">
                                    <div
                                        v-for="p in picks"
                                        :key="p.variant.id"
                                        class="flex items-center gap-3 p-3 rounded-[var(--r-md)] bg-surface-lowest shadow-[var(--shadow-ambient)]"
                                    >
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-sm text-on-surface truncate">{{ variantLabel(p.variant) }}</p>
                                            <p class="text-xs text-on-surface-variant">
                                                {{ p.quantity }} × {{ formatCents(p.variant.price_cents) }}
                                            </p>
                                        </div>
                                        <span class="font-bold tabular-nums text-on-surface">
                                            {{ formatCents(p.variant.price_cents * p.quantity) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-3.5 px-6 pt-4 pb-6 max-md:flex-row max-md:items-center max-md:pb-[calc(1rem+env(safe-area-inset-bottom))]">
                                <div class="flex items-baseline justify-between max-md:flex-col max-md:items-start">
                                    <span class="serif text-lg text-on-surface max-md:text-sm max-md:text-on-surface-variant">Total</span>
                                    <span class="serif text-3xl font-semibold tabular-nums text-on-surface max-md:text-2xl" data-testid="pos-variant-total">
                                        {{ formatCents(totalCents) }}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    class="btn-primary w-full max-md:w-auto max-md:flex-1 justify-center disabled:opacity-45"
                                    :disabled="picks.length === 0"
                                    data-testid="pos-variant-confirm"
                                    @click="confirm"
                                >
                                    Agregar al carrito<template v-if="totalCents > 0"> · {{ formatCents(totalCents) }}</template>
                                </button>
                                <button
                                    v-if="picks.length > 0"
                                    type="button"
                                    class="hidden md:block text-sm text-on-surface-variant hover:text-primary self-center"
                                    @click="clearAll"
                                >
                                    Vaciar selección
                                </button>
                            </div>
                        </aside>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
