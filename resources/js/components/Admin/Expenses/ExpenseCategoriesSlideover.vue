<script setup lang="ts">
import { ref, watch } from 'vue'
import { Plus, X, Pencil, Check } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import ExpenseService from '@/services/ExpenseService'
import { useToast } from '@/composables/useToast'
import {
    ALL_EXPENSE_CATEGORY_TYPES,
    EXPENSE_CATEGORY_TYPE_LABELS,
    EXPENSE_CATEGORY_TYPE_VARIANT,
} from '@/constants/expenses'
import type {
    ExpenseCategory,
    ExpenseCategoryType,
    CreateExpenseCategoryPayload,
} from '@/types/domain/Expense'

// ── Props / emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    /** Emitted after any create / update / remove so parent can refresh selects. */
    changed: []
}>()

// ── State ─────────────────────────────────────────────────────────────────────

type PageState = 'loading' | 'loaded' | 'error'

const pageState = ref<PageState>('loading')
const categories = ref<ExpenseCategory[]>([])
const toast = useToast()

// Add form
const newName = ref('')
const newType = ref<ExpenseCategoryType>('other')
const isAdding = ref(false)

// Inline edit state: tracks which category is being edited and its draft values
const editingId = ref<number | null>(null)
const editName = ref('')
const editType = ref<ExpenseCategoryType>('other')
const editActive = ref(true)
const isSavingEdit = ref(false)

// ── Load ──────────────────────────────────────────────────────────────────────

async function loadCategories(): Promise<void> {
    pageState.value = 'loading'
    try {
        categories.value = await ExpenseService.listCategories()
        pageState.value = 'loaded'
    } catch {
        pageState.value = 'error'
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) void loadCategories()
    },
)

// ── Add ───────────────────────────────────────────────────────────────────────

async function addCategory(): Promise<void> {
    const trimmed = newName.value.trim()
    if (!trimmed) return

    const payload: CreateExpenseCategoryPayload = {
        name: trimmed,
        type: newType.value,
        is_active: true,
    }

    isAdding.value = true
    try {
        const created = await ExpenseService.createCategory(payload)
        categories.value.push(created)
        newName.value = ''
        newType.value = 'other'
        toast.success(`Categoría "${created.name}" creada.`)
        emit('changed')
    } catch (err: unknown) {
        const apiErr = err as { response?: { data?: { message?: string } } }
        const msg = apiErr.response?.data?.message ?? 'No se pudo crear la categoría.'
        toast.error(msg)
    } finally {
        isAdding.value = false
    }
}

function onAddKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault()
        void addCategory()
    }
}

// ── Inline edit ───────────────────────────────────────────────────────────────

function startEdit(cat: ExpenseCategory): void {
    editingId.value = cat.id
    editName.value = cat.name
    editType.value = cat.type
    editActive.value = cat.is_active
}

function cancelEdit(): void {
    editingId.value = null
}

async function saveEdit(id: number): Promise<void> {
    const trimmed = editName.value.trim()
    if (!trimmed) return

    isSavingEdit.value = true
    try {
        const updated = await ExpenseService.updateCategory(id, {
            name: trimmed,
            type: editType.value,
            is_active: editActive.value,
        })
        const idx = categories.value.findIndex((c) => c.id === id)
        if (idx !== -1) categories.value[idx] = updated
        editingId.value = null
        toast.success(`Categoría "${updated.name}" actualizada.`)
        emit('changed')
    } catch (err: unknown) {
        const apiErr = err as { response?: { data?: { message?: string } } }
        const msg = apiErr.response?.data?.message ?? 'No se pudo guardar la categoría.'
        toast.error(msg)
    } finally {
        isSavingEdit.value = false
    }
}

// ── Remove ────────────────────────────────────────────────────────────────────

async function removeCategory(cat: ExpenseCategory): Promise<void> {
    try {
        await ExpenseService.removeCategory(cat.id)
        categories.value = categories.value.filter((c) => c.id !== cat.id)
        toast.success(`Categoría "${cat.name}" eliminada.`)
        emit('changed')
    } catch (err: unknown) {
        const apiErr = err as {
            response?: {
                status?: number
                data?: { message?: string; error_code?: string }
            }
        }

        // 422 with error_code 'expenses.category_in_use' means the category has
        // expenses attached and cannot be deleted — guide the user to deactivate instead.
        if (
            apiErr.response?.status === 422 &&
            apiErr.response?.data?.error_code === 'expenses.category_in_use'
        ) {
            toast.warning(`Categoría en uso — desactívala en su lugar editando el registro.`)
        } else if (apiErr.response?.status === 403) {
            toast.error('Solo propietarios y administradores pueden eliminar categorías.')
        } else {
            const msg = apiErr.response?.data?.message ?? 'No se pudo eliminar la categoría.'
            toast.error(msg)
        }
    }
}

// ── Close ─────────────────────────────────────────────────────────────────────

