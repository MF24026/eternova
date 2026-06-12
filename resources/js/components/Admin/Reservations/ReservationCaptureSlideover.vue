<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { DollarSign, User, Search, X } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ReservationService from '@/services/ReservationService'
import CustomerService, { type CustomerSummary } from '@/services/CustomerService'
import { useBranches } from '@/composables/useBranches'
import { useToast } from '@/composables/useToast'
import { DEFAULT_OCCASIONS } from '@/constants/reservations'
import type { CreateReservationPayload, ReservationDetail } from '@/types/domain/Reservation'

// ── Props / emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    success: [reservation: ReservationDetail]
}>()

// ── Composables ───────────────────────────────────────────────────────────────

const { branches, loadBranches } = useBranches()
const toast = useToast()

// ── Settings (occasions + deposit_pct) ────────────────────────────────────────

const depositPct = ref(30)
const occasions = ref<string[]>(DEFAULT_OCCASIONS)
const settingsLoaded = ref(false)

async function loadSettings(): Promise<void> {
    try {
        const settings = await ReservationService.getSettings()
        depositPct.value = settings.deposit_pct
        // Merge tenant occasions — keep their list if non-empty, otherwise fall back to defaults
        if (settings.occasions.length > 0) {
            occasions.value = settings.occasions
        }
        settingsLoaded.value = true
    } catch {
        // Non-critical — defaults remain in place so the form still works
        settingsLoaded.value = true
    }
}

// Reload settings whenever the slideover opens
watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            void loadSettings()
            void loadBranches()
        }
    },
)

onMounted(() => {
    void loadSettings()
    void loadBranches()
})

// ── Form state ────────────────────────────────────────────────────────────────

const description = ref('')
const occasion = ref('')
const customOccasion = ref('')
const eventDate = ref('')
const totalInput = ref('')         // string → cents on submit
const depositOverrideInput = ref('') // empty = use auto-computed default
const branchId = ref('')
const specialInstructions = ref('')
const adminNotes = ref('')

// Customer picker
const customerSearch = ref('')
const customerResults = ref<CustomerSummary[]>([])
const selectedCustomer = ref<CustomerSummary | null>(null)
const isSearching = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null

// Submission
const isSubmitting = ref(false)
const fieldErrors = ref<Record<string, string>>({})

// ── Derived ───────────────────────────────────────────────────────────────────

// Parse a decimal/currency string (e.g. "12.50" or "1250") to cents (integer).
function parseCents(raw: string): number {
    const cleaned = raw.replace(/[^0-9.]/g, '')
    const n = parseFloat(cleaned)
    if (isNaN(n)) return 0
    // If it contains a decimal point treat as units → *100; otherwise treat as cents
    return cleaned.includes('.') ? Math.round(n * 100) : Math.round(n * 100)
}

const totalCents = computed(() => parseCents(totalInput.value))

// The deposit shown as a placeholder in the override field
const computedDepositCents = computed(() =>
    Math.round(totalCents.value * (depositPct.value / 100)),
)

const depositOverrideCents = computed(() => {
    if (!depositOverrideInput.value.trim()) return null
    return parseCents(depositOverrideInput.value)
})

// Whether the user selected "Otro" in the occasion dropdown
const isCustomOccasion = computed(() => occasion.value === '__other__')

// The final occasion to send (null if blank)
const resolvedOccasion = computed<string | null>(() => {
    if (isCustomOccasion.value) return customOccasion.value.trim() || null
    return occasion.value || null
})

// ── Customer search ───────────────────────────────────────────────────────────

watch(customerSearch, (query) => {
    if (selectedCustomer.value) return // already selected — don't trigger new search

    if (searchTimer) clearTimeout(searchTimer)
    if (!query.trim()) {
        customerResults.value = []
        return
    }

    searchTimer = setTimeout(async () => {
        isSearching.value = true
        try {
            customerResults.value = await CustomerService.search(query.trim())
        } catch {
            customerResults.value = []
        } finally {
            isSearching.value = false
        }
    }, 300)
})

function selectCustomer(customer: CustomerSummary): void {
    selectedCustomer.value = customer
    customerSearch.value = customer.name
    customerResults.value = []
}

function clearCustomer(): void {
    selectedCustomer.value = null
    customerSearch.value = ''
    customerResults.value = []
}

// ── Form reset ────────────────────────────────────────────────────────────────

function resetForm(): void {
    description.value = ''
    occasion.value = ''
    customOccasion.value = ''
    eventDate.value = ''
    totalInput.value = ''
    depositOverrideInput.value = ''
    branchId.value = ''
    specialInstructions.value = ''
    adminNotes.value = ''
    customerSearch.value = ''
    customerResults.value = []
    selectedCustomer.value = null
    fieldErrors.value = {}
}

