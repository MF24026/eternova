<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { DollarSign, Sparkles, FileText, ExternalLink, AlertCircle } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ExpenseService from '@/services/ExpenseService'
import { useBranches } from '@/composables/useBranches'
import { useToast } from '@/composables/useToast'
import {
    ALL_EXPENSE_PAYMENT_METHODS,
    EXPENSE_PAYMENT_METHOD_LABELS,
} from '@/constants/expenses'
import type {
    Expense,
    ExpenseCategory,
    ExpensePaymentMethod,
} from '@/types/domain/Expense'

// ── Props / emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
    /** The draft expense to verify. Can be passed directly (from upload flow)
     *  or loaded from the list when the user clicks "Verificar" on a draft row. */
    expense: Expense | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    /** Emitted after a successful PATCH is_verified=true. */
    verified: [expense: Expense]
}>()

// ── OCR data helpers ──────────────────────────────────────────────────────────

// ocr_data shape from the backend OcrResult:
// { vendor, amount_cents, date, raw_text, confidence, line_items[] }
interface OcrData {
    vendor?: string | null
    amount_cents?: number | null
    date?: string | null
    raw_text?: string | null
    confidence?: number | null
}

function getOcrData(expense: Expense | null): OcrData {
    if (!expense?.ocr_data) return {}
    return expense.ocr_data as OcrData
}

// A field was "suggested by OCR" when the expense has ocr_data and the field
// matches the suggested value (user hasn't overridden it yet on first load).
function ocrSuggested(expense: Expense | null): boolean {
    return !!(expense?.ocr_data && expense.ocr_status === 'done')
}

// ── Categories ────────────────────────────────────────────────────────────────

const categories = ref<ExpenseCategory[]>([])

async function loadCategories(): Promise<void> {
    try {
        categories.value = await ExpenseService.listCategories()
    } catch {
        categories.value = []
    }
}

// ── Composables ───────────────────────────────────────────────────────────────

const { branches, loadBranches } = useBranches()
const toast = useToast()

// ── Form state ────────────────────────────────────────────────────────────────

const description = ref('')
const amountInput = ref('')
const expenseDate = ref('')
const categoryId = ref<number | ''>('')
const branchId = ref('')
const vendor = ref('')
const paymentMethod = ref<ExpensePaymentMethod | ''>('')
const notes = ref('')

const isSubmitting = ref(false)
const fieldErrors = ref<Record<string, string>>({})

// Tracks whether each field was pre-filled from OCR (for the "Sugerido por OCR" hint).
// The hint is shown only when the field was filled from ocr_data on open — once the
// user edits the field, the hint stays visible (it's informational, not ephemeral).
const ocrFilledFields = ref<Set<string>>(new Set())

// ── Parse / format helpers ────────────────────────────────────────────────────

function parseCents(raw: string): number {
    const cleaned = raw.replace(/[^0-9.]/g, '')
    const n = parseFloat(cleaned)
    if (isNaN(n)) return 0
    return Math.round(n * 100)
}

const amountCents = computed(() => parseCents(amountInput.value))

function todayIso(): string {
    return new Date().toISOString().slice(0, 10)
}

// ── Fill form ─────────────────────────────────────────────────────────────────

function fillForm(expense: Expense): void {
    const ocr = getOcrData(expense)
    const isSuggested = ocrSuggested(expense)
    ocrFilledFields.value = new Set()

    // Description: prefer the existing expense description (not from OCR).
    description.value = expense.description ?? ''

    // Amount: prefer OCR suggestion if available, else existing value.
    if (isSuggested && ocr.amount_cents != null) {
        amountInput.value = (ocr.amount_cents / 100).toFixed(2)
        ocrFilledFields.value.add('amount')
    } else {
        amountInput.value = expense.amount_cents > 0
            ? (expense.amount_cents / 100).toFixed(2)
            : ''
    }

    // Date: prefer OCR suggestion if available, else existing value.
    if (isSuggested && ocr.date) {
        expenseDate.value = ocr.date
        ocrFilledFields.value.add('expense_date')
    } else {
        expenseDate.value = expense.expense_date ?? todayIso()
    }

    // Vendor: prefer OCR suggestion if available, else existing value.
    if (isSuggested && ocr.vendor) {
        vendor.value = ocr.vendor
        ocrFilledFields.value.add('vendor')
    } else {
        vendor.value = expense.vendor ?? ''
    }

    categoryId.value = expense.category?.id ?? ''
    branchId.value = expense.branch?.id ?? ''
    paymentMethod.value = expense.payment_method ?? ''
    notes.value = expense.notes ?? ''

    fieldErrors.value = {}
}

