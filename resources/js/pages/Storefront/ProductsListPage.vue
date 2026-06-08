<script setup lang="ts">
/**
 * S2-E3 — Public product catalog list.
 * Deep-link: ?category_slug= and ?search= are read on mount and pushed on filter change.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Search, SlidersHorizontal } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import StorefrontProductCard from '@/components/composite/storefront/StorefrontProductCard.vue'
import StorefrontCategoryChip from '@/components/composite/storefront/StorefrontCategoryChip.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import AppButton from '@/components/base/AppButton.vue'
import type { StorefrontSortOption } from '@/types/domain/Storefront'

const router = useRouter()
const route = useRoute()
const store = useStorefrontStore()

// ── Filter state (synced with URL query params) ────────────────────────────────
const searchQuery = ref<string>('')
const activeCategory = ref<string | null>(null)
const sortOption = ref<StorefrontSortOption>('featured')

const SORT_LABELS: Record<StorefrontSortOption, string> = {
    featured: 'Destacados',
    price_asc: 'Menor precio',
    price_desc: 'Mayor precio',
    newest: 'Mas nuevos',
}

// ── Debounced search ───────────────────────────────────────────────────────────
let searchDebounce: ReturnType<typeof setTimeout> | null = null

watch(searchQuery, () => {
    if (searchDebounce) clearTimeout(searchDebounce)
    searchDebounce = setTimeout(() => { void applyFilters() }, 300)
})

watch([activeCategory, sortOption], () => { void applyFilters() })

// ── Sync URL on filter change ──────────────────────────────────────────────────
async function applyFilters(page = 1): Promise<void> {
    const query: Record<string, string> = {}

    if (activeCategory.value) query['category_slug'] = activeCategory.value
    if (searchQuery.value.trim()) query['search'] = searchQuery.value.trim()
    if (sortOption.value !== 'featured') query['sort'] = sortOption.value
    if (page > 1) query['page'] = String(page)

    await router.replace({ name: 'storefront.products', query })

    await store.fetchProducts({
        category_slug: activeCategory.value ?? undefined,
        search: searchQuery.value.trim() || undefined,
        sort: sortOption.value,
        page,
    })
}

function selectCategory(slug: string | null): void {
    activeCategory.value = slug
}

// ── Pagination ─────────────────────────────────────────────────────────────────
const currentPage = computed(() => store.pagination.meta?.current_page ?? 1)
const lastPage = computed(() => store.pagination.meta?.last_page ?? 1)

function goToPage(page: number): void {
    void applyFilters(page)
}

// ── Mount: hydrate from URL params ────────────────────────────────────────────
onMounted(async () => {
    document.title = 'Catalogo'

    await Promise.all([store.fetchTenant(), store.fetchCategories()])

    // Read initial filter state from the URL so direct links and back-navigation work.
    const query = route.query
    if (typeof query['category_slug'] === 'string') activeCategory.value = query['category_slug']
    if (typeof query['search'] === 'string') searchQuery.value = query['search']
    if (typeof query['sort'] === 'string') sortOption.value = query['sort'] as StorefrontSortOption

    const initialPage = typeof query['page'] === 'string' ? Number(query['page']) : 1

    await store.fetchProducts({
        category_slug: activeCategory.value ?? undefined,
        search: searchQuery.value || undefined,
        sort: sortOption.value,
        page: initialPage,
    })

    document.title = `Catalogo — ${store.tenant?.business_name ?? 'Tienda'}`
})
</script>

<template>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
        <!-- Page header -->
        <div>
            <p class="label-gilt mb-1">Catalogo</p>
            <h1 class="font-serif text-3xl font-semibold text-on-surface tracking-tighter">
                Todos los productos
            </h1>
        </div>

        <!-- Filters bar -->
        <div
            class="rounded-xl p-4 space-y-3"
            style="background: var(--surface-low)"
        >
            <!-- Search + sort row -->
            <div class="flex flex-col sm:flex-row gap-3">
                <!-- Search input -->
                <div class="relative flex-1">
                    <Search
                        :size="16"
                        class="absolute left-3.5 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                    />
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Buscar productos..."
                        class="field pl-10 pr-4 text-sm"
                        data-testid="search-input"
                        aria-label="Buscar productos"
                    />
                </div>

                <!-- Sort dropdown -->
                <div class="flex items-center gap-2 shrink-0">
                    <SlidersHorizontal :size="14" class="text-on-surface-variant shrink-0" />
                    <select
                        v-model="sortOption"
                        class="field text-sm py-2.5 pr-8 cursor-pointer"
                        style="background: var(--surface-highest)"
                        aria-label="Ordenar productos"
                        data-testid="sort-select"
                    >
                        <option
                            v-for="(label, value) in SORT_LABELS"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Category chips -->
            <div
                v-if="store.categories.length > 0"
                class="flex flex-wrap gap-2"
                role="group"
                aria-label="Filtrar por categoria"
            >
                <StorefrontCategoryChip
                    :category="null"
                    :active="activeCategory === null"
                    data-testid="chip-all"
                    @select="selectCategory(null)"
                />
                <StorefrontCategoryChip
                    v-for="cat in store.categories"
                    :key="cat.id"
                    :category="cat"
                    :active="activeCategory === cat.slug"
                    :data-testid="`chip-${cat.slug}`"
                    @select="selectCategory"
                />
            </div>
        </div>

        <!-- Loading state -->
        <div v-if="store.isLoading" class="flex justify-center py-20">
            <AppSpinner />
        </div>

        <!-- Empty state -->
        <AppEmptyState
            v-else-if="store.products.length === 0"
            title="Sin resultados"
            description="Intenta con otro termino de busqueda o una categoria diferente."
        />

        <!-- Product grid -->
        <div
            v-else
            class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
            data-testid="products-grid"
        >
            <StorefrontProductCard
                v-for="product in store.products"
                :key="product.id"
                :product="product"
                :data-testid="`product-card-${product.slug}`"
            />
        </div>

        <!-- Pagination -->
        <div
            v-if="lastPage > 1"
            class="flex items-center justify-between pt-4"
        >
            <p class="text-sm text-on-surface-variant">
                Pagina {{ currentPage }} de {{ lastPage }}
                <span v-if="store.pagination.meta">
                    ({{ store.pagination.meta.total }} productos)
                </span>
            </p>

            <div class="flex gap-2">
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="currentPage <= 1"
                    @click="goToPage(currentPage - 1)"
                >
                    Anterior
                </AppButton>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="currentPage >= lastPage"
                    @click="goToPage(currentPage + 1)"
                >
                    Siguiente
                </AppButton>
            </div>
        </div>
    </div>
</template>