function close(): void {
    emit('update:modelValue', false)
    resetForm()
}

// ── Submit ────────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
    fieldErrors.value = {}

    // Client-side required validation
    if (!description.value.trim()) {
        fieldErrors.value.description = 'La descripción es requerida.'
    }
    if (totalCents.value <= 0) {
        fieldErrors.value.total = 'Ingresa un total válido mayor a 0.'
    }
    if (Object.keys(fieldErrors.value).length > 0) return

    const payload: CreateReservationPayload = {
        description: description.value.trim(),
        occasion: resolvedOccasion.value,
        event_date: eventDate.value || null,
        total_cents: totalCents.value,
        deposit_required_cents: depositOverrideCents.value,
        customer_id: selectedCustomer.value?.id ?? null,
        branch_id: branchId.value || null,
        special_instructions: specialInstructions.value.trim() || null,
        admin_notes: adminNotes.value.trim() || null,
    }

    isSubmitting.value = true
    try {
        const created = await ReservationService.create(payload)
        toast.success(`Reserva ${created.reservation_number} creada.`)
        emit('success', created)
        close()
    } catch (err: unknown) {
        const apiErr = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } }

        if (apiErr.response?.status === 422) {
            const serverErrors = apiErr.response.data?.errors ?? {}
            for (const [field, messages] of Object.entries(serverErrors)) {
                fieldErrors.value[field] = messages[0] ?? 'Campo inválido.'
            }
            const msg = apiErr.response.data?.message ?? 'Revisa los campos marcados.'
            toast.error(msg)
        } else {
            const msg = apiErr.response?.data?.message ?? 'No se pudo crear la reserva. Intenta de nuevo.'
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
        title="Nueva reserva"
        subtitle="Captura los datos de la reserva"
        test-id="reservation-capture-slideover"
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
                    for="res-description"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Descripción <span class="text-error" aria-hidden="true">*</span>
                </label>
                <textarea
                    id="res-description"
                    v-model="description"
                    rows="3"
                    placeholder="Arreglo floral para boda, centro de mesa..."
                    :class="[
                        'w-full px-4 py-2.5 rounded-xl resize-none',
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

            <!-- Occasion -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-occasion"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Ocasión
                </label>
                <select
                    id="res-occasion"
                    v-model="occasion"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface appearance-none"
                    aria-label="Seleccionar ocasión"
                >
                    <option value="">Sin ocasión específica</option>
                    <option v-for="occ in occasions" :key="occ" :value="occ">{{ occ }}</option>
                    <option value="__other__">Otra (especificar)</option>
                </select>
                <!-- Free-text fallback when "Otra" is selected -->
                <AppInput
                    v-if="isCustomOccasion"
                    v-model="customOccasion"
                    placeholder="Escribe la ocasión..."
                    aria-label="Ocasión personalizada"
                />
            </div>

            <!-- Event date -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-event-date"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Fecha del evento
                </label>
                <input
                    id="res-event-date"
                    v-model="eventDate"
                    type="date"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                    aria-label="Fecha del evento"
                />
            </div>

            <!-- Total -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-total"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Total <span class="text-error" aria-hidden="true">*</span>
                </label>
                <div class="relative">
                    <span
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                        aria-hidden="true"
                    >
                        <DollarSign :size="14" />
                    </span>
                    <input
                        id="res-total"
                        v-model="totalInput"
                        type="text"
                        inputmode="decimal"
                        placeholder="0.00"
                        :class="[
                            'w-full pl-9 pr-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm',
                            'placeholder:text-on-surface-variant/50',
                            'transition-colors duration-150',
                            'focus:outline-none focus:ring-2 focus:ring-primary/30',
                            'dark:bg-surface-mid dark:text-on-surface',
                            fieldErrors.total ? 'ring-2 ring-error/60' : '',
                        ]"
                        aria-required="true"
                        :aria-invalid="!!fieldErrors.total || undefined"
                    />
                </div>
                <p v-if="fieldErrors.total" class="text-xs text-error" role="alert">
                    {{ fieldErrors.total }}
                </p>
            </div>

            <!-- Deposit override -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-deposit"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Anticipo requerido
                    <span class="text-on-surface-variant font-normal normal-case tracking-normal ml-1">
                        (opcional — por defecto {{ depositPct }}%)
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
                        id="res-deposit"
                        v-model="depositOverrideInput"
                        type="text"
                        inputmode="decimal"
                        :placeholder="computedDepositCents > 0
                            ? (computedDepositCents / 100).toFixed(2)
                            : '0.00'"
                        class="w-full pl-9 pr-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                               placeholder:text-on-surface-variant/50
                               transition-colors duration-150
                               focus:outline-none focus:ring-2 focus:ring-primary/30
                               dark:bg-surface-mid dark:text-on-surface"
                        aria-label="Monto de anticipo requerido (dejar vacío para usar el porcentaje por defecto)"
                    />
                </div>
                <p class="text-xs text-on-surface-variant">
                    Deja vacío para calcular automáticamente. El valor en el campo sobreescribe el porcentaje por defecto del tenant.
                </p>
                <p v-if="fieldErrors.deposit_required_cents" class="text-xs text-error" role="alert">
                    {{ fieldErrors.deposit_required_cents }}
                </p>
            </div>

            <!-- Customer picker -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-customer-search"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Cliente
                    <span class="text-on-surface-variant font-normal normal-case tracking-normal ml-1">
                        (opcional — dejar vacío para cliente de mostrador)
                    </span>
                </label>

                <!-- Selected customer chip -->
                <div
                    v-if="selectedCustomer"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary-container"
                >
                    <User :size="14" class="text-primary shrink-0" aria-hidden="true" />
                    <span class="text-sm font-semibold text-on-surface flex-1 truncate">
                        {{ selectedCustomer.name }}
                    </span>
                    <button
                        type="button"
                        class="text-on-surface-variant hover:text-on-surface transition-colors"
                        aria-label="Quitar cliente seleccionado"
                        @click="clearCustomer"
                    >
                        <X :size="14" />
                    </button>
                </div>

                <!-- Search input when no customer selected -->
                <div v-else class="relative">
                    <span
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                        aria-hidden="true"
                    >
                        <Search :size="14" />
                    </span>
                    <input
                        id="res-customer-search"
                        v-model="customerSearch"
                        type="text"
                        placeholder="Buscar por nombre o teléfono..."
                        class="w-full pl-9 pr-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                               placeholder:text-on-surface-variant/50
                               transition-colors duration-150
                               focus:outline-none focus:ring-2 focus:ring-primary/30
                               dark:bg-surface-mid dark:text-on-surface"
                        autocomplete="off"
                        aria-label="Buscar cliente"
                        aria-autocomplete="list"
                    />
                    <AppSpinner
                        v-if="isSearching"
                        size="sm"
                        class="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none"
                    />

                    <!-- Results dropdown -->
                    <div
                        v-if="customerResults.length > 0"
                        class="absolute z-10 top-full mt-1 left-0 right-0 rounded-xl overflow-hidden
                               bg-surface-lowest dark:bg-surface-low
                               shadow-[var(--shadow-lifted)]"
                        role="listbox"
                        aria-label="Resultados de búsqueda de clientes"
                    >
                        <button
                            v-for="customer in customerResults"
                            :key="customer.id"
                            type="button"
                            class="w-full text-left px-4 py-3 flex flex-col gap-0.5
                                   hover:bg-surface-low dark:hover:bg-surface-mid
                                   transition-colors"
                            role="option"
                            :aria-selected="false"
                            @click="selectCustomer(customer)"
                        >
                            <span class="text-sm font-semibold text-on-surface">{{ customer.name }}</span>
                            <span v-if="customer.phone" class="text-xs text-on-surface-variant">{{ customer.phone }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Branch -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-branch"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Sucursal
                </label>
                <select
                    id="res-branch"
                    v-model="branchId"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface appearance-none"
                    aria-label="Sucursal de la reserva"
                >
                    <option value="">Sin sucursal específica</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                        {{ branch.name }}
                    </option>
                </select>
            </div>

            <!-- Special instructions -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-instructions"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Instrucciones especiales
                </label>
                <textarea
                    id="res-instructions"
                    v-model="specialInstructions"
                    rows="2"
                    placeholder="Preferencias de color, entrega, empaque..."
                    class="w-full px-4 py-2.5 rounded-xl resize-none
                           bg-surface-low text-on-surface text-sm
                           placeholder:text-on-surface-variant/50
                           transition-colors duration-150
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                />
            </div>

            <!-- Admin notes (internal) -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="res-admin-notes"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Notas internas
                    <span class="text-on-surface-variant font-normal normal-case tracking-normal ml-1">
                        (solo visible para el equipo)
                    </span>
                </label>
                <textarea
                    id="res-admin-notes"
                    v-model="adminNotes"
                    rows="2"
                    placeholder="Notas para el equipo, recordatorios..."
                    class="w-full px-4 py-2.5 rounded-xl resize-none
                           bg-surface-low text-on-surface text-sm
                           placeholder:text-on-surface-variant/50
                           transition-colors duration-150
                           focus:outline-none focus:ring-2 focus:ring-primary/30
                           dark:bg-surface-mid dark:text-on-surface"
                />
            </div>
        </form>

        <!-- Footer with action buttons -->
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
                    Crear reserva
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