function close(): void {
    emit('update:modelValue', false)
    editingId.value = null
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Categorías de gastos"
        subtitle="Administra las categorías del tenant"
        test-id="expense-categories-slideover"
        @update:model-value="close"
    >
        <!-- Loading -->
        <div v-if="pageState === 'loading'" class="flex items-center justify-center py-16">
            <AppSpinner size="lg" />
        </div>

        <!-- Error -->
        <div v-else-if="pageState === 'error'" class="text-center py-16">
            <p class="text-sm text-error mb-4">No se pudo cargar las categorías.</p>
            <AppButton variant="secondary" @click="loadCategories">Reintentar</AppButton>
        </div>

        <!-- Loaded -->
        <div v-else class="flex flex-col gap-6">

            <!-- Category list -->
            <div class="flex flex-col gap-2">
                <h3 class="serif text-lg text-on-surface tracking-tighter">
                    Categorías actuales
                </h3>

                <div
                    v-if="categories.length === 0"
                    class="text-sm text-on-surface-variant italic py-4 text-center"
                >
                    No hay categorías. Agrega una usando el formulario de abajo.
                </div>

                <!-- Category rows -->
                <div
                    v-for="cat in categories"
                    :key="cat.id"
                    class="rounded-xl bg-surface-low dark:bg-surface-mid"
                    :data-testid="`category-row-${cat.id}`"
                >
                    <!-- View mode -->
                    <div
                        v-if="editingId !== cat.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <div class="flex-1 min-w-0 flex flex-col gap-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span
                                    class="text-sm font-semibold text-on-surface"
                                    :class="{ 'opacity-50 line-through': !cat.is_active }"
                                >
                                    {{ cat.name }}
                                </span>
                                <AppBadge
                                    :variant="EXPENSE_CATEGORY_TYPE_VARIANT[cat.type]"
                                    size="sm"
                                >
                                    {{ EXPENSE_CATEGORY_TYPE_LABELS[cat.type] }}
                                </AppBadge>
                                <span
                                    v-if="!cat.is_active"
                                    class="text-[10px] text-on-surface-variant font-semibold uppercase tracking-wider"
                                >
                                    Inactiva
                                </span>
                            </div>
                        </div>

                        <!-- Row actions -->
                        <div class="flex items-center gap-1 shrink-0">
                            <button
                                type="button"
                                class="btn-icon"
                                :aria-label="`Editar categoría ${cat.name}`"
                                @click="startEdit(cat)"
                            >
                                <Pencil :size="14" />
                            </button>
                            <button
                                type="button"
                                class="btn-icon text-error hover:bg-error/10"
                                :aria-label="`Eliminar categoría ${cat.name}`"
                                @click="removeCategory(cat)"
                            >
                                <X :size="14" />
                            </button>
                        </div>
                    </div>

                    <!-- Edit mode (inline) -->
                    <div v-else class="flex flex-col gap-3 px-4 py-3">
                        <input
                            v-model="editName"
                            type="text"
                            class="w-full px-3 py-2 rounded-lg bg-surface-lowest dark:bg-surface-low
                                   text-on-surface text-sm
                                   focus:outline-none focus:ring-2 focus:ring-primary/30"
                            :aria-label="`Nombre de categoría ${cat.name}`"
                        />
                        <div class="flex items-center gap-2">
                            <select
                                v-model="editType"
                                class="flex-1 px-3 py-2 rounded-lg bg-surface-lowest dark:bg-surface-low
                                       text-on-surface text-sm
                                       focus:outline-none focus:ring-2 focus:ring-primary/30 appearance-none"
                                :aria-label="`Tipo de categoría ${cat.name}`"
                            >
                                <option
                                    v-for="type in ALL_EXPENSE_CATEGORY_TYPES"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ EXPENSE_CATEGORY_TYPE_LABELS[type] }}
                                </option>
                            </select>

                            <!-- Active toggle -->
                            <label class="flex items-center gap-1.5 text-xs text-on-surface-variant cursor-pointer select-none">
                                <input
                                    v-model="editActive"
                                    type="checkbox"
                                    class="rounded"
                                    :aria-label="`Categoría ${cat.name} activa`"
                                />
                                Activa
                            </label>
                        </div>

                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="btn-icon text-success hover:bg-success/10"
                                :disabled="isSavingEdit"
                                :aria-label="`Guardar cambios de categoría ${cat.name}`"
                                @click="saveEdit(cat.id)"
                            >
                                <Check :size="14" />
                            </button>
                            <button
                                type="button"
                                class="btn-icon"
                                :aria-label="`Cancelar edición de categoría ${cat.name}`"
                                @click="cancelEdit"
                            >
                                <X :size="14" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add new category -->
            <div class="flex flex-col gap-3">
                <h3 class="serif text-lg text-on-surface tracking-tighter">
                    Nueva categoría
                </h3>

                <div class="flex flex-col gap-2">
                    <input
                        v-model="newName"
                        type="text"
                        placeholder="Nombre de la categoría..."
                        class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                               placeholder:text-on-surface-variant/50
                               transition-colors duration-150
                               focus:outline-none focus:ring-2 focus:ring-primary/30
                               dark:bg-surface-mid dark:text-on-surface"
                        aria-label="Nombre de la nueva categoría"
                        @keydown="onAddKeydown"
                    />

                    <select
                        v-model="newType"
                        class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                               focus:outline-none focus:ring-2 focus:ring-primary/30
                               dark:bg-surface-mid dark:text-on-surface appearance-none"
                        aria-label="Tipo de la nueva categoría"
                    >
                        <option
                            v-for="type in ALL_EXPENSE_CATEGORY_TYPES"
                            :key="type"
                            :value="type"
                        >
                            {{ EXPENSE_CATEGORY_TYPE_LABELS[type] }}
                        </option>
                    </select>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full
                               bg-primary-container text-on-surface text-sm font-medium
                               hover:bg-primary/20 transition-colors
                               disabled:opacity-50 disabled:pointer-events-none"
                        :disabled="!newName.trim() || isAdding"
                        aria-label="Agregar categoría"
                        @click="addCategory"
                    >
                        <Plus :size="14" aria-hidden="true" />
                        Agregar categoría
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <template #footer>
            <div class="flex gap-3">
                <AppButton
                    variant="secondary"
                    class="flex-1"
                    @click="close"
                >
                    Cerrar
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
