<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Plus, Search, Pencil, Trash2, RotateCcw, LayoutGrid, List } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import { useProductsStore } from '@/stores/products'
import { useCategoriesStore } from '@/stores/categories'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import type { Product } from '@/types/domain/Product'

onMounted(() => {
    document.title = 'Productos — Eternova'
    void loadProducts()
    void categoriesStore.fetchList({ is_active: true })
})

const router = useRouter()
const store = useProductsStore()
const categoriesStore = useCategoriesStore()
const toast = useToast()
const { confirm } = useConfirm()

// ── Filters ───────────────────────────────────────────────────────────────────
const searchQuery = ref('')
const activeFilter = ref<'active' | 'inactive' | 'all'>('active')
const selectedCategory = ref<number | null>(null)
const view = ref<'grid' | 'list'>('grid')

let searchDebounce: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchDebounce) clearTimeout(searchDebounce)
    searchDebounce = setTimeout(() => { void loadProducts() }, 350)
})

watch([activeFilter, selectedCategory], () => { void loadProducts() })

async function loadProducts(): Promise<void> {
    const filters: Parameters<typeof store.fetchList>[0] = {}

    if (searchQuery.value.trim() !== '') filters.search = searchQuery.value.trim()
    if (activeFilter.value !== 'all') filters.is_active = activeFilter.value === 'active'
    if (selectedCategory.value !== null) filters.category_id = selectedCategory.value

    await store.fetchList(filters)
}

// ── Navigation ─────────────────────────────────────────────────────────────────
function openCreate(): void {
    void router.push({ name: 'admin.products.create' })
}

function openEdit(product: Product): void {
    void router.push({ name: 'admin.products.edit', params: { id: product.id } })
}

// ── Delete / Restore ───────────────────────────────────────────────────────────
async function deleteProduct(product: Product): Promise<void> {
    const ok = await confirm({
        title: 'Archivar producto',
        message: `"${product.name}" se ocultara del catálogo. Podras restaurarlo después.`,
        confirmLabel: 'Archivar',
        variant: 'danger',
    })
    if (!ok) return

    try {
        await store.remove(product.id)
        toast.success('Producto archivado')
        void loadProducts()
    } catch {
        toast.error('No se pudo archivar el producto')
    }
}

async function restoreProduct(product: Product): Promise<void> {
    try {
        await store.restore(product.id)
        toast.success('Producto restaurado')
        void loadProducts()
    } catch {
        toast.error('No se pudo restaurar el producto')
    }
}

// ── Price display ──────────────────────────────────────────────────────────────
function formatPrice(cents: number): string {
    return '$' + (cents / 100).toFixed(2)
}

// ── Totals ─────────────────────────────────────────────────────────────────────
const totalProducts = computed(() => store.pagination.meta?.total ?? store.items.length)
</script>