function resetForm(): void {
    description.value = ''
    amountInput.value = ''
    expenseDate.value = todayIso()
    categoryId.value = ''
    branchId.value = ''
    vendor.value = ''
    paymentMethod.value = ''
    notes.value = ''
    fieldErrors.value = {}
    ocrFilledFields.value = new Set()
}

// ── Watchers ──────────────────────────────────────────────────────────────────

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return
        void loadBranches()
        void loadCategories()
        if (props.expense) {
            fillForm(props.expense)
        } else {
            resetForm()
        }
    },
)

watch(
    () => props.expense,
    (expense) => {
        if (!props.modelValue) return
        if (expense) {
            fillForm(expense)
        } else {
            resetForm()
        }
    },
)

onMounted(() => {
    void loadBranches()
    void loadCategories()
})

// ── Derived display ───────────────────────────────────────────────────────────

const ocrFailed = computed(() => props.expense?.ocr_status === 'failed')

const ocrConfidence = computed(() => {
    const ocr = getOcrData(props.expense)
    return ocr.confidence != null ? Math.round(ocr.confidence * 100) : null
})

const receiptUrl = computed(() => props.expense?.receipt_url ?? null)
const isImage = computed(() => {
    const url = receiptUrl.value
    if (!url) return false
    return /\.(jpe?g|png|webp|gif)$/i.test(url)
})

const slideoverTitle = computed(() =>
    ocrFailed.value ? 'Registrar gasto manualmente' : 'Verificar gasto',
)

const slideoverSubtitle = computed(() =>
    ocrFailed.value
        ? 'No pudimos leer la factura — completa los datos manualmente.'
        : 'Revisa y corrige los datos extraídos antes de confirmar.',
)

// ── Close ─────────────────────────────────────────────────────────────────────

function close(): void {
    emit('update:modelValue', false)
}

// ── Submit ────────────────────────────────────────────────────────────────────

