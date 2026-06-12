<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { DollarSign } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import ExpenseService from '@/services/ExpenseService'
import { useBranches } from '@/composables/useBranches'
import { useToast } from '@/composables/useToast'
import {
    ALL_EXPENSE_PAYMENT_METHODS,
    EXPENSE_PAYMENT_METHOD_LABELS,
} from '@/constants/expenses'
import type { Expense, ExpenseCategory, CreateExpensePayload, ExpensePaymentMethod } from '@/types/domain/Expense'

// ── Props / emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
    /** When provided, the slideover is in edit mode. Absent = create mode. */
    expense?: Expense | null
}

const props = withDefaults(defineProps<Props>(), {
    expense: null,
})

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    success: [expense: Expense]
}>()

// ── Composables ───────────────────────────────────────────────────────────────

const { branches, loadBranches } = useBranches()
const toast = useToast()

// ── Categories ────────────────────────────────────────────────────────────────

const categories = ref<ExpenseCategory[]>([])

async function loadCategories(): Promise<void> {
    try {
        categories.value = await ExpenseService.listCategories()
    } catch {
        // Non-critical — the select just stays empty; the API validates on submit
        categories.value = []
    }
}

// ── Form state ────────────────────────────────────────────────────────────────

const description = ref('')
const amountInput = ref('')       // string; parsed to cents on submit
const expenseDate = ref('')
const categoryId = ref<number | ''>('')
const branchId = ref('')
const vendor = ref('')
const paymentMethod = ref<ExpensePaymentMethod | ''>('')
const notes = ref('')

const isSubmitting = ref(false)
const fieldErrors = ref<Record<string, string>>({})

// ── Derived ───────────────────────────────────────────────────────────────────

const isEditMode = computed(() => props.expense !== null)

const slideoverTitle = computed(() => isEditMode.value ? 'Editar gasto' : 'Nuevo gasto')
const slideoverSubtitle = computed(() =>
    isEditMode.value ? 'Modifica los datos del gasto' : 'Registra un gasto manualmente',
)
const submitLabel = computed(() => isEditMode.value ? 'Guardar cambios' : 'Crear gasto')

// Parse a decimal/currency string (e.g. "12.50") to cents (integer).
// Matches the pattern used in ReservationCaptureSlideover.
function parseCents(raw: string): number {
    const cleaned = raw.replace(/[^0-9.]/g, '')
    const n = parseFloat(cleaned)
    if (isNaN(n)) return 0
    return Math.round(n * 100)
}

const amountCents = computed(() => parseCents(amountInput.value))

// ── Load on open ──────────────────────────────────────────────────────────────

function fillFormFromExpense(expense: Expense): void {
    description.value = expense.description
    amountInput.value = (expense.amount_cents / 100).toFixed(2)
    expenseDate.value = expense.expense_date
    categoryId.value = expense.category?.id ?? ''
    branchId.value = expense.branch?.id ?? ''
    vendor.value = expense.vendor ?? ''
    paymentMethod.value = expense.payment_method ?? ''
    notes.value = expense.notes ?? ''
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
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10)
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return
        void loadBranches()
        void loadCategories()
        if (props.expense) {
            fillFormFromExpense(props.expense)
        } else {
            resetForm()
        }
    },
)

// Also populate when the expense prop changes while the slideover is already open
// (edge case: parent swaps the expense prop for a different row before closing).
watch(
    () => props.expense,
    (expense) => {
        if (!props.modelValue) return
        if (expense) {
            fillFormFromExpense(expense)
        } else {
            resetForm()
        }
    },
)

onMounted(() => {
    void loadBranches()
    void loadCategories()
})

// ── Close / reset ─────────────────────────────────────────────────────────────

function close(): void {
    emit('update:modelValue', false)
    resetForm()
}

