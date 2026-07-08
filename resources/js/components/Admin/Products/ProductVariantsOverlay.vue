<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { X, Plus, Trash2, Check } from 'lucide-vue-next'
import { useProductsStore } from '@/stores/products'
import { useToast } from '@/composables/useToast'
import type { ProductVariant } from '@/types/domain/Product'

const props = defineProps<{ show: boolean }>()
const emit = defineEmits<{ close: [] }>()

const store = useProductsStore()
const toast = useToast()

const product = computed(() => store.current)
const variants = computed<ProductVariant[]>(() => product.value?.variants ?? [])

// Option axes for the add form: { name, values: string[] } snapshotted when the
// overlay opens (union of the product's declared options and the values seen on
// existing variants — seeded products carry the axes only on the variants).
// Snapshotted (not reactive) so deleting a variant doesn't drop a value from the
// add form; the value inputs are free-text (datalist), so new values are allowed.
const optionAxes = ref<Array<{ name: string; values: string[] }>>([])

function snapshotAxes(): void {
    const map = new Map<string, Set<string>>()
    for (const axis of product.value?.options ?? []) {
        if (!map.has(axis.name)) map.set(axis.name, new Set())
        axis.values.forEach((v) => map.get(axis.name)!.add(v.value))
    }
    for (const v of variants.value) {
        for (const [key, val] of Object.entries(v.options)) {
            if (!map.has(key)) map.set(key, new Set())
            map.get(key)!.add(val)
        }
    }
    optionAxes.value = [...map.entries()].map(([name, vals]) => ({ name, values: [...vals] }))
}

// ── Add-variant form ────────────────────────────────────────────────────────
const newOptions = ref<Record<string, string>>({})
const newSku = ref('')
const newPrice = ref('')
const adding = ref(false)

function initAddForm(): void {
    const defaults: Record<string, string> = {}
    for (const axis of optionAxes.value) {
        if (axis.values.length > 0) defaults[axis.name] = axis.values[0]
    }
    newOptions.value = defaults
    newSku.value = ''
    newPrice.value = ''
}

function priceToCents(input: string): number | null {
    const trimmed = input.trim()
    if (trimmed === '') return null
    const n = parseFloat(trimmed)
    return Number.isFinite(n) ? Math.round(n * 100) : null
}

const optionLabel = (v: ProductVariant): string =>
    Object.entries(v.options).map(([k, val]) => `${k}: ${val}`).join(' · ') || 'Sin opciones'

// ── Row edits (save on change) ──────────────────────────────────────────────
async function saveSku(variant: ProductVariant, value: string): Promise<void> {
    if (product.value === null || value === variant.sku) return
    try {
        await store.updateVariant(product.value.id, variant.id, { sku: value })
    } catch {
        toast.error('No se pudo actualizar el SKU')
    }
}

async function savePrice(variant: ProductVariant, value: string): Promise<void> {
    if (product.value === null) return
    const cents = priceToCents(value)
    if (cents === variant.price_cents) return
    try {
        await store.updateVariant(product.value.id, variant.id, { price_cents: cents })
    } catch {
        toast.error('No se pudo actualizar el precio')
    }
}

// Availability toggle: inactive variants stay configured here but disappear from
// the public storefront and the POS (matches the backend is_active filter).
async function toggleActive(variant: ProductVariant): Promise<void> {
    if (product.value === null) return
    const next = !variant.is_active
    try {
        await store.updateVariant(product.value.id, variant.id, { is_active: next })
    } catch {
        toast.error('No se pudo cambiar la disponibilidad')
    }
}

// Inline delete confirmation (a nested confirm dialog would render behind this
// z-90 overlay). Arming a row shows an inline "¿Eliminar?" prompt on that row.
const confirmingId = ref<number | null>(null)

function askRemove(variant: ProductVariant): void {
    confirmingId.value = variant.id
}
function cancelRemove(): void {
    confirmingId.value = null
}
async function doRemove(variant: ProductVariant): Promise<void> {
    if (product.value === null) return
    try {
        await store.removeVariant(product.value.id, variant.id)
        toast.success('Variante eliminada')
    } catch {
        toast.error('No se pudo eliminar la variante')
    } finally {
        confirmingId.value = null
    }
}

async function add(): Promise<void> {
    if (product.value === null || adding.value) return
    adding.value = true
    try {
        await store.addVariant(product.value.id, {
            options: { ...newOptions.value },
            sku: newSku.value.trim() || undefined,
            price_cents: priceToCents(newPrice.value),
        })
        toast.success('Variante agregada')
        initAddForm()
    } catch (err) {
        const message = (err as { response?: { data?: { message?: string } } }).response?.data?.message
        toast.error(message ?? 'No se pudo agregar la variante')
    } finally {
        adding.value = false
    }
}

