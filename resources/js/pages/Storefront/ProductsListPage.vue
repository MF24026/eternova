<script setup lang="ts">
/**
 * S2-E3 — Public product catalog list.
 * Deep-link: ?category_slug= and ?search= are read on mount and pushed on filter change.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { Search, SlidersHorizontal, ArrowLeft, ArrowRight } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useHead } from '@/composables/useHead'
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import Surrogate from '@/components/base/Surrogate.vue'
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
    await Promise.all([store.fetchTenant(), store.fetchCategories()])

    useHead({
        title: `Productos — ${store.tenant?.business_name ?? 'Tienda'}`,
        description: store.tenant?.business_name
            ? `Catalogo de ${store.tenant.business_name}`
            : 'Catalogo de productos',
        url: window.location.href,
        type: 'website',
    })

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
})

// Tone cycles from product id for surrogate fallback
const TONES = ['rose', 'lilac', 'cream', 'sage'] as const
type SurrogateTone = typeof TONES[number]
function toneFromId(id: number): SurrogateTone {
    return TONES[id % TONES.length]
}
</script>

<template>
    <!-- Header section with tier background -->
    <section
        class="tier px-6 py-12 sm:px-10 lg:px-20"
    >
        <div class="label-gilt" style="margin-bottom: 12px">Catalogo</div>
        <h1 class="serif" style="font-size: 48px; margin: 0">
            {{ store.tenant?.business_name ? `Todo de ${store.tenant.business_name}` : 'Todos los productos' }}
        </h1>
    </section>

    <!-- Filters + grid content -->
    <div class="px-6 pb-14 sm:px-10 lg:px-20 lg:pb-24">

        <!-- Filters bar: search + sort + category chips -->
        <div style="padding: 24px 0 32px">
            <!-- Search + sort row -->
            <div class="flex gap-3 mb-5">
                <!-- Search -->
                <div class="relative flex-1" style="max-width: 480px">
                    <Search
                        :size="16"
                        class="absolute left-4 top-1/2 -translate-y-1/2 pointer-events-none"
                        style="color: var(--on-surface-variant)"
                    />
                    <input
                        v-model="searchQuery"
                        type="search"
                        placeholder="Buscar productos..."
                        class="field"
                        style="padding-left: 44px"
                        data-testid="search-input"
                        aria-label="Buscar productos"
                    />
                </div>

                <!-- Sort dropdown -->
                <div class="flex items-center gap-2 shrink-0">
                    <SlidersHorizontal :size="14" class="shrink-0" style="color: var(--on-surface-variant)" />
                    <select
                        v-model="sortOption"
                        class="field text-sm cursor-pointer"
                        style="background: var(--surface-high); padding: 10px 14px; width: auto"
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

            <!-- Category chips as bloom pills -->
            <div
                v-if="store.categories.length"
                class="flex flex-wrap gap-2"
                role="group"
                aria-label="Filtrar por categoria"
            >
                <button
                    type="button"
                    class="bloom transition-all duration-200"
                    :class="activeCategory === null ? 'bloom-primary' : 'bloom-soft'"
                    :aria-pressed="activeCategory === null"
                    data-testid="chip-all"
                    @click="selectCategory(null)"
                >
                    Todos
                </button>
                <button
                    v-for="cat in store.categories"
                    :key="cat.id"
                    type="button"
                    class="bloom transition-all duration-200"
                    :class="activeCategory === cat.slug ? 'bloom-primary' : 'bloom-soft'"
                    :aria-pressed="activeCategory === cat.slug"
                    :data-testid="`chip-${cat.slug}`"
                    @click="selectCategory(cat.slug)"
                >
                    {{ cat.name }}
                    <span v-if="cat.products_count > 0" class="opacity-60 text-xs font-normal">
                        {{ cat.products_count }}
                    </span>
                </button>
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

        <!-- Product grid — card style matches the curated grid on home -->
        <div
            v-else
            class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-5"
            data-testid="products-grid"
        >
            <RouterLink
                v-for="product in store.products"
                :key="product.id"
                :to="{ name: 'storefront.product', params: { slug: product.slug } }"
                class="group block text-left card-hover"
                style="transition: transform .35s ease"
                :data-testid="`product-card-${product.slug}`"
            >
                <div
                    class="relative overflow-hidden mb-3.5"
                    style="aspect-ratio: 1/1; border-radius: var(--r-xl)"
                >
                    <img
                        v-if="product.default_image_url"
                        :src="product.default_image_url"
                        :alt="product.name"
                        class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                        loading="lazy"
                    />
                    <Surrogate
                        v-else
                        :tone="toneFromId(product.id)"
                        :fill="true"
                    />

                    <!-- Featured badge -->
                    <span
                        v-if="product.is_featured"
                        class="absolute top-2 left-2 bloom bloom-primary text-xs"
                        aria-label="Producto destacado"
                    >
                        Destacado
                    </span>
                </div>
                <div class="label-gilt" style="margin-bottom: 4px">
                    {{ product.categories[0]?.name ?? 'Producto' }}
                </div>
                <div class="serif" style="font-size: 18px; margin-bottom: 6px">{{ product.name }}</div>
                <div style="font-size: 14px; font-weight: 600; color: var(--primary)">
                    {{ store.formatPrice(product.base_price_cents) }}
                </div>
            </RouterLink>
        </div>

        <!-- Pagination -->
        <!-- Pagination — tier background as separator (No-Line Rule) -->
        <div
            v-if="lastPage > 1"
            class="flex items-center justify-between mt-10"
            style="background: var(--surface-low); border-radius: var(--r-lg); padding: 14px 20px"
        >
            <p class="text-sm" style="color: var(--on-surface-variant)">
                Pagina {{ currentPage }} de {{ lastPage }}
                <span v-if="store.pagination.meta"> ({{ store.pagination.meta.total }} productos)</span>
            </p>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="btn btn-tertiary"
                    :disabled="currentPage <= 1"
                    :style="currentPage <= 1 ? 'opacity: .4; pointer-events: none' : ''"
                    @click="goToPage(currentPage - 1)"
                >
                    <ArrowLeft :size="14" /> Anterior
                </button>
                <button
                    type="button"
                    class="btn btn-tertiary"
                    :disabled="currentPage >= lastPage"
                    :style="currentPage >= lastPage ? 'opacity: .4; pointer-events: none' : ''"
                    @click="goToPage(currentPage + 1)"
                >
                    Siguiente <ArrowRight :size="14" />
                </button>
            </div>
        </div>
    </div>
</template>