// ── Submit ────────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
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

    const payload: CreateExpensePayload = {
        description: description.value.trim(),
        amount_cents: amountCents.value,
        expense_date: expenseDate.value,
        expense_category_id: categoryId.value !== '' ? Number(categoryId.value) : null,
        branch_id: branchId.value || null,
        vendor: vendor.value.trim() || null,
        payment_method: paymentMethod.value || null,
        notes: notes.value.trim() || null,
    }

    isSubmitting.value = true
    try {
        const saved = isEditMode.value
            ? await ExpenseService.update(props.expense!.id, payload)
            : await ExpenseService.create(payload)

        toast.success(isEditMode.value ? 'Gasto actualizado.' : 'Gasto registrado.')
        emit('success', saved)
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
            const msg = apiErr.response?.data?.message ?? 'No se pudo guardar el gasto.'
            toast.error(msg)
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
        test-id="expense-form-slideover"
        @update:model-value="close"
    >
        <form
            class="flex flex-col gap-5"
            novalidate
            @submit.prevent="submit"
        >
            <!-- Description (required) -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-description"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Descripción <span class="text-error" aria-hidden="true">*</span>
                </label>
                <input
                    id="exp-description"
                    v-model="description"
                    type="text"
                    placeholder="Compra de insumos, factura de servicios..."
                    :class="[
                        'w-full px-4 py-2.5 rounded-xl',
                        'bg-surface-low text-on-surface text-sm',
                        'placeholder:text-on-surface-variant/50',
                        'transition-colors duration-150',
                        'focus:outline-none focus:ring-2 focus:ring-primary/30',
                        'dark:bg-surface-mid dark:text-on-surface',
                        fieldErrors.description ? 'ring-2 ring-error/60' : '',
                    ]"
                    required
                    aria-required="true"
                    :aria-invalid="!!fieldErrors.description || undefined"
                />
                <p v-if="fieldErrors.description" class="text-xs text-error" role="alert">
                    {{ fieldErrors.description }}
                </p>
            </div>

            <!-- Amount (required) -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-amount"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Monto <span class="text-error" aria-hidden="true">*</span>
                </label>
                <div class="relative">
                    <span
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                        aria-hidden="true"
                    >
                        <DollarSign :size="14" />
                    </span>
                    <input
                        id="exp-amount"
                        v-model="amountInput"
                        type="text"
                        inputmode="decimal"
                        placeholder="0.00"
                        :class="[
                            'w-full pl-9 pr-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm',
                            'placeholder:text-on-surface-variant/50',
                            'transition-colors duration-150',
                            'focus:outline-none focus:ring-2 focus:ring-primary/30',
                            'dark:bg-surface-mid dark:text-on-surface',
                            fieldErrors.amount ? 'ring-2 ring-error/60' : '',
                        ]"
                        aria-required="true"
                        :aria-invalid="!!fieldErrors.amount || undefined"
                    />
                </div>
                <p v-if="fieldErrors.amount" class="text-xs text-error" role="alert">
                    {{ fieldErrors.amount }}
                </p>
            </div>

            <!-- Expense date (required) -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-date"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Fecha <span class="text-error" aria-hidden="true">*</span>
                </label>
                <input
                    id="exp-date"
                    v-model="expenseDate"
                    type="date"
                    :class="[
                        'w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm',
                        'focus:outline-none focus:ring-2 focus:ring-primary/30',
                        'dark:bg-surface-mid dark:text-on-surface',
                        fieldErrors.expense_date ? 'ring-2 ring-error/60' : '',
                    ]"
                    aria-required="true"
                />
                <p v-if="fieldErrors.expense_date" class="text-xs text-error" role="alert">
                    {{ fieldErrors.expense_date }}
                </p>
            </div>

            <!-- Category -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-category"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Categoría
                </label>
                <select
                    id="exp-category"
                    v-model="categoryId"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface appearance-none"
                    aria-label="Categoría del gasto"
                >
                    <option value="">Sin categoría</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">
                        {{ cat.name }}
                    </option>
                </select>
            </div>

            <!-- Vendor -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-vendor"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Proveedor
                </label>
                <input
                    id="exp-vendor"
                    v-model="vendor"
                    type="text"
                    placeholder="Nombre del proveedor..."
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           placeholder:text-on-surface-variant/50
                           transition-colors duration-150
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                />
            </div>

            <!-- Payment method -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-payment-method"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Método de pago
                </label>
                <select
                    id="exp-payment-method"
                    v-model="paymentMethod"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface appearance-none"
                    aria-label="Método de pago"
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
            <div class="flex flex-col gap-1.5">
                <label
                    for="exp-branch"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Sucursal
                </label>
                <select
                    id="exp-branch"
                    v-model="branchId"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface appearance-none"
                    aria-label="Sucursal del gasto"
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
                    for="exp-notes"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Notas
                </label>
                <textarea
                    id="exp-notes"
                    v-model="notes"
                    rows="3"
                    placeholder="Observaciones adicionales..."
                    class="w-full px-4 py-2.5 rounded-xl resize-none
                           bg-surface-low text-on-surface text-sm
                           placeholder:text-on-surface-variant/50
                           transition-colors duration-150
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                />
            </div>

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
                    type="submit"
                    class="flex-1"
                    :loading="isSubmitting"
                    :disabled="isSubmitting"
                    @click="submit"
                >
                    {{ submitLabel }}
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
