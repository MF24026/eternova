<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Plus, Trash2, ArrowUp, ArrowDown, ChevronDown, X, User, Tag } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import PosCustomerSelector from '@/components/Admin/Pos/PosCustomerSelector.vue'
import QuotationService from '@/services/QuotationService'
import ProductsService from '@/services/ProductsService'
import { useToast } from '@/composables/useToast'
import { useFormatCurrency } from '@/composables/useFormatCurrency'
import type { Quotation, QuotationItemPayload, CreateQuotationPayload } from '@/types/domain/Quotation'
import type { Product } from '@/types/domain/Product'

// ── Props / Emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
    /** Present = edit mode (draft only). Absent/null = create mode. */
    quotation?: Quotation | null
}

const props = withDefaults(defineProps<Props>(), {
    quotation: null,
})

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    /** Emitted after a successful save with the created/updated quotation. */
    saved: [quotation: Quotation]
}>()

// ── Composables ───────────────────────────────────────────────────────────────

const toast = useToast()
const { formatCents } = useFormatCurrency()

// ── Customer picker state ─────────────────────────────────────────────────────

const customerSelectorOpen = ref(false)
const selectedCustomerId = ref<number | null>(null)
const selectedCustomerName = ref<string | null>(null)

function openCustomerSelector(): void {
    customerSelectorOpen.value = true
}

function onCustomerSelected(id: number | null, name: string | null): void {
    selectedCustomerId.value = id
    selectedCustomerName.value = name
}

function clearCustomer(): void {
    selectedCustomerId.value = null
    selectedCustomerName.value = null
}

// ── Line items ────────────────────────────────────────────────────────────────

interface LineItem {
    /** Local ID for v-for key stability — not sent to the backend. */
    _key: number
    product_id: number | null
    description: string
    quantity: number
    /** User-visible string input (e.g. "45.50") — converted to cents on submit. */
    unitPriceInput: string
}

let nextKey = 0

function makeBlankLine(): LineItem {
    return { _key: nextKey++, product_id: null, description: '', quantity: 1, unitPriceInput: '0.00' }
}

const lines = ref<LineItem[]>([makeBlankLine()])

function addLine(): void {
    lines.value.push(makeBlankLine())
}

function removeLine(idx: number): void {
    lines.value.splice(idx, 1)
}

function moveLine(idx: number, direction: 'up' | 'down'): void {
    const target = direction === 'up' ? idx - 1 : idx + 1
    if (target < 0 || target >= lines.value.length) return
    const arr = [...lines.value]
    ;[arr[idx], arr[target]] = [arr[target], arr[idx]]
    lines.value = arr
}

// ── Product picker per line ────────────────────────────────────────────────────

interface ProductSearchState {
    query: string
    results: Product[]
    isLoading: boolean
    open: boolean
}

// One product search state per line (keyed by LineItem._key).
const productSearchMap = ref<Record<number, ProductSearchState>>({})

function getProductSearch(key: number): ProductSearchState {
    if (!productSearchMap.value[key]) {
        productSearchMap.value[key] = { query: '', results: [], isLoading: false, open: false }
    }
    return productSearchMap.value[key]
}

let productSearchTimers: Record<number, ReturnType<typeof setTimeout>> = {}

function onProductQueryInput(line: LineItem): void {
    const state = getProductSearch(line._key)
    if (productSearchTimers[line._key]) clearTimeout(productSearchTimers[line._key])
    productSearchTimers[line._key] = setTimeout(() => { void searchProducts(line) }, 300)
    state.open = true
}

async function searchProducts(line: LineItem): Promise<void> {
    const state = getProductSearch(line._key)
    state.isLoading = true
    try {
        const result = await ProductsService.list({ search: state.query, is_active: true, per_page: 10 })
        state.results = result.data
    } catch {
        state.results = []
    } finally {
        state.isLoading = false
    }
}

function pickProduct(line: LineItem, product: Product): void {
    line.product_id = product.id
    line.description = product.name
    // Use base_price_cents as the default unit price snapshot
    line.unitPriceInput = formatCentsToInput(product.base_price_cents)
    const state = getProductSearch(line._key)
    state.query = product.name
    state.open = false
}

