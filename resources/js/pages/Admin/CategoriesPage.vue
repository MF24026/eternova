<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Plus, Pencil, ChevronDown, ChevronRight, RotateCcw, Trash2, GripVertical, Search } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import { useCategoriesStore } from '@/stores/categories'
import { useToast } from '@/composables/useToast'
import type { Category, CategoryInput, CategoryReorderItem } from '@/types/domain/Category'
import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'

onMounted(() => {
    document.title = 'Categorias — Eternova'
    void loadCategories()
})

const store = useCategoriesStore()
const toast = useToast()

// ── Filters ───────────────────────────────────────────────────────────────────
const searchQuery = ref('')
const activeFilter = ref<'active' | 'inactive' | 'all'>('active')

let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    searchDebounceTimer = setTimeout(() => { void loadCategories() }, 350)
})

watch(activeFilter, () => { void loadCategories() })

async function loadCategories(): Promise<void> {
    const filters: { search?: string; is_active?: boolean; include_children?: boolean } = {
        include_children: true,
    }
    if (searchQuery.value.trim() !== '') {
        filters.search = searchQuery.value.trim()
    }
    if (activeFilter.value !== 'all') {
        filters.is_active = activeFilter.value === 'active'
    }
    await store.fetchList(filters)
}

// ── Tree view ──────────────────────────────────────────────────────────────────
const expanded = ref<Set<number>>(new Set())
const selected = ref<Category | null>(null)

const rootCategories = computed<Category[]>(() =>
    store.items.filter((c) => c.parent_id === null)
)

function toggleExpand(id: number): void {
    if (expanded.value.has(id)) {
        expanded.value.delete(id)
    } else {
        expanded.value.add(id)
    }
}

function selectCategory(cat: Category): void {
    selected.value = cat
}

// ── Drag and drop sort ─────────────────────────────────────────────────────────
// Native HTML5 drag-and-drop. We only support reordering root categories here.
// Children can be reordered within their parent in a future iteration.
const draggingId = ref<number | null>(null)
const dragOverId = ref<number | null>(null)

function onDragStart(event: DragEvent, id: number): void {
    draggingId.value = id
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move'
    }
}

function onDragEnter(id: number): void {
    if (draggingId.value !== id) {
        dragOverId.value = id
    }
}

function onDragLeave(): void {
    dragOverId.value = null
}

function onDragEnd(): void {
    draggingId.value = null
    dragOverId.value = null
}

async function onDrop(targetId: number): Promise<void> {
    const fromId = draggingId.value
    dragOverId.value = null
    draggingId.value = null

    if (fromId === null || fromId === targetId) return

    // Compute new order by swapping sort_order values
    const roots = [...rootCategories.value]
    const fromIdx = roots.findIndex((c) => c.id === fromId)
    const toIdx = roots.findIndex((c) => c.id === targetId)

    if (fromIdx === -1 || toIdx === -1) return

    // Move the item from fromIdx to toIdx
    const reordered = [...roots]
    const [moved] = reordered.splice(fromIdx, 1)
    reordered.splice(toIdx, 0, moved)

    // Build reorder payload with new sort_order values based on position
    const items: CategoryReorderItem[] = reordered.map((cat, index) => ({
        id: cat.id,
        sort_order: index,
        parent_id: null,
    }))

    try {
        await store.reorder(items)
        toast.success('Orden guardado')
        void loadCategories()
    } catch {
        toast.error('No se pudo guardar el nuevo orden')
    }
}

// ── Slideover form ─────────────────────────────────────────────────────────────

// Local form shape with all string fields required (never undefined) for
// cleaner v-model bindings. Mapped to CategoryInput on submit.
interface CategoryForm {
    name: string
    slug: string
    description: string
    parent_id: number | null
    sort_order: number
    is_active: boolean
}

const slideoverOpen = ref(false)
const isEditing = ref(false)
const editingCategory = ref<Category | null>(null)

const form = ref<CategoryForm>({
    name: '',
    slug: '',
    description: '',
    parent_id: null,
    sort_order: 0,
    is_active: true,
})

const formErrors = ref<Record<string, string[]>>({})
const isSaving = ref(false)

function openCreate(): void {
    isEditing.value = false
    editingCategory.value = null
    form.value = { name: '', slug: '', description: '', parent_id: null, sort_order: 0, is_active: true }
    formErrors.value = {}
    slideoverOpen.value = true
}