// ── Overlay lifecycle ───────────────────────────────────────────────────────
function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Escape') emit('close')
}
watch(
    () => props.show,
    (open) => {
        if (open) {
            snapshotAxes()
            initAddForm()
            document.addEventListener('keydown', onKeydown)
        } else {
            confirmingId.value = null
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
                class="fixed inset-0 z-[90] grid place-items-center p-0 sm:p-5"
                role="dialog"
                aria-modal="true"
                aria-labelledby="pv-title"
                data-testid="product-variants-overlay"
            >
                <div
                    class="absolute inset-0"
                    style="background: rgba(61,47,50,.42); backdrop-filter: blur(6px)"
                    @click="emit('close')"
                />

                <div
                    class="relative flex flex-col w-full max-w-[860px] h-[100dvh] sm:h-[min(90dvh,780px)]
                           overflow-hidden bg-surface sm:rounded-[var(--r-2xl)] shadow-[var(--shadow-lifted)]"
                >
                    <!-- Header -->
                    <div class="flex items-start gap-4 px-6 sm:px-8 pt-6 pb-4">
                        <div class="min-w-0">
                            <p class="text-[11px] font-bold tracking-[0.16em] uppercase text-primary mb-1.5">Producto</p>
                            <h2 id="pv-title" class="serif text-2xl leading-tight text-on-surface truncate">
                                Variantes · {{ product.name }}
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="btn-icon ml-auto shrink-0"
                            aria-label="Cerrar"
                            data-testid="pv-close"
                            @click="emit('close')"
                        >
                            <X :size="20" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="overflow-y-auto px-6 sm:px-8 pb-6 flex-1">
                        <!-- Existing variants -->
                        <div v-if="variants.length" class="flex flex-col gap-2.5">
                            <div
                                v-for="v in variants"
                                :key="v.id"
                                class="grid grid-cols-1 sm:grid-cols-[1fr_140px_120px_auto_auto] gap-2 sm:items-center p-3 rounded-[var(--r-lg)] bg-surface-low"
                                :data-testid="`pv-row-${v.id}`"
                            >
                                <div class="min-w-0" :class="{ 'opacity-55': !v.is_active }">
                                    <p class="text-sm font-semibold text-on-surface truncate">{{ optionLabel(v) }}</p>
                                    <p v-if="!v.is_active" class="text-[11px] font-medium text-on-surface-variant">No disponible</p>
                                </div>
                                <input
                                    :value="v.sku"
                                    type="text"
                                    class="field text-xs font-mono"
                                    :class="{ 'opacity-55': !v.is_active }"
                                    aria-label="SKU"
                                    :data-testid="`pv-sku-${v.id}`"
                                    @change="saveSku(v, ($event.target as HTMLInputElement).value)"
                                />
                                <input
                                    :value="v.price_cents !== null ? (v.price_cents / 100).toFixed(2) : ''"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="Heredado"
                                    class="field text-sm text-right"
                                    :class="{ 'opacity-55': !v.is_active }"
                                    aria-label="Precio"
                                    :data-testid="`pv-price-${v.id}`"
                                    @change="savePrice(v, ($event.target as HTMLInputElement).value)"
                                />
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="v.is_active"
                                    :aria-label="`Disponibilidad de ${optionLabel(v)}`"
                                    :data-testid="`pv-active-${v.id}`"
                                    class="relative h-6 w-11 shrink-0 justify-self-end rounded-full transition-colors duration-150"
                                    :style="{ background: v.is_active ? 'var(--primary)' : 'var(--surface-high)' }"
                                    @click="toggleActive(v)"
                                >
                                    <span
                                        class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow-[var(--shadow-ambient)] transition-transform duration-150"
                                        :class="{ 'translate-x-5': v.is_active }"
                                    />
                                </button>
                                <div class="justify-self-end flex items-center gap-1">
                                    <template v-if="confirmingId === v.id">
                                        <span class="text-xs text-on-surface-variant mr-1">¿Eliminar?</span>
                                        <button
                                            type="button"
                                            class="btn-icon text-error"
                                            aria-label="Confirmar eliminación"
                                            :data-testid="`pv-confirm-remove-${v.id}`"
                                            @click="doRemove(v)"
                                        >
                                            <Check :size="16" />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn-icon"
                                            aria-label="Cancelar"
                                            @click="cancelRemove"
                                        >
                                            <X :size="15" />
                                        </button>
                                    </template>
                                    <button
                                        v-else
                                        type="button"
                                        class="btn-icon text-error"
                                        :aria-label="`Eliminar variante ${optionLabel(v)}`"
                                        :data-testid="`pv-remove-${v.id}`"
                                        @click="askRemove(v)"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-on-surface-variant text-center py-8">
                            Este producto todavía no tiene variantes.
                        </p>

                        <!-- Add variant -->
                        <div class="mt-6 p-4 rounded-[var(--r-lg)]" style="background: var(--surface-mid)">
                            <p class="label-gilt mb-3">Agregar variante</p>
                            <div class="flex flex-wrap items-end gap-3">
                                <div v-for="axis in optionAxes" :key="axis.name" class="flex flex-col gap-1">
                                    <label class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant">{{ axis.name }}</label>
                                    <input
                                        v-model="newOptions[axis.name]"
                                        :list="`pv-axis-${axis.name}`"
                                        type="text"
                                        class="field text-sm w-36"
                                        :data-testid="`pv-new-option-${axis.name}`"
                                    />
                                    <datalist :id="`pv-axis-${axis.name}`">
                                        <option v-for="val in axis.values" :key="val" :value="val" />
                                    </datalist>
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant">SKU (opcional)</label>
                                    <input v-model="newSku" type="text" class="field text-xs font-mono w-32" data-testid="pv-new-sku" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant">Precio</label>
                                    <input v-model="newPrice" type="text" inputmode="decimal" placeholder="Heredado" class="field text-sm text-right w-24" data-testid="pv-new-price" />
                                </div>
                                <button
                                    type="button"
                                    class="btn-primary gap-2"
                                    :disabled="adding"
                                    data-testid="pv-add"
                                    @click="add"
                                >
                                    <Plus :size="16" aria-hidden="true" />
                                    Agregar
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="flex justify-end px-6 sm:px-8 py-4 pb-[calc(1rem+env(safe-area-inset-bottom))]" style="background: var(--surface-low)">
                        <button type="button" class="btn-primary" data-testid="pv-done" @click="emit('close')">
                            Listo
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