async function confirm(): Promise<void> {
    if (!props.expense) return

    fieldErrors.value = {}

    if (!description.value.trim()) {
        fieldErrors.value.description = 'La descripción es requerida.'
    }
    if (amountCents.value <= 0) {
        fieldErrors.value.amount = 'Ingresa un monto válido mayor a 0.'
    }
    if (!expenseDate.value) {
        fieldErrors.value.expense_date = 'La fecha es requerida.'
    }
    if (Object.keys(fieldErrors.value).length > 0) return

    isSubmitting.value = true
    try {
        // is_verified is not in the UpdateExpensePayload type (it lives in the
        // backend PATCH handler separately from the standard update fields).
        // We extend the payload with the extra field via a type assertion so we
        // don't have to widen the shared type definition for all callers.
        const verifyPayload = {
            description: description.value.trim(),
            amount_cents: amountCents.value,
            expense_date: expenseDate.value,
            expense_category_id: categoryId.value !== '' ? Number(categoryId.value) : null,
            branch_id: branchId.value || null,
            vendor: vendor.value.trim() || null,
            payment_method: paymentMethod.value || null,
            notes: notes.value.trim() || null,
            is_verified: true,
        } as Parameters<typeof ExpenseService.update>[1]

        const saved = await ExpenseService.update(props.expense.id, verifyPayload)

        toast.success('Gasto verificado.')
        emit('verified', saved)
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
            toast.error(apiErr.response?.data?.message ?? 'No se pudo guardar el gasto.')
        }
    } finally {
        isSubmitting.value = false
    }
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        :title="slideoverTitle"
        :subtitle="slideoverSubtitle"
        width="640px"
        test-id="receipt-verification-slideover"
        @update:model-value="close"
    >
        <!-- Loading skeleton while expense is null -->
        <div
            v-if="!expense"
            class="flex flex-col items-center justify-center gap-4 py-16"
        >
            <AppSpinner size="lg" />
            <p class="text-sm text-on-surface-variant">Cargando datos…</p>
        </div>

        <div v-else class="flex flex-col gap-6">

            <!-- OCR failed notice -->
            <div
                v-if="ocrFailed"
                class="flex gap-3 px-4 py-3 rounded-xl"
                style="background: var(--warning-container)"
                data-testid="ocr-failed-notice"
            >
                <AlertCircle :size="16" style="color: var(--warning)" class="shrink-0 mt-0.5" />
                <p class="text-xs leading-relaxed" style="color: var(--on-surface)">
                    No pudimos leer la factura automáticamente.
                    Completa los datos manualmente.
                </p>
            </div>

            <!-- OCR suggestion banner (only when done + has data) -->
            <div
                v-else-if="expense.ocr_status === 'done' && expense.ocr_data"
                class="flex gap-3 px-4 py-3 rounded-xl"
                style="background: var(--info-container)"
                data-testid="ocr-suggestion-banner"
            >
                <Sparkles :size="16" style="color: var(--info)" class="shrink-0 mt-0.5" />
                <div class="flex flex-col gap-0.5">
                    <p class="text-xs font-medium" style="color: var(--on-surface)">
                        Datos sugeridos por OCR
                        <span v-if="ocrConfidence !== null" class="font-normal text-on-surface-variant">
                            — confianza {{ ocrConfidence }}%
                        </span>
                    </p>
                    <p class="text-xs" style="color: var(--on-surface-variant)">
                        Los campos marcados con
                        <Sparkles :size="10" class="inline" style="color: var(--info)" />
                        fueron pre-llenados. Corrígelos si es necesario.
                    </p>
                </div>
            </div>

            <!-- Two-pane layout: receipt preview (md+) + form fields -->
            <div class="flex flex-col md:flex-row gap-5">

                <!-- Receipt preview pane (shown only when there's a receipt_url) -->
                <div
                    v-if="receiptUrl"
                    class="md:w-56 shrink-0 flex flex-col gap-2"
                    data-testid="receipt-preview-pane"
                >
                    <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                        Factura
                    </p>
                    <div class="rounded-xl overflow-hidden bg-surface-low dark:bg-surface-mid">
                        <img
                            v-if="isImage"
                            :src="receiptUrl"
                            alt="Factura"
                            class="w-full object-contain max-h-72"
                            data-testid="receipt-image"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center justify-center gap-3 py-8"
                        >
                            <FileText :size="30" class="text-on-surface-variant" />
                            <p class="text-xs text-on-surface-variant">PDF adjunto</p>
                        </div>
                    </div>
                    <a
                        v-if="receiptUrl"
                        :href="receiptUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1.5 text-xs text-primary hover:underline"
                    >
                        <ExternalLink :size="11" />
                        Ver en pantalla completa
                    </a>
                </div>

                <!-- Form fields pane -->
                <form
                    class="flex-1 flex flex-col gap-4"
                    novalidate
                    @submit.prevent="confirm"
                >
                    <!-- Description (required) -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-description"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Descripción <span class="text-error" aria-hidden="true">*</span>
                        </label>
                        <input
                            id="ver-description"
                            v-model="description"
                            type="text"
                            placeholder="Compra de insumos, pago de servicios..."
                            :class="[
                                'w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm',
                                'placeholder:text-on-surface-variant/50 transition-colors duration-150',
                                'focus:outline-none focus:ring-2 focus:ring-primary/30',
                                'dark:bg-surface-mid dark:text-on-surface',
                                fieldErrors.description ? 'ring-2 ring-error/60' : '',
                            ]"
                        />
                        <p v-if="fieldErrors.description" class="text-xs text-error" role="alert">
                            {{ fieldErrors.description }}
                        </p>
                    </div>

                    <!-- Amount (required) — may be OCR-suggested -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-amount"
                            class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Monto <span class="text-error" aria-hidden="true">*</span>
                            <span
                                v-if="ocrFilledFields.has('amount')"
                                class="inline-flex items-center gap-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded-full"
                                style="background: var(--info-container); color: var(--info)"
                                title="Sugerido por OCR"
                            >
                                <Sparkles :size="9" />
                                OCR
                            </span>
                        </label>
                        <div class="relative">
                            <span
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                                aria-hidden="true"
                            >
                                <DollarSign :size="14" />
                            </span>
                            <input
                                id="ver-amount"
                                v-model="amountInput"
                                type="text"
                                inputmode="decimal"
                                placeholder="0.00"
                                :class="[
                                    'w-full pl-9 pr-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm',
                                    'placeholder:text-on-surface-variant/50 transition-colors duration-150',
                                    'focus:outline-none focus:ring-2 focus:ring-primary/30',
                                    'dark:bg-surface-mid dark:text-on-surface',
                                    fieldErrors.amount ? 'ring-2 ring-error/60' : '',
                                ]"
                                data-testid="ver-amount"
                            />
                        </div>
                        <p v-if="fieldErrors.amount" class="text-xs text-error" role="alert">
                            {{ fieldErrors.amount }}
                        </p>
                    </div>

                    <!-- Expense date (required) — may be OCR-suggested -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-date"
                            class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Fecha <span class="text-error" aria-hidden="true">*</span>
                            <span
                                v-if="ocrFilledFields.has('expense_date')"
                                class="inline-flex items-center gap-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded-full"
                                style="background: var(--info-container); color: var(--info)"
                                title="Sugerido por OCR"
                            >
                                <Sparkles :size="9" />
                                OCR
                            </span>
                        </label>
                        <input
                            id="ver-date"
                            v-model="expenseDate"
                            type="date"
                            :class="[
                                'w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm',
                                'focus:outline-none focus:ring-2 focus:ring-primary/30',
                                'dark:bg-surface-mid dark:text-on-surface',
                                fieldErrors.expense_date ? 'ring-2 ring-error/60' : '',
                            ]"
                            data-testid="ver-date"
                        />
                        <p v-if="fieldErrors.expense_date" class="text-xs text-error" role="alert">
                            {{ fieldErrors.expense_date }}
                        </p>
                    </div>

                    <!-- Vendor — may be OCR-suggested -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-vendor"
                            class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Proveedor
                            <span
                                v-if="ocrFilledFields.has('vendor')"
                                class="inline-flex items-center gap-0.5 text-[10px] font-medium px-1.5 py-0.5 rounded-full"
                                style="background: var(--info-container); color: var(--info)"
                                title="Sugerido por OCR"
                            >
                                <Sparkles :size="9" />
                                OCR
                            </span>
                        </label>
                        <input
                            id="ver-vendor"
                            v-model="vendor"
                            type="text"
                            placeholder="Nombre del proveedor..."
                            class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                                   placeholder:text-on-surface-variant/50 transition-colors duration-150
                                   focus:outline-none focus:ring-2 focus:ring-primary/30
                                   dark:bg-surface-mid dark:text-on-surface"
                            data-testid="ver-vendor"
                        />
                    </div>

                    <!-- Category -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-category"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Categoría
                        </label>
                        <select
                            id="ver-category"
                            v-model="categoryId"
                            class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                                   focus:outline-none focus:ring-2 focus:ring-primary/30
                                   dark:bg-surface-mid dark:text-on-surface appearance-none"
                            data-testid="ver-category"
                        >
                            <option value="">Sin categoría</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                                {{ cat.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Payment method -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-payment-method"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Método de pago
                        </label>
                        <select
                            id="ver-payment-method"
                            v-model="paymentMethod"
                            class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                                   focus:outline-none focus:ring-2 focus:ring-primary/30
                                   dark:bg-surface-mid dark:text-on-surface appearance-none"
                            data-testid="ver-payment-method"
                        >
                            <option value="">Sin especificar</option>
                            <option
                                v-for="method in ALL_EXPENSE_PAYMENT_METHODS"
                                :key="method"
                                :value="method"
                            >
                                {{ EXPENSE_PAYMENT_METHOD_LABELS[method] }}
                            </option>
                        </select>
                    </div>

                    <!-- Branch -->
                    <div v-if="branches.length > 0" class="flex flex-col gap-1.5">
                        <label
                            for="ver-branch"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Sucursal
                        </label>
                        <select
                            id="ver-branch"
                            v-model="branchId"
                            class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                                   focus:outline-none focus:ring-2 focus:ring-primary/30
                                   dark:bg-surface-mid dark:text-on-surface appearance-none"
                        >
                            <option value="">Sin sucursal específica</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                                {{ branch.name }}
                            </option>
                        </select>
                    </div>

                    <!-- Notes -->
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="ver-notes"
                            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                        >
                            Notas
                        </label>
                        <textarea
                            id="ver-notes"
                            v-model="notes"
                            rows="2"
                            placeholder="Observaciones adicionales..."
                            class="w-full px-4 py-2.5 rounded-xl resize-none
                                   bg-surface-low text-on-surface text-sm
                                   placeholder:text-on-surface-variant/50 transition-colors duration-150
                                   focus:outline-none focus:ring-2 focus:ring-primary/30
                                   dark:bg-surface-mid dark:text-on-surface"
                        />
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Footer ────────────────────────────────────────────────────────── -->
        <template #footer>
            <div class="flex gap-3">
                <AppButton
                    variant="secondary"
                    class="flex-1"
                    :disabled="isSubmitting"
                    @click="close"
                >
                    Descartar
                </AppButton>
                <AppButton
                    variant="primary"
                    class="flex-1"
                    :loading="isSubmitting"
                    :disabled="isSubmitting || !expense"
                    data-testid="btn-confirmar-gasto"
                    @click="confirm"
                >
                    Confirmar gasto
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