function openEdit(cat: Category): void {
    isEditing.value = true
    editingCategory.value = cat
    form.value = {
        name: cat.name,
        slug: cat.slug,
        description: cat.description ?? '',
        parent_id: cat.parent_id,
        sort_order: cat.sort_order,
        is_active: cat.is_active,
    }
    formErrors.value = {}
    slideoverOpen.value = true
}

function fieldError(field: string): string {
    return formErrors.value[field]?.[0] ?? ''
}

async function submitForm(): Promise<void> {
    isSaving.value = true
    formErrors.value = {}

    const payload: CategoryInput = {
        name: form.value.name,
        description: form.value.description || null,
        parent_id: form.value.parent_id,
        sort_order: form.value.sort_order,
        is_active: form.value.is_active,
    }

    if (form.value.slug && form.value.slug.trim() !== '') {
        payload.slug = form.value.slug.trim()
    }

    try {
        if (isEditing.value && editingCategory.value) {
            await store.update(editingCategory.value.id, payload)
            toast.success('Categoria actualizada')
        } else {
            await store.create(payload)
            toast.success('Categoria creada')
        }
        slideoverOpen.value = false
        void loadCategories()
    } catch (err) {
        const axiosError = err as AxiosError<ApiErrorResponse>
        if (axiosError.response?.status === 422 && axiosError.response.data.errors) {
            formErrors.value = axiosError.response.data.errors as Record<string, string[]>
        } else {
            toast.error('Error al guardar la categoria')
        }
    } finally {
        isSaving.value = false
    }
}

// ── Delete / Restore ───────────────────────────────────────────────────────────
async function deleteCategory(cat: Category): Promise<void> {
    if (! confirm(`Archivar "${cat.name}"? Los subcategorias quedaran sin padre.`)) return

    try {
        await store.remove(cat.id)
        toast.success('Categoria archivada')
        if (selected.value?.id === cat.id) selected.value = null
        void loadCategories()
    } catch {
        toast.error('No se pudo archivar la categoria')
    }
}

async function restoreCategory(cat: Category): Promise<void> {
    try {
        await store.restore(cat.id)
        toast.success('Categoria restaurada')
        void loadCategories()
    } catch {
        toast.error('No se pudo restaurar la categoria')
    }
}

// ── Totals summary ─────────────────────────────────────────────────────────────
const totalCategories = computed(() => store.items.length)
const totalSubcategories = computed(() =>
    store.items.reduce((acc, c) => acc + (c.children?.length ?? 0), 0)
)
</script>

