<script setup lang="ts">
/**
 * S2-E4 — Public product detail page.
 *
 * Cart placeholder (S2-E5):
 *   The "Agregar al carrito" button is wired to emit 'add-to-cart' with the
 *   resolved variant and quantity. ProductDetailPage shows a "Proximamente"
 *   toast for now. S2-E5 replaces this by connecting the cart Pinia store.
 *
 * SEO placeholder (S2-E7):
 *   document.title is set on mount. Full og: / meta tags require @vueuse/head
 *   or a head composable — left for S2-E7. A TODO marks the injection point.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { ShoppingCart, Minus, Plus, ChevronRight, AlertTriangle } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useToast } from '@/composables/useToast'
import StorefrontImageGallery from '@/components/composite/storefront/StorefrontImageGallery.vue'
import StorefrontVariantSelector from '@/components/composite/storefront/StorefrontVariantSelector.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import type { StorefrontVariant } from '@/types/domain/Storefront'

const route = useRoute()
const router = useRouter()
const store = useStorefrontStore()
const toast = useToast()

const quantity = ref(1)
const resolvedVariant = ref<StorefrontVariant | null>(null)

// ── Derived price and stock from the resolved variant ─────────────────────────
const displayPriceCents = computed((): number => {
    if (resolvedVariant.value?.price_cents !== null && resolvedVariant.value?.price_cents !== undefined) {
        return resolvedVariant.value.price_cents
    }
    return store.currentProduct?.base_price_cents ?? 0
})

const displayPrice = computed((): string => store.formatPrice(displayPriceCents.value))

const isOutOfStock = computed((): boolean => {
    if (!resolvedVariant.value) return false
    return !resolvedVariant.value.in_stock
})

const isLowStock = computed((): boolean => {
    if (!resolvedVariant.value) return false
    return resolvedVariant.value.low_stock && resolvedVariant.value.in_stock
})

const hasVariants = computed((): boolean =>
    (store.currentProduct?.variants?.length ?? 0) > 0,
)

// ── Quantity stepper ──────────────────────────────────────────────────────────
function decrementQty(): void {
    if (quantity.value > 1) quantity.value--
}

function incrementQty(): void {
    quantity.value++
}

// ── Add-to-cart ────────────────────────────────────────────────────────────────
// S2-E5 placeholder: shows a toast until the cart store is wired.
// When S2-E5 is implemented: call cartStore.addItem({ variant: resolvedVariant.value, qty: quantity.value })
function handleAddToCart(): void {
    // TODO(S2-E5): replace with cartStore.addItem({ variant: resolvedVariant.value, qty: quantity.value })
    toast.info('Carrito disponible proximamente')
}

// ── Breadcrumb ────────────────────────────────────────────────────────────────
const breadcrumbCategory = computed(() =>
    store.currentProduct?.categories?.[0] ?? null,
)

// ── Load product on mount and when slug changes ───────────────────────────────
async function loadProduct(slug: string): Promise<void> {
    await Promise.all([store.fetchTenant(), store.fetchProduct(slug)])

    if (!store.currentProduct) {
        void router.replace({ name: 'storefront.products' })
        return
    }

    // TODO(S2-E7): inject og:title / og:image via @vueuse/head
    document.title = `${store.currentProduct.name} — ${store.tenant?.business_name ?? 'Tienda'}`

    // Reset quantity when navigating to a different product.
    quantity.value = 1
    resolvedVariant.value = null
}

onMounted(() => {
    const slug = route.params['slug']
    if (typeof slug === 'string') void loadProduct(slug)
})

watch(
    () => route.params['slug'],
    (slug) => {
        if (typeof slug === 'string') void loadProduct(slug)
    },
)
</script>

<template>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Loading state -->
        <div v-if="store.isLoading" class="flex justify-center py-20">
            <AppSpinner />
        </div>

        <template v-else-if="store.currentProduct">
            <!-- Breadcrumb -->
            <nav
                class="flex items-center gap-1.5 text-xs text-on-surface-variant mb-6 flex-wrap"
                aria-label="Ruta de navegacion"
                data-testid="breadcrumb"
            >
                <RouterLink
                    :to="{ name: 'storefront.home' }"
                    class="hover:text-on-surface transition-colors"
                >
                    Inicio
                </RouterLink>
                <ChevronRight :size="12" class="shrink-0 opacity-50" />
                <RouterLink
                    v-if="breadcrumbCategory"
                    :to="{ name: 'storefront.products', query: { category_slug: breadcrumbCategory.slug } }"
                    class="hover:text-on-surface transition-colors"
                >
                    {{ breadcrumbCategory.name }}
                </RouterLink>
                <RouterLink
                    v-else
                    :to="{ name: 'storefront.products' }"
                    class="hover:text-on-surface transition-colors"
                >
                    Catalogo
                </RouterLink>
                <ChevronRight :size="12" class="shrink-0 opacity-50" />
                <span class="text-on-surface font-medium truncate max-w-[200px]">
                    {{ store.currentProduct.name }}
                </span>
            </nav>

            <!-- Two-column layout on desktop, stacked on mobile -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 lg:gap-12">
                <!-- Left: image gallery -->
                <div data-testid="image-gallery">
                    <StorefrontImageGallery
                        :gallery="store.currentProduct.gallery"
                        :default-image="store.currentProduct.default_image_url"
                        :product-name="store.currentProduct.name"
                    />
                </div>

                <!-- Right: product info -->
                <div class="space-y-5">
                    <!-- Category tags -->
                    <div
                        v-if="store.currentProduct.categories.length > 0"
                        class="flex flex-wrap gap-1.5"
                    >
                        <RouterLink
                            v-for="cat in store.currentProduct.categories"
                            :key="cat.slug"
                            :to="{ name: 'storefront.products', query: { category_slug: cat.slug } }"
                            class="bloom bloom-soft text-xs hover:text-on-surface transition-colors"
                        >
                            {{ cat.name }}
                        </RouterLink>
                    </div>

                    <!-- Product name -->
                    <h1 class="font-serif text-3xl sm:text-4xl font-semibold text-on-surface tracking-tighter leading-tight">
                        {{ store.currentProduct.name }}
                    </h1>

                    <!-- Price -->
                    <p
                        class="text-2xl font-bold"
                        style="color: var(--brand-primary, var(--primary))"
                        data-testid="product-price"
                    >
                        {{ displayPrice }}
                    </p>

                    <!-- Low-stock warning -->
                    <div
                        v-if="isLowStock"
                        class="bloom bloom-warning flex items-center gap-2"
                        data-testid="low-stock-badge"
                    >
                        <AlertTriangle :size="14" />
                        Quedan pocas unidades
                    </div>

                    <!-- Out-of-stock notice -->
                    <div
                        v-if="isOutOfStock"
                        class="bloom bloom-error flex items-center gap-2"
                        data-testid="out-of-stock-badge"
                    >
                        Agotado
                    </div>

                    <!-- Description -->
                    <p
                        v-if="store.currentProduct.description"
                        class="text-sm text-on-surface-variant leading-relaxed"
                    >
                        {{ store.currentProduct.description }}
                    </p>

                    <!-- Variant selector -->
                    <div
                        v-if="hasVariants"
                        data-testid="variant-selector"
                    >
                        <StorefrontVariantSelector
                            :variants="store.currentProduct.variants ?? []"
                            @update:resolved="(v) => resolvedVariant = v"
                        />
                    </div>

                    <!-- Quantity + add-to-cart -->
                    <div class="flex items-center gap-3 pt-2">
                        <!-- Quantity stepper -->
                        <div
                            class="flex items-center rounded-full overflow-hidden"
                            style="background: var(--surface-high)"
                        >
                            <button
                                type="button"
                                class="btn-icon w-10 h-10 rounded-full"
                                aria-label="Reducir cantidad"
                                :disabled="quantity <= 1"
                                @click="decrementQty"
                            >
                                <Minus :size="14" />
                            </button>
                            <span
                                class="w-8 text-center text-sm font-bold text-on-surface select-none"
                                data-testid="quantity-display"
                            >
                                {{ quantity }}
                            </span>
                            <button
                                type="button"
                                class="btn-icon w-10 h-10 rounded-full"
                                aria-label="Aumentar cantidad"
                                @click="incrementQty"
                            >
                                <Plus :size="14" />
                            </button>
                        </div>

                        <!-- Add-to-cart button -->
                        <button
                            type="button"
                            class="btn btn-primary flex-1 flex items-center justify-center gap-2"
                            :disabled="isOutOfStock"
                            :aria-disabled="isOutOfStock"
                            data-testid="add-to-cart-btn"
                            @click="handleAddToCart"
                        >
                            <ShoppingCart :size="16" />
                            {{ isOutOfStock ? 'Agotado' : 'Agregar al carrito' }}
                        </button>
                    </div>

                    <!-- Tags -->
                    <div
                        v-if="store.currentProduct.tags.length > 0"
                        class="pt-2"
                    >
                        <p class="label-gilt mb-2">Etiquetas</p>
                        <div class="flex flex-wrap gap-1.5">
                            <RouterLink
                                v-for="tag in store.currentProduct.tags"
                                :key="tag.slug"
                                :to="{ name: 'storefront.products', query: { tag_slug: tag.slug } }"
                                class="bloom bloom-soft text-xs hover:text-on-surface transition-colors"
                            >
                                {{ tag.name }}
                            </RouterLink>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Error / not found state -->
        <div
            v-else-if="store.error"
            class="text-center py-20 text-on-surface-variant text-sm"
        >
            Producto no encontrado.
            <RouterLink
                :to="{ name: 'storefront.products' }"
                class="text-primary hover:underline ml-1"
            >
                Volver al catalogo
            </RouterLink>
        </div>
    </div>
</template>
