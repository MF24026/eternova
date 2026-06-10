<script setup lang="ts">
import { ref, watch } from 'vue'
import { Search, Check, UserX } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import CustomerService, { type CustomerSummary } from '@/services/CustomerService'
import { useToast } from '@/composables/useToast'

interface Props {
    modelValue: boolean
    selectedId: number | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    'select': [id: number | null, name: string | null]
}>()

const toast = useToast()

// ── Search state ──────────────────────────────────────────────────────────────

const query = ref('')
const customers = ref<CustomerSummary[]>([])
const isLoading = ref(false)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(query, () => {
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => { void runSearch() }, 350)
})

// Re-run search each time the selector opens so the list is fresh.
watch(() => props.modelValue, (open) => {
    if (open) {
        query.value = ''
        void runSearch()
    }
})

async function runSearch(): Promise<void> {
    isLoading.value = true
    try {
        customers.value = await CustomerService.search(query.value)
    } catch {
        toast.error('No se pudo cargar la lista de clientes')
        customers.value = []
    } finally {
        isLoading.value = false
    }
}

// ── Selection ─────────────────────────────────────────────────────────────────

function pickCustomer(customer: CustomerSummary): void {
    emit('select', customer.id, customer.name)
    emit('update:modelValue', false)
}

function clearCustomer(): void {
    emit('select', null, null)
    emit('update:modelValue', false)
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Seleccionar cliente"
        subtitle="Busca por nombre o teléfono"
        side="right"
        width="420px"
        test-id="pos-customer-selector"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <!-- Search input -->
        <div class="mb-4">
            <div class="relative">
                <Search
                    :size="16"
                    class="absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"
                    style="color: var(--on-surface-variant)"
                    aria-hidden="true"
                />
                <input
                    v-model="query"
                    type="search"
                    class="field pl-10"
                    placeholder="Nombre o teléfono..."
                    aria-label="Buscar cliente"
                    data-testid="pos-customer-search-input"
                />
            </div>
        </div>

        <!-- Walk-in option — always first -->
        <button
            type="button"
            class="w-full flex items-center gap-3 rounded-xl px-4 py-3 text-left transition-colors mb-3"
            :class="selectedId === null
                ? 'bg-primary-container'
                : 'hover:bg-surface-low'"
            style="background: var(--surface-mid)"
            aria-label="Cliente sin registrar"
            data-testid="pos-customer-walkin"
            @click="clearCustomer"
        >
            <span
                class="flex items-center justify-center w-9 h-9 rounded-full shrink-0"
                style="background: var(--surface-high)"
            >
                <UserX :size="16" style="color: var(--on-surface-variant)" aria-hidden="true" />
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-sm font-semibold" style="color: var(--on-surface)">
                    Cliente sin registrar
                </span>
                <span class="block text-xs" style="color: var(--on-surface-variant)">
                    Venta sin datos del cliente
                </span>
            </span>
            <Check
                v-if="selectedId === null"
                :size="16"
                style="color: var(--primary)"
                aria-hidden="true"
            />
        </button>

        <!-- Loading -->
        <div v-if="isLoading" class="flex justify-center py-8">
            <AppSpinner />
        </div>

        <!-- Customer list -->
        <div v-else-if="customers.length > 0" class="flex flex-col gap-1.5">
            <button
                v-for="customer in customers"
                :key="customer.id"
                type="button"
                class="w-full flex items-center gap-3 rounded-xl px-4 py-3 text-left transition-colors"
                :style="selectedId === customer.id
                    ? 'background: var(--primary-container)'
                    : 'background: var(--surface-low)'"
                :aria-pressed="selectedId === customer.id"
                :data-testid="`pos-customer-option-${customer.id}`"
                @click="pickCustomer(customer)"
            >
                <!-- Avatar initials -->
                <span
                    class="flex items-center justify-center w-9 h-9 rounded-full shrink-0 text-xs font-bold"
                    :style="selectedId === customer.id
                        ? 'background: var(--primary); color: var(--on-primary)'
                        : 'background: var(--surface-high); color: var(--on-surface-variant)'"
                    aria-hidden="true"
                >
                    {{ customer.name.slice(0, 2).toUpperCase() }}
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-semibold truncate" style="color: var(--on-surface)">
                        {{ customer.name }}
                    </span>
                    <span
                        v-if="customer.phone"
                        class="block text-xs truncate"
                        style="color: var(--on-surface-variant)"
                    >
                        {{ customer.phone }}
                    </span>
                </span>
                <Check
                    v-if="selectedId === customer.id"
                    :size="16"
                    style="color: var(--primary)"
                    aria-hidden="true"
                />
            </button>
        </div>

        <!-- Empty search result -->
        <div
            v-else
            class="py-10 text-center text-sm"
            style="color: var(--on-surface-variant)"
        >
            No se encontraron clientes.
            <br>
            <span class="text-xs">Ajusta la búsqueda o elige "Cliente sin registrar".</span>
        </div>
    </AppSlideover>
</template>