<template>
    <div class="h-[calc(100vh-120px)] overflow-hidden">
        <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                    <p class="label-gilt">Catálogo</p>
                    <p class="serif text-2xl text-on-surface">{{ totalProducts }} productos</p>
                </div>
                <AppButton :icon="Plus" @click="openCreate">Nuevo producto</AppButton>
            </div>

            <!-- Filters toolbar -->
            <div class="flex flex-wrap items-center gap-2 mb-4">
                <div class="relative flex-1 min-w-40">
                    <AppInput
                        v-model="searchQuery"
                        placeholder="Buscar productos..."
                    >
                        <template #icon>
                            <Search :size="14" class="text-on-surface-variant" />
                        </template>
                    </AppInput>
                </div>

                <!-- Active filter chips -->
                <div class="flex gap-1">
                    <button
                        v-for="(label, key) in { active: 'Activos', inactive: 'Archivados', all: 'Todos' }"
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

                <!-- Category filter -->
                <select
                    v-model="selectedCategory"
                    class="text-sm rounded-xl px-3 py-2 focus:outline-none"
                    style="background: var(--surface-low); color: var(--on-surface)"
                >
                    <option :value="null">Todas las categorías</option>
                    <option
                        v-for="cat in categoriesStore.items"
                        :key="cat.id"
                        :value="cat.id"
                    >
                        {{ cat.name }}
                    </option>
                </select>

                <!-- View toggle -->
                <div class="tabs inline-flex">
                    <button
                        :class="['tab', { active: view === 'grid' }]"
                        aria-label="Vista cuadricula"
                        @click="view = 'grid'"
                    >
                        <LayoutGrid :size="14" />
                    </button>
                    <button
                        :class="['tab', { active: view === 'list' }]"
                        aria-label="Vista lista"
                        @click="view = 'list'"
                    >
                        <List :size="14" />
                    </button>
                </div>
            </div>

            <!-- Content -->
            <div v-if="store.isLoading" class="flex-1 flex items-center justify-center">
                <AppSpinner />
            </div>

            <AppEmptyState
                v-else-if="store.items.length === 0"
                title="Sin productos"
                description="Crea tu primer producto para empezar."
            />

            <div v-else class="scroll flex-1 min-h-0">
                <!-- Grid view -->
                <div v-if="view === 'grid'" class="products-grid">
                    <div
                        v-for="product in store.items"
                        :key="product.id"
                        class="rounded-xl overflow-hidden cursor-pointer"
                        :class="{ 'opacity-60': !product.is_active }"
                        style="background: var(--surface-low)"
                        @click="openEdit(product)"
                    >
                        <!-- Image placeholder -->
                        <div class="aspect-square" style="background: var(--gradient-soft)" />
                        <div class="p-3.5">
                            <p class="serif text-base text-on-surface mb-1 truncate">{{ product.name }}</p>
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-bold text-primary">
                                    {{ formatPrice(product.base_price_cents) }}
                                </span>
                                <span class="text-xs text-on-surface-variant">{{ product.sku_root ?? '' }}</span>
                            </div>
                            <!-- Badges -->
                            <div class="flex gap-1 mt-2 flex-wrap">
                                <span
                                    v-if="product.is_featured"
                                    class="text-xs px-2 py-0.5 rounded-full"
                                    style="background: var(--secondary-container); color: var(--on-secondary-container)"
                                >
                                    Destacado
                                </span>
                                <span
                                    v-if="!product.is_active"
                                    class="text-xs px-2 py-0.5 rounded-full"
                                    style="background: var(--error-container); color: var(--on-error-container)"
                                >
                                    Archivado
                                </span>
                            </div>
                        </div>
                        <!-- Row actions -->
                        <div class="flex gap-1 px-3 pb-3">
                            <button
                                class="btn-icon w-7 h-7"
                                aria-label="Editar"
                                @click.stop="openEdit(product)"
                            >
                                <Pencil :size="12" />
                            </button>
                            <button
                                v-if="product.deleted_at === null"
                                class="btn-icon w-7 h-7"
                                aria-label="Archivar"
                                @click.stop="deleteProduct(product)"
                            >
                                <Trash2 :size="12" />
                            </button>
                            <button
                                v-else
                                class="btn-icon w-7 h-7"
                                aria-label="Restaurar"
                                @click.stop="restoreProduct(product)"
                            >
                                <RotateCcw :size="12" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- List view -->
                <div v-else class="flex flex-col gap-1.5">
                    <div
                        v-for="product in store.items"
                        :key="product.id"
                        class="flex items-center gap-3 p-3.5 rounded-xl cursor-pointer"
                        :class="{ 'opacity-60': !product.is_active }"
                        style="background: var(--surface-low)"
                    >
                        <div class="w-11 h-11 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                        <div class="grow min-w-0">
                            <p class="serif text-base text-on-surface truncate">{{ product.name }}</p>
                            <p class="text-xs text-on-surface-variant">
                                {{ product.sku_root ?? '—' }}
                            </p>
                        </div>
                        <span class="text-sm font-bold text-primary shrink-0">
                            {{ formatPrice(product.base_price_cents) }}
                        </span>
                        <div class="flex gap-1 shrink-0">
                            <button
                                class="btn-icon w-7 h-7"
                                aria-label="Editar"
                                @click="openEdit(product)"
                            >
                                <Pencil :size="12" />
                            </button>
                            <button
                                v-if="product.deleted_at === null"
                                class="btn-icon w-7 h-7"
                                aria-label="Archivar"
                                @click="deleteProduct(product)"
                            >
                                <Trash2 :size="12" />
                            </button>
                            <button
                                v-else
                                class="btn-icon w-7 h-7"
                                aria-label="Restaurar"
                                @click="restoreProduct(product)"
                            >
                                <RotateCcw :size="12" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div
                v-if="store.pagination.meta && store.pagination.meta.last_page > 1"
                class="flex items-center justify-between mt-4 pt-4"
                style="border-top: 1px solid var(--outline-variant)"
            >
                <p class="text-sm text-on-surface-variant">
                    Página {{ store.pagination.meta.current_page }} de {{ store.pagination.meta.last_page }}
                    ({{ store.pagination.meta.total }} productos)
                </p>
                <div class="flex gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="store.pagination.links?.prev === null"
                        @click="store.fetchList({ page: (store.pagination.meta?.current_page ?? 1) - 1 })"
                    >
                        Anterior
                    </AppButton>
                    <AppButton
                        variant="secondary"
                        size="sm"
                        :disabled="store.pagination.links?.next === null"
                        @click="store.fetchList({ page: (store.pagination.meta?.current_page ?? 1) + 1 })"
                    >
                        Siguiente
                    </AppButton>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.products-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

@media (min-width: 1024px) {
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }
}

.scroll {
    overflow-y: auto;
    overflow-x: hidden;
}
</style>