<template>
    <div class="cat-shell">
        <!-- Tree panel -->
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; overflow: hidden">
            <!-- Header -->
            <div class="flex justify-between items-start mb-4 gap-3 flex-wrap">
                <div>
                    <p class="label-gilt">Estructura</p>
                    <p class="serif text-2xl text-on-surface">
                        {{ totalCategories }} categorias &middot; {{ totalSubcategories }} subcategorias
                    </p>
                </div>
                <AppButton :icon="Plus" size="sm" @click="openCreate">Nueva</AppButton>
            </div>

            <!-- Search + filter chips -->
            <div class="flex items-center gap-2 mb-3 flex-wrap">
                <div class="relative flex-1 min-w-40">
                    <AppInput
                        v-model="searchQuery"
                        placeholder="Buscar categoria..."
                        class="pr-4"
                    >
                        <template #icon>
                            <Search :size="14" class="text-on-surface-variant" />
                        </template>
                    </AppInput>
                </div>
                <div class="flex gap-1">
                    <button
                        v-for="(label, key) in { active: 'Activas', inactive: 'Archivadas', all: 'Todas' }"
                        :key="key"
                        class="px-3 py-1 rounded-full text-xs font-medium transition-colors"
                        :class="activeFilter === key
                            ? 'bg-primary text-on-primary'
                            : 'text-on-surface-variant hover:text-on-surface'"
                        @click="activeFilter = key as 'active' | 'inactive' | 'all'"
                    >
                        {{ label }}
                    </button>
                </div>
            </div>

            <!-- Tree list -->
            <div v-if="store.isLoading" class="flex justify-center items-center py-10">
                <AppSpinner />
            </div>

            <AppEmptyState
                v-else-if="rootCategories.length === 0"
                title="Sin categorias"
                description="Crea tu primera categoria para organizar el catalogo."
            />

            <div v-else class="scroll flex flex-col gap-2 flex-1 min-h-0">
                <div
                    v-for="node in rootCategories"
                    :key="node.id"
                    :class="['rounded-xl', dragOverId === node.id ? 'ring-2 ring-primary/60' : '']"
                    draggable="true"
                    @dragstart="onDragStart($event, node.id)"
                    @dragenter.prevent="onDragEnter(node.id)"
                    @dragleave="onDragLeave"
                    @dragover.prevent
                    @dragend="onDragEnd"
                    @drop.prevent="onDrop(node.id)"
                >
                    <!-- Category row -->
                    <div
                        class="flex items-center gap-2 p-3 rounded-xl cursor-pointer transition-colors hover:opacity-90"
                        :class="selected?.id === node.id ? 'ring-2 ring-primary/40' : ''"
                        style="background: var(--surface-low)"
                        @click="() => { toggleExpand(node.id); selectCategory(node) }"
                    >
                        <!-- Drag handle -->
                        <GripVertical :size="14" class="text-on-surface-variant/50 shrink-0 cursor-grab" />

                        <!-- Color thumbnail placeholder -->
                        <div class="w-9 h-9 rounded-lg shrink-0" style="background: var(--gradient-soft)" />

                        <div class="grow min-w-0">
                            <p class="serif text-sm text-on-surface truncate">{{ node.name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ node.products_count }} productos</p>
                        </div>

                        <!-- Action buttons -->
                        <button
                            class="btn-icon w-7 h-7 shrink-0"
                            aria-label="Editar categoria"
                            @click.stop="openEdit(node)"
                        >
                            <Pencil :size="12" />
                        </button>

                        <button
                            v-if="node.deleted_at === null"
                            class="btn-icon w-7 h-7 shrink-0"
                            aria-label="Archivar categoria"
                            @click.stop="deleteCategory(node)"
                        >
                            <Trash2 :size="12" />
                        </button>

                        <button
                            v-else
                            class="btn-icon w-7 h-7 shrink-0"
                            aria-label="Restaurar categoria"
                            @click.stop="restoreCategory(node)"
                        >
                            <RotateCcw :size="12" />
                        </button>

                        <button
                            v-if="node.children && node.children.length > 0"
                            class="btn-icon w-7 h-7 shrink-0"
                            aria-label="Expandir"
                            @click.stop="toggleExpand(node.id)"
                        >
                            <component
                                :is="expanded.has(node.id) ? ChevronDown : ChevronRight"
                                :size="14"
                                class="transition-transform duration-200"
                            />
                        </button>
                    </div>

                    <!-- Subcategories -->
                    <Transition name="expand">
                        <div
                            v-if="expanded.has(node.id) && node.children && node.children.length > 0"
                            class="flex flex-col gap-1 py-2 pl-8"
                        >
                            <div
                                v-for="child in node.children"
                                :key="child.id"
                                class="flex items-center gap-2 px-3 py-2 rounded-xl"
                                style="background: var(--surface-lowest)"
                            >
                                <div class="grow text-sm text-on-surface truncate">{{ child.name }}</div>
                                <span class="text-xs text-on-surface-variant shrink-0">
                                    {{ child.products_count }} prod.
                                </span>
                                <button
                                    class="btn-icon w-6 h-6 shrink-0"
                                    aria-label="Editar subcategoria"
                                    @click="openEdit(child)"
                                >
                                    <Pencil :size="12" />
                                </button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </div>

        <!-- Preview / detail panel -->
        <div class="card" style="padding: 24px; background: var(--surface-low); display: flex; flex-direction: column">
            <p class="label-gilt mb-3">Vista previa</p>

            <template v-if="selected !== null">
                <div class="aspect-[4/3] rounded-xl overflow-hidden mb-4" style="background: var(--gradient-soft)" />
                <p class="serif text-2xl text-on-surface mb-1.5">{{ selected.name }}</p>
                <p class="text-sm text-on-surface-variant mb-4 leading-relaxed">
                    {{ selected.description ?? 'Sin descripcion.' }}
                </p>
                <div class="flex flex-col gap-2 text-sm mb-auto">
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Slug</span>
                        <code class="text-xs text-on-surface-variant">/{{ selected.slug }}</code>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Productos</span>
                        <span class="font-semibold">{{ selected.products_count }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Subcategorias</span>
                        <span class="font-semibold">{{ selected.children?.length ?? 0 }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-on-surface-variant">Estado</span>
                        <span
                            class="text-xs font-medium px-2 py-0.5 rounded-full"
                            :class="selected.is_active
                                ? 'bg-success/15 text-success'
                                : 'bg-on-surface-variant/15 text-on-surface-variant'"
                        >
                            {{ selected.is_active ? 'Activa' : 'Archivada' }}
                        </span>
                    </div>
                </div>
                <AppButton :icon="Pencil" class="w-full justify-center mt-5" @click="openEdit(selected)">
                    Editar categoria
                </AppButton>
            </template>

            <div v-else class="flex-1 flex items-center justify-center">
                <p class="text-sm text-on-surface-variant text-center">
                    Selecciona una categoria para ver detalles.
                </p>
            </div>
        </div>
    </div>

    <!-- Create / Edit Slideover -->
    <AppSlideover
        v-model="slideoverOpen"
        :title="isEditing ? 'Editar categoria' : 'Nueva categoria'"
        subtitle="Organiza tu catalogo con categorias jerarquicas"
    >
        <form class="flex flex-col gap-4 p-6" @submit.prevent="submitForm">
            <AppInput
                v-model="form.name"
                label="Nombre"
                placeholder="ej. Rosas Eternas"
                :error="fieldError('name')"
                required
            />

            <AppInput
                v-model="form.slug"
                label="Slug (opcional)"
                placeholder="se genera automaticamente"
                :error="fieldError('slug')"
                help-text="Solo letras minusculas, numeros y guiones"
            />

            <div class="field">
                <label class="label-gilt mb-1 block">Descripcion</label>
                <textarea
                    v-model="form.description"
                    class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm resize-none
                           focus:outline-none focus:ring-2 focus:ring-primary/30"
                    rows="3"
                    placeholder="Descripcion opcional del catalogo..."
                />
                <p v-if="fieldError('description')" class="text-xs text-error mt-1">
                    {{ fieldError('description') }}
                </p>
            </div>

            <AppInput
                :model-value="String(form.sort_order ?? 0)"
                label="Orden"
                type="number"
                placeholder="0"
                :error="fieldError('sort_order')"
                @update:model-value="(v) => { form.sort_order = Number(v) }"
            />

            <div class="flex items-center justify-between p-3 rounded-xl" style="background: var(--surface-lowest)">
                <span class="text-sm text-on-surface">Activa en catalogo</span>
                <button
                    type="button"
                    :class="[
                        'w-10 h-6 rounded-full transition-colors duration-200 relative',
                        form.is_active ? 'bg-primary' : 'bg-on-surface-variant/30'
                    ]"
                    :aria-checked="form.is_active"
                    role="switch"
                    @click="form.is_active = !form.is_active"
                >
                    <span
                        :class="[
                            'absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200',
                            form.is_active ? 'translate-x-5' : 'translate-x-1'
                        ]"
                    />
                </button>
            </div>

            <p v-if="fieldError('parent_id')" class="text-xs text-error">
                {{ fieldError('parent_id') }}
            </p>

            <!-- Actions -->
            <div class="flex gap-2 mt-2">
                <AppButton
                    type="submit"
                    class="flex-1 justify-center"
                    :disabled="isSaving"
                >
                    {{ isSaving ? 'Guardando...' : (isEditing ? 'Guardar cambios' : 'Crear categoria') }}
                </AppButton>
                <AppButton
                    type="button"
                    variant="secondary"
                    @click="slideoverOpen = false"
                >
                    Cancelar
                </AppButton>
            </div>
        </form>
    </AppSlideover>
</template>

<style scoped>
.cat-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}

.expand-enter-active,
.expand-leave-active {
    transition: opacity 0.2s ease, max-height 0.2s ease;
    max-height: 400px;
    overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
    opacity: 0;
    max-height: 0;
}

.scroll {
    overflow-y: auto;
    overflow-x: hidden;
}

@media (min-width: 1024px) {
    .cat-shell {
        grid-template-columns: 1.4fr 1fr;
    }
}
</style>
