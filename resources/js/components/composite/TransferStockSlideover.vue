<script setup lang="ts">
import { ref, watch } from 'vue'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import { useInventoryStore } from '@/stores/inventory'
import { useBranches } from '@/composables/useBranches'
import type { BranchInventory, TransferStockInput } from '@/types/domain/Inventory'
import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'

interface Props {
    modelValue: boolean
    initialInventory?: BranchInventory
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    saved: []
}>()

const store = useInventoryStore()
const { branches, loadBranches } = useBranches()

// ── Form state ──────────────────────────────────────────────────────────────

interface TransferForm {
    from_branch_id: string
    to_branch_id: string
    product_variant_id_str: string
    quantity_str: string
    notes: string
}

const form = ref<TransferForm>({
    from_branch_id: '',
    to_branch_id: '',
    product_variant_id_str: '',
    quantity_str: '1',
    notes: '',
})

const formErrors = ref<Record<string, string[]>>({})
const isSaving = ref(false)

function fieldError(field: string): string {
    return formErrors.value[field]?.[0] ?? ''
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return
        formErrors.value = {}
        void loadBranches()

        form.value = {
            from_branch_id: props.initialInventory?.branch_id ?? '',
            to_branch_id: '',
            product_variant_id_str: props.initialInventory
                ? String(props.initialInventory.product_variant_id)
                : '',
            quantity_str: '1',
            notes: '',
        }
    },
)

// ── Submit ──────────────────────────────────────────────────────────────────

async function submit(): Promise<void> {
    formErrors.value = {}

    const variantId = parseInt(form.value.product_variant_id_str, 10)
    const qty = parseInt(form.value.quantity_str, 10)

    // Client-side validation before hitting the network.
    if (!form.value.from_branch_id) {
        formErrors.value['from_branch_id'] = ['Selecciona la sucursal de origen']
        return
    }
    if (!form.value.to_branch_id) {
        formErrors.value['to_branch_id'] = ['Selecciona la sucursal de destino']
        return
    }
    if (form.value.from_branch_id === form.value.to_branch_id) {
        formErrors.value['to_branch_id'] = ['La sucursal de destino debe ser diferente al origen']
        return
    }
    if (isNaN(variantId) || variantId <= 0) {
        formErrors.value['product_variant_id'] = ['Ingresa un ID de variante valido']
        return
    }
    if (isNaN(qty) || qty <= 0) {
        formErrors.value['quantity'] = ['La cantidad debe ser mayor a 0']
        return
    }

    const payload: TransferStockInput = {
        from_branch_id: form.value.from_branch_id,
        to_branch_id: form.value.to_branch_id,
        product_variant_id: variantId,
        quantity: qty,
        notes: form.value.notes.trim() || undefined,
    }

    isSaving.value = true
    try {
        await store.transfer(payload)
        emit('update:modelValue', false)
        emit('saved')
    } catch (err) {
        const axiosError = err as AxiosError<ApiErrorResponse>
        if (axiosError.response?.status === 422 && axiosError.response.data.errors) {
            formErrors.value = axiosError.response.data.errors as Record<string, string[]>
        } else {
            formErrors.value['_general'] = ['Error al realizar la transferencia. Intenta de nuevo.']
        }
    } finally {
        isSaving.value = false
    }
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Transferir stock"
        subtitle="Mover unidades entre sucursales"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <!-- Context card -->
            <div
                v-if="initialInventory"
                class="flex items-center gap-3 p-3 rounded-xl"
                style="background: var(--surface-low)"
            >
                <div
                    class="w-10 h-10 rounded-lg shrink-0"
                    style="background: var(--gradient-soft)"
                />
                <div class="min-w-0">
                    <p class="serif text-sm text-on-surface truncate">
                        {{ initialInventory.product_variant?.product?.name ?? 'Producto' }}
                    </p>
                    <p class="text-xs text-on-surface-variant font-mono">
                        {{ initialInventory.product_variant?.sku ?? '' }}
                    </p>
                </div>
                <div class="ml-auto text-right shrink-0">
                    <p class="text-xs text-on-surface-variant">Disponible</p>
                    <p class="font-bold text-on-surface">{{ initialInventory.available }}</p>
                </div>
            </div>

            <!-- From branch -->
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                    Desde sucursal
                </label>
                <select
                    v-model="form.from_branch_id"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30"
                    :class="fieldError('from_branch_id') ? 'ring-2 ring-error/60' : ''"
                >
                    <option value="" disabled>Selecciona origen</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">
                        {{ branch.name }}
                    </option>
                </select>
                <p v-if="fieldError('from_branch_id')" class="text-xs text-error">
                    {{ fieldError('from_branch_id') }}
                </p>
            </div>

            <!-- To branch -->
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                    Hacia sucursal
                </label>
                <select
                    v-model="form.to_branch_id"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                           focus:outline-none focus:ring-2 focus:ring-primary/30"
                    :class="fieldError('to_branch_id') ? 'ring-2 ring-error/60' : ''"
                >
                    <option value="" disabled>Selecciona destino</option>
                    <option
                        v-for="branch in branches"
                        :key="branch.id"
                        :value="branch.id"
                        :disabled="branch.id === form.from_branch_id"
                    >
                        {{ branch.name }}
                    </option>
                </select>
                <p v-if="fieldError('to_branch_id')" class="text-xs text-error">
                    {{ fieldError('to_branch_id') }}
                </p>
            </div>

            <!-- Variant ID (when not pre-filled) -->
            <div v-if="!initialInventory">
                <AppInput
                    v-model="form.product_variant_id_str"
                    label="ID de variante"
                    placeholder="ej. 42"
                    type="number"
                    :error="fieldError('product_variant_id')"
                    help-text="El ID numerico de la variante"
                />
            </div>

            <!-- Quantity -->
            <AppInput
                v-model="form.quantity_str"
                label="Cantidad a transferir"
                type="number"
                placeholder="1"
                :error="fieldError('quantity')"
                required
            />

            <!-- Notes -->
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                    Notas (opcional)
                </label>
                <textarea
                    v-model="form.notes"
                    rows="3"
                    placeholder="Motivo de la transferencia..."
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm resize-none
                           focus:outline-none focus:ring-2 focus:ring-primary/30"
                />
            </div>

            <!-- General error -->
            <p v-if="fieldError('_general')" class="text-xs text-error">
                {{ fieldError('_general') }}
            </p>
        </form>

        <template #footer>
            <div class="flex gap-2.5">
                <AppButton
                    variant="secondary"
                    class="flex-1 justify-center"
                    @click="emit('update:modelValue', false)"
                >
                    Cancelar
                </AppButton>
                <AppButton
                    type="button"
                    class="flex-1 justify-center"
                    :loading="isSaving"
                    :disabled="isSaving"
                    @click="submit"
                >
                    Transferir
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