function clearProductPick(line: LineItem): void {
    line.product_id = null
    const state = getProductSearch(line._key)
    state.query = ''
    state.open = false
    state.results = []
}

function closeProductDropdown(key: number): void {
    const state = productSearchMap.value[key]
    if (state) state.open = false
}

function scheduleCloseDropdown(key: number): void {
    window.setTimeout(() => closeProductDropdown(key), 200)
}

// ── Money helpers ─────────────────────────────────────────────────────────────

/**
 * Parse a user-typed price string to integer cents.
 * "45000.50" → 4500050  |  "100" → 10000  |  "" → 0  |  "abc" → 0
 *
 * Algorithm: multiply by 100, then Math.round to avoid floating-point drift.
 * This matches the server formula exactly for prices (price is a decimal snapshot,
 * not subject to floor — only tax uses Math.floor per the spec).
 */
function parseCents(raw: string): number {
    const cleaned = raw.replace(/[^0-9.]/g, '')
    if (!cleaned) return 0
    const n = parseFloat(cleaned)
    if (isNaN(n)) return 0
    return Math.round(n * 100)
}

function formatCentsToInput(cents: number): string {
    return (cents / 100).toFixed(2)
}

// ── Discount / tax / date / notes / terms ─────────────────────────────────────

const discountInput = ref('0.00')
const taxRateInput = ref('0')      // percentage string: "13" = 13% = 1300 bps
const issueDate = ref(todayIso())
const validUntil = ref('')
const notes = ref('')
const terms = ref('')

function todayIso(): string {
    return new Date().toISOString().slice(0, 10)
}

const discountCents = computed(() => parseCents(discountInput.value))

/**
 * Convert a percentage string to basis points.
 * "13" → 1300  |  "13.5" → 1350  |  "" → 0
 * Clamped to 0..9999 matching the backend validation.
 */
function parseTaxRateBps(raw: string): number {
    const cleaned = raw.replace(/[^0-9.]/g, '')
    if (!cleaned) return 0
    const pct = parseFloat(cleaned)
    if (isNaN(pct)) return 0
    const bps = Math.round(pct * 100)
    return Math.min(9999, Math.max(0, bps))
}

const taxRateBps = computed(() => parseTaxRateBps(taxRateInput.value))

// ── Live totals — must exactly match the server formula ──────────────────────
//
// Server (QuotationService::calculateTotals):
//   subtotal     = sum(quantity * unit_price_cents)   for each item
//   taxableBase  = max(0, subtotal - discount_cents)
//   tax          = Math.floor(taxableBase * tax_rate_bps / 10000)
//   total        = taxableBase + tax
//
// The spec says "Math.floor for tax" — match exactly.

const subtotalCents = computed(() =>
    lines.value.reduce((sum, line) => {
        const unitCents = parseCents(line.unitPriceInput)
        return sum + Math.max(0, line.quantity) * unitCents
    }, 0),
)

const taxableBase = computed(() => Math.max(0, subtotalCents.value - discountCents.value))

const taxCents = computed(() => Math.floor((taxableBase.value * taxRateBps.value) / 10000))

const totalCents = computed(() => taxableBase.value + taxCents.value)

function lineTotalCents(line: LineItem): number {
    return Math.max(0, line.quantity) * parseCents(line.unitPriceInput)
}

// ── Validation ────────────────────────────────────────────────────────────────

const fieldErrors = ref<Record<string, string>>({})

function validate(): boolean {
    fieldErrors.value = {}

    if (!issueDate.value) {
        fieldErrors.value.issue_date = 'La fecha de emisión es requerida.'
    }

    // At least one line with a non-empty description and quantity ≥ 1
    if (lines.value.length === 0) {
        fieldErrors.value.items = 'Debes agregar al menos una línea.'
    } else {
        const valid = lines.value.every(
            (l) => l.description.trim().length > 0 && l.quantity >= 1,
        )
        if (!valid) {
            fieldErrors.value.items = 'Cada línea debe tener una descripción y cantidad ≥ 1.'
        }
    }

    return Object.keys(fieldErrors.value).length === 0
}

// ── Form state ────────────────────────────────────────────────────────────────

const isSubmitting = ref(false)
const isEditMode = computed(() => props.quotation !== null)

const slideoverTitle = computed(() =>
    isEditMode.value ? 'Editar cotización' : 'Nueva cotización',
)

const slideoverSubtitle = computed(() =>
    isEditMode.value
        ? `Editando ${props.quotation?.quotation_number ?? 'borrador'}`
        : 'Crea un nuevo presupuesto',
)

// ── Fill / reset ──────────────────────────────────────────────────────────────

function fillFromQuotation(q: Quotation): void {
    selectedCustomerId.value = q.customer?.id ?? null
    selectedCustomerName.value = q.customer?.name ?? null
    issueDate.value = q.issue_date
    validUntil.value = q.valid_until ?? ''
    discountInput.value = formatCentsToInput(q.discount_cents)
    taxRateInput.value = (q.tax_rate_bps / 100).toFixed(2).replace(/\.00$/, '')
    notes.value = q.notes ?? ''
    terms.value = q.terms ?? ''
    productSearchMap.value = {}
    productSearchTimers = {}
    nextKey = 0

    lines.value = (q.items ?? []).map((item) => {
        const key = nextKey++
        productSearchMap.value[key] = {
            query: item.product_id ? item.description : '',
            results: [],
            isLoading: false,
            open: false,
        }
        return {
            _key: key,
            product_id: item.product_id,
            description: item.description,
            quantity: item.quantity,
            unitPriceInput: formatCentsToInput(item.unit_price_cents),
        }
    })

    if (lines.value.length === 0) lines.value.push(makeBlankLine())
}

function resetForm(): void {
    selectedCustomerId.value = null
    selectedCustomerName.value = null
    issueDate.value = todayIso()
    validUntil.value = ''
    discountInput.value = '0.00'
    taxRateInput.value = '0'
    notes.value = ''
    terms.value = ''
    fieldErrors.value = {}
    productSearchMap.value = {}
    productSearchTimers = {}
    nextKey = 0
    lines.value = [makeBlankLine()]
}

// ── Watchers ──────────────────────────────────────────────────────────────────

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return
        if (props.quotation) {
            fillFromQuotation(props.quotation)
        } else {
            resetForm()
        }
    },
)

// Handle the parent swapping `quotation` while the slideover is already open
// (e.g. user clicks a different draft row without closing first).
watch(
    () => props.quotation,
    (q) => {
        if (!props.modelValue) return
        if (q) {
            fillFromQuotation(q)
        } else {
            resetForm()
        }
    },
)

// ── Close ─────────────────────────────────────────────────────────────────────

function close(): void {
    emit('update:modelValue', false)
    resetForm()
}

// ── Submit ────────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
    if (!validate()) return

    const itemPayloads: QuotationItemPayload[] = lines.value.map((line, idx) => ({
        product_id: line.product_id ?? null,
        description: line.description.trim(),
        quantity: line.quantity,
        unit_price_cents: parseCents(line.unitPriceInput),
        sort_order: idx,
    }))

    const payload: CreateQuotationPayload = {
        customer_id: selectedCustomerId.value,
        issue_date: issueDate.value,
        valid_until: validUntil.value || null,
        discount_cents: discountCents.value,
        // Only send tax_rate_bps when the user has explicitly set it (non-zero),
        // otherwise let the server apply the tenant default.
        ...(taxRateBps.value > 0 ? { tax_rate_bps: taxRateBps.value } : {}),
        notes: notes.value.trim() || null,
        terms: terms.value.trim() || null,
        items: itemPayloads,
    }

    isSubmitting.value = true
    try {
        const saved = isEditMode.value
            ? await QuotationService.update(props.quotation!.id, payload)
            : await QuotationService.create(payload)

        toast.success(isEditMode.value ? 'Cotización actualizada.' : 'Cotización creada.')
        emit('saved', saved)
        close()
    } catch (err: unknown) {
        const apiErr = err as {
            response?: {
                status?: number
                data?: { message?: string; errors?: Record<string, string[]> }
            }
        }

        if (apiErr.response?.status === 422) {
            const serverErrors = apiErr.response.data?.errors ?? {}
            for (const [field, messages] of Object.entries(serverErrors)) {
                fieldErrors.value[field] = messages[0] ?? 'Campo inválido.'
            }
            toast.error(apiErr.response.data?.message ?? 'Revisa los campos marcados.')
        } else {
            toast.error(apiErr.response?.data?.message ?? 'No se pudo guardar la cotización.')
        }
    } finally {
        isSubmitting.value = false
    }
}

// ── Input class helper ────────────────────────────────────────────────────────

function inputClass(hasError = false): string {
    return [
        'w-full px-4 py-2.5 rounded-xl',
        'bg-surface-low text-on-surface text-sm',
        'placeholder:text-on-surface-variant/50',
        'transition-colors duration-150',
        'focus:outline-none focus:ring-2 focus:ring-primary/30',
        'dark:bg-surface-mid dark:text-on-surface',
        hasError ? 'ring-2 ring-error/60' : '',
    ].join(' ')
}

// Narrow input used inside line rows (no horizontal padding override needed)
function narrowInputClass(hasError = false): string {
    return [
        'w-full px-3 py-2 rounded-lg',
        'bg-surface-low text-on-surface text-sm',
        'placeholder:text-on-surface-variant/50',
        'transition-colors duration-150',
        'focus:outline-none focus:ring-2 focus:ring-primary/30',
        'dark:bg-surface-mid dark:text-on-surface',
        hasError ? 'ring-2 ring-error/60' : '',
    ].join(' ')
}
</script>

<template>
    <!-- The customer-selector opens as a nested slideover (same pattern as POS) -->
    <PosCustomerSelector
        :model-value="customerSelectorOpen"
        :selected-id="selectedCustomerId"
        @update:model-value="customerSelectorOpen = $event"
        @select="onCustomerSelected"
    />

    <AppSlideover
        :model-value="modelValue"
        :title="slideoverTitle"
        :subtitle="slideoverSubtitle"
        width="600px"
        test-id="quotation-builder-slideover"
        @update:model-value="close"
    >
        <form
            class="flex flex-col gap-6"
            novalidate
            @submit.prevent="submit"
        >

            <!-- ── Customer (optional) ──────────────────────────────────── -->
            <section aria-label="Cliente" data-testid="section-customer">
                <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant mb-2">
                    Cliente
                </p>

                <div v-if="selectedCustomerName" class="flex items-center gap-3 rounded-xl px-4 py-3 bg-surface-low dark:bg-surface-mid">
                    <span
                        class="flex items-center justify-center w-9 h-9 rounded-full shrink-0 text-xs font-bold"
                        style="background: var(--primary-container); color: var(--primary)"
                        aria-hidden="true"
                    >
                        {{ selectedCustomerName.slice(0, 2).toUpperCase() }}
                    </span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-semibold text-on-surface truncate">
                            {{ selectedCustomerName }}
                        </span>
                        <span class="block text-xs text-on-surface-variant">Cliente seleccionado</span>
                    </span>
                    <button
                        type="button"
                        class="btn-icon shrink-0"
                        aria-label="Quitar cliente"
                        data-testid="btn-clear-customer"
                        @click="clearCustomer"
                    >
                        <X :size="16" />
                    </button>
                </div>

                <button
                    v-else
                    type="button"
                    class="w-full flex items-center gap-3 rounded-xl px-4 py-3 text-left
                           bg-surface-low hover:bg-surface-mid transition-colors
                           dark:bg-surface-mid dark:hover:bg-surface-high"
                    data-testid="btn-pick-customer"
                    @click="openCustomerSelector"
                >
                    <span
                        class="flex items-center justify-center w-9 h-9 rounded-full shrink-0"
                        style="background: var(--surface-high)"
                        aria-hidden="true"
                    >
                        <User :size="16" style="color: var(--on-surface-variant)" />
                    </span>
                    <span class="flex-1 text-sm text-on-surface-variant">
                        Sin cliente asignado — click para buscar
                    </span>
                    <ChevronDown :size="16" class="text-on-surface-variant" aria-hidden="true" />
                </button>
            </section>

            <!-- ── Dates ────────────────────────────────────────────────── -->
            <section aria-label="Fechas">
                <div class="grid grid-cols-2 gap-3">

                    <div class="flex flex-col gap-1.5">
                        <label
                            for="qb-issue-date"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Fecha de emisión <span class="text-error" aria-hidden="true">*</span>
                        </label>
                        <input
                            id="qb-issue-date"
                            v-model="issueDate"
                            type="date"
                            :class="inputClass(!!fieldErrors.issue_date)"
                            required
                            aria-required="true"
                            data-testid="input-issue-date"
                        />
                        <p v-if="fieldErrors.issue_date" class="text-xs text-error" role="alert">
                            {{ fieldErrors.issue_date }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label
                            for="qb-valid-until"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Válida hasta
                        </label>
                        <input
                            id="qb-valid-until"
                            v-model="validUntil"
                            type="date"
                            :class="inputClass()"
                            data-testid="input-valid-until"
                        />
                    </div>

                </div>
            </section>

            <!-- ── Line items ────────────────────────────────────────────── -->
            <section aria-label="Líneas de la cotización" data-testid="section-line-items">

                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                        Líneas <span class="text-error" aria-hidden="true">*</span>
                    </p>
                    <AppButton
                        variant="ghost"
                        size="sm"
                        :icon="Plus"
                        type="button"
                        data-testid="btn-add-line"
                        @click="addLine"
                    >
                        Agregar línea
                    </AppButton>
                </div>

                <!-- Line items error (no lines / invalid lines) -->
                <p v-if="fieldErrors.items" class="text-xs text-error mb-2" role="alert" data-testid="error-items">
                    {{ fieldErrors.items }}
                </p>

                <!-- Lines list -->
                <div class="flex flex-col gap-3">
                    <div
                        v-for="(line, idx) in lines"
                        :key="line._key"
                        class="rounded-xl bg-surface-low dark:bg-surface-mid p-3 flex flex-col gap-2"
                        :data-testid="`line-item-${idx}`"
                    >
                        <!-- Row top: product picker + sort controls -->
                        <div class="flex items-center gap-2">

                            <!-- Product search -->
                            <div class="relative flex-1 min-w-0">
                                <!-- The query input drives both product search and description snapshot -->
                                <div class="relative">
                                    <Tag
                                        :size="14"
                                        class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-on-surface-variant"
                                        aria-hidden="true"
                                    />
                                    <input
                                        v-model="getProductSearch(line._key).query"
                                        type="text"
                                        :class="narrowInputClass() + ' pl-8'"
                                        placeholder="Buscar producto..."
                                        :aria-label="`Buscar producto para línea ${idx + 1}`"
                                        :data-testid="`input-product-search-${idx}`"
                                        @input="onProductQueryInput(line)"
                                        @blur="scheduleCloseDropdown(line._key)"
                                    />
                                </div>

                                <!-- Product dropdown -->
                                <div
                                    v-if="getProductSearch(line._key).open"
                                    class="absolute z-10 mt-1 w-full rounded-xl shadow-[var(--shadow-lifted)]
                                           bg-surface-lowest dark:bg-surface-low overflow-hidden max-h-48 overflow-y-auto"
                                    data-testid="product-dropdown"
                                >
                                    <div v-if="getProductSearch(line._key).isLoading" class="flex justify-center py-4">
                                        <AppSpinner />
                                    </div>
                                    <template v-else>
                                        <button
                                            v-for="product in getProductSearch(line._key).results"
                                            :key="product.id"
                                            type="button"
                                            class="w-full flex items-center gap-2 px-3 py-2.5 text-left text-sm
                                                   hover:bg-surface-low dark:hover:bg-surface-mid transition-colors"
                                            :data-testid="`product-option-${product.id}`"
                                            @mousedown.prevent="pickProduct(line, product)"
                                        >
                                            <img
                                                v-if="product.default_image_url"
                                                :src="product.default_image_url"
                                                :alt="product.name"
                                                class="w-8 h-8 rounded-lg object-cover shrink-0"
                                            />
                                            <span
                                                v-else
                                                class="w-8 h-8 rounded-lg shrink-0 flex items-center justify-center"
                                                style="background: var(--primary-container)"
                                                aria-hidden="true"
                                            >
                                                <Tag :size="12" style="color: var(--primary)" />
                                            </span>
                                            <span class="flex-1 min-w-0">
                                                <span class="block text-on-surface truncate font-medium">{{ product.name }}</span>
                                                <span class="block text-xs text-on-surface-variant">
                                                    {{ formatCents(product.base_price_cents) }}
                                                </span>
                                            </span>
                                        </button>
                                        <div
                                            v-if="getProductSearch(line._key).results.length === 0"
                                            class="px-3 py-3 text-xs text-on-surface-variant text-center"
                                        >
                                            Sin resultados
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Clear product link -->
                            <button
                                v-if="line.product_id"
                                type="button"
                                class="btn-icon shrink-0"
                                :aria-label="`Quitar producto de línea ${idx + 1}`"
                                :data-testid="`btn-clear-product-${idx}`"
                                @click="clearProductPick(line)"
                            >
                                <X :size="14" />
                            </button>

                            <!-- Up / down / remove controls -->
                            <div class="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    :disabled="idx === 0"
                                    class="btn-icon disabled:opacity-30"
                                    :aria-label="`Subir línea ${idx + 1}`"
                                    :data-testid="`btn-move-up-${idx}`"
                                    @click="moveLine(idx, 'up')"
                                >
                                    <ArrowUp :size="14" />
                                </button>
                                <button
                                    type="button"
                                    :disabled="idx === lines.length - 1"
                                    class="btn-icon disabled:opacity-30"
                                    :aria-label="`Bajar línea ${idx + 1}`"
                                    :data-testid="`btn-move-down-${idx}`"
                                    @click="moveLine(idx, 'down')"
                                >
                                    <ArrowDown :size="14" />
                                </button>
                                <button
                                    type="button"
                                    :disabled="lines.length === 1"
                                    class="btn-icon text-error disabled:opacity-30"
                                    :aria-label="`Eliminar línea ${idx + 1}`"
                                    :data-testid="`btn-remove-line-${idx}`"
                                    @click="removeLine(idx)"
                                >
                                    <Trash2 :size="14" />
                                </button>
                            </div>
                        </div>

                        <!-- Row bottom: description + qty + unit price + line total -->
                        <div class="grid grid-cols-[1fr_64px_96px_auto] gap-2 items-end">

                            <!-- Description -->
                            <div class="flex flex-col gap-1">
                                <label
                                    :for="`qb-desc-${idx}`"
                                    class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant"
                                >
                                    Descripción
                                </label>
                                <input
                                    :id="`qb-desc-${idx}`"
                                    v-model="line.description"
                                    type="text"
                                    placeholder="Descripción de la línea"
                                    :class="narrowInputClass()"
                                    :aria-label="`Descripción línea ${idx + 1}`"
                                    :data-testid="`input-description-${idx}`"
                                />
                            </div>

                            <!-- Quantity -->
                            <div class="flex flex-col gap-1">
                                <label
                                    :for="`qb-qty-${idx}`"
                                    class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant"
                                >
                                    Cant.
                                </label>
                                <input
                                    :id="`qb-qty-${idx}`"
                                    v-model.number="line.quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    :class="narrowInputClass() + ' text-center'"
                                    :aria-label="`Cantidad línea ${idx + 1}`"
                                    :data-testid="`input-quantity-${idx}`"
                                />
                            </div>

                            <!-- Unit price -->
                            <div class="flex flex-col gap-1">
                                <label
                                    :for="`qb-price-${idx}`"
                                    class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant"
                                >
                                    Precio unit.
                                </label>
                                <input
                                    :id="`qb-price-${idx}`"
                                    v-model="line.unitPriceInput"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    :class="narrowInputClass() + ' text-right'"
                                    :aria-label="`Precio unitario línea ${idx + 1}`"
                                    :data-testid="`input-unit-price-${idx}`"
                                />
                            </div>

                            <!-- Line total (read-only) -->
                            <div class="flex flex-col gap-1 items-end pb-0.5">
                                <span class="text-[10px] uppercase tracking-[0.05em] text-on-surface-variant">
                                    Total
                                </span>
                                <span
                                    class="text-sm font-semibold text-primary whitespace-nowrap"
                                    :data-testid="`line-total-${idx}`"
                                >
                                    {{ formatCents(lineTotalCents(line)) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ── Discount / Tax ─────────────────────────────────────── -->
            <section aria-label="Descuento e impuesto">
                <div class="grid grid-cols-2 gap-3">

                    <div class="flex flex-col gap-1.5">
                        <label
                            for="qb-discount"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Descuento
                        </label>
                        <input
                            id="qb-discount"
                            v-model="discountInput"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                            :class="inputClass()"
                            data-testid="input-discount"
                        />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label
                            for="qb-tax-rate"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            IVA (%)
                        </label>
                        <input
                            id="qb-tax-rate"
                            v-model="taxRateInput"
                            type="text"
                            inputmode="decimal"
                            placeholder="0"
                            :class="inputClass()"
                            data-testid="input-tax-rate"
                        />
                        <p class="text-[10px] text-on-surface-variant">
                            {{ taxRateBps }} bps
                        </p>
                    </div>

                </div>
            </section>

            <!-- ── Live totals panel ─────────────────────────────────── -->
            <section
                aria-label="Totales"
                class="rounded-xl bg-surface-low dark:bg-surface-mid px-4 py-4"
                data-testid="totals-panel"
            >
                <div class="flex flex-col gap-2">

                    <div class="flex justify-between items-center text-sm">
                        <span class="text-on-surface-variant">Subtotal</span>
                        <span class="font-medium text-on-surface" data-testid="live-subtotal">
                            {{ formatCents(subtotalCents) }}
                        </span>
                    </div>

                    <div v-if="discountCents > 0" class="flex justify-between items-center text-sm">
                        <span class="text-on-surface-variant">Descuento</span>
                        <span class="font-medium text-error" data-testid="live-discount">
                            -{{ formatCents(discountCents) }}
                        </span>
                    </div>

                    <div v-if="taxRateBps > 0" class="flex justify-between items-center text-sm">
                        <span class="text-on-surface-variant">
                            IVA ({{ (taxRateBps / 100).toFixed(2).replace(/\.?0+$/, '') }}%)
                        </span>
                        <span class="font-medium text-on-surface" data-testid="live-tax">
                            {{ formatCents(taxCents) }}
                        </span>
                    </div>

                    <!-- Divider via bg shift (No-Line Rule) -->
                    <div class="h-px bg-surface-high dark:bg-surface-high mt-1 mb-1" aria-hidden="true" />

                    <div class="flex justify-between items-center">
                        <span class="text-sm font-semibold text-on-surface">Total</span>
                        <span class="text-lg font-bold text-primary" data-testid="live-total">
                            {{ formatCents(totalCents) }}
                        </span>
                    </div>

                </div>
            </section>

            <!-- ── Notes / Terms ─────────────────────────────────────── -->
            <section aria-label="Notas y términos">

                <div class="flex flex-col gap-1.5 mb-4">
                    <label
                        for="qb-notes"
                        class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                    >
                        Notas al cliente
                    </label>
                    <textarea
                        id="qb-notes"
                        v-model="notes"
                        rows="2"
                        placeholder="Observaciones visibles en el PDF..."
                        :class="inputClass() + ' resize-none'"
                        data-testid="input-notes"
                    />
                </div>

                <div class="flex flex-col gap-1.5">
                    <label
                        for="qb-terms"
                        class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                    >
                        Términos y condiciones
                    </label>
                    <textarea
                        id="qb-terms"
                        v-model="terms"
                        rows="3"
                        placeholder="Condiciones de pago, entrega, validez..."
                        :class="inputClass() + ' resize-none'"
                        data-testid="input-terms"
                    />
                </div>

            </section>

        </form>

        <!-- Footer -->
        <template #footer>
            <div class="flex gap-3">
                <AppButton
                    variant="secondary"
                    class="flex-1"
                    :disabled="isSubmitting"
                    @click="close"
                >
                    Cancelar
                </AppButton>
                <AppButton
                    variant="primary"
                    class="flex-1"
                    :loading="isSubmitting"
                    :disabled="isSubmitting || lines.length === 0"
                    data-testid="btn-submit-quotation"
                    @click="submit"
                >
                    {{ isEditMode ? 'Guardar cambios' : 'Crear cotización' }}
                </AppButton>
            </div>
        </template>

    </AppSlideover>
</template>
