<script setup lang="ts">
import { ref, computed, onMounted, watch, watchEffect } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
    ShoppingBag,
    Minus,
    Plus,
    ChevronRight,
    ArrowLeft,
    Check,
    Truck,
    Gift,
    Sparkles,
} from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useCartStore } from '@/stores/cart'
import { useToast } from '@/composables/useToast'
import { useHead } from '@/composables/useHead'
import StorefrontVariantSelector from '@/components/composite/storefront/StorefrontVariantSelector.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import Surrogate from '@/components/base/Surrogate.vue'
import type { StorefrontVariant } from '@/types/domain/Storefront'

const route = useRoute()
const router = useRouter()
const store = useStorefrontStore()
const cart = useCartStore()
const toast = useToast()

const quantity = ref(1)
const resolvedVariant = ref<StorefrontVariant | null>(null)
const activeGalleryIndex = ref(0)
const addedFeedback = ref(false)

// ── Price / stock derived from resolved variant ───────────────────────────────

const displayPriceCents = computed((): number => {
    if (resolvedVariant.value?.price_cents !== null && resolvedVariant.value?.price_cents !== undefined) {
        return resolvedVariant.value.price_cents
    }
    return store.currentProduct?.base_price_cents ?? 0
})

const displayPrice = computed((): string => store.formatPrice(displayPriceCents.value))

const allVariantsOutOfStock = computed((): boolean => {
    const variants = store.currentProduct?.variants ?? []
    return variants.length > 0 && variants.every((v) => !v.in_stock)
})

const isOutOfStock = computed((): boolean => {
    if (allVariantsOutOfStock.value) return true
    if (resolvedVariant.value) return !resolvedVariant.value.in_stock
    return false
})

const isLowStock = computed((): boolean => {
    if (!resolvedVariant.value) return false
    return resolvedVariant.value.low_stock && resolvedVariant.value.in_stock
})

const hasVariants = computed((): boolean =>
    (store.currentProduct?.variants?.length ?? 0) > 0,
)

const needsVariantSelection = computed((): boolean =>
    hasVariants.value && !resolvedVariant.value && !allVariantsOutOfStock.value,
)

const addToCartDisabled = computed((): boolean =>
    isOutOfStock.value || needsVariantSelection.value,
)

const addToCartLabel = computed((): string => {
    if (isOutOfStock.value) return 'Agotado'
    if (needsVariantSelection.value) return 'Selecciona una opcion'
    return 'Agregar al carrito'
})

// ── Gallery helpers ───────────────────────────────────────────────────────────

const activeImageSrc = computed((): string | null => {
    const gallery = store.currentProduct?.gallery ?? []
    if (gallery.length > 0) return gallery[activeGalleryIndex.value]?.full ?? null
    return store.currentProduct?.default_image_url ?? null
})

// ── Quantity stepper ──────────────────────────────────────────────────────────

function decrementQty(): void {
    if (quantity.value > 1) quantity.value--
}

function incrementQty(): void {
    quantity.value++
}

// ── Add-to-cart ───────────────────────────────────────────────────────────────

function handleAddToCart(): void {
    if (!store.currentProduct) return
    if (addToCartDisabled.value) return

    const variantPayload = resolvedVariant.value ?? {
        id: store.currentProduct.id * -1,
        sku: '',
        price_cents: store.currentProduct.base_price_cents,
        options: {} as Record<string, string>,
        image_url: store.currentProduct.default_image_url,
        in_stock: true,
        low_stock: false,
    }

    cart.addItem({
        variant: variantPayload,
        product: store.currentProduct,
        qty: quantity.value,
    })

    toast.success('Agregado al carrito')
    addedFeedback.value = true
    setTimeout(() => { addedFeedback.value = false }, 1500)
}

// ── Breadcrumb ────────────────────────────────────────────────────────────────

const breadcrumbCategory = computed(() =>
    store.currentProduct?.categories?.[0] ?? null,
)

// ── Related products ──────────────────────────────────────────────────────────

const relatedProducts = computed(() => {
    const current = store.currentProduct
    if (!current || !breadcrumbCategory.value) return []
    return store.products
        .filter(
            (p) =>
                p.id !== current.id &&
                p.categories.some((c) => c.slug === breadcrumbCategory.value?.slug),
        )
        .slice(0, 3)
})

// ── Load product ──────────────────────────────────────────────────────────────

async function loadProduct(slug: string): Promise<void> {
    quantity.value = 1
    resolvedVariant.value = null
    activeGalleryIndex.value = 0

    await Promise.all([store.fetchTenant(), store.fetchProduct(slug)])

    if (!store.currentProduct) {
        void router.replace({ name: 'storefront.products' })
        return
    }

    // Load related products from the same category (best-effort, no spinner)
    if (breadcrumbCategory.value) {
        void store.fetchProducts({ category_slug: breadcrumbCategory.value.slug, per_page: 4 })
    }
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

watchEffect(() => {
    const product = store.currentProduct
    const tenant = store.tenant
    if (product === null) return
    useHead({
        title: `${product.name} — ${tenant?.business_name ?? 'Tienda'}`,
        description: product.description ?? `${product.name} disponible en nuestra tienda`,
        image: product.default_image_url ?? undefined,
        url: window.location.href,
        type: 'product',
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
    <!-- Loading state -->
    <div v-if="store.isLoading" class="flex justify-center py-20">
        <AppSpinner />
    </div>

    <template v-else-if="store.currentProduct">
        <!-- Breadcrumb + back -->
        <div
            class="flex items-center gap-4 flex-wrap text-xs px-6 pt-6 sm:px-10 lg:px-20"
            style="color: var(--on-surface-variant)"
            aria-label="Ruta de navegacion"
            data-testid="breadcrumb"
        >
            <RouterLink
                :to="{ name: 'storefront.home' }"
                class="btn btn-ghost inline-flex items-center gap-1.5"
                style="padding: 6px 12px; border-radius: 999px; font-size: 13px"
            >
                <ArrowLeft :size="14" /> Atras
            </RouterLink>
            <span>Casa</span>
            <ChevronRight :size="12" class="opacity-50 shrink-0" />
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
            <ChevronRight :size="12" class="opacity-50 shrink-0" />
            <span
                class="font-medium truncate max-w-[200px]"
                style="color: var(--on-surface)"
            >
                {{ store.currentProduct.name }}
            </span>
        </div>

        <!-- Product grid: gallery + info -->
        <section
            class="grid items-start grid-cols-1 md:grid-cols-2 gap-8 lg:gap-16 px-6 py-8 sm:px-10 lg:px-20 lg:pb-24"
        >
            <!-- ── Gallery ─────────────────────────────────────────────────── -->
            <div class="fade-in">
                <!-- Main image -->
                <div
                    class="relative overflow-hidden mb-4"
                    style="aspect-ratio: 4/5; border-radius: var(--r-xl)"
                >
                    <img
                        v-if="activeImageSrc"
                        :src="activeImageSrc"
                        :alt="store.currentProduct.name"
                        class="w-full h-full object-cover"
                        data-testid="product-main-image"
                    />
                    <Surrogate
                        v-else
                        :tone="toneFromId(store.currentProduct.id)"
                        :fill="true"
                    />

                    <!-- Featured badge -->
                    <div
                        v-if="store.currentProduct.is_featured"
                        class="glass absolute"
                        style="top: 20px; right: 20px; padding: 6px 12px; border-radius: 999px"
                    >
                        <span class="label-gilt" style="color: var(--primary)">Edicion limitada</span>
                    </div>
                </div>

                <!-- Thumbnail strip -->
                <div
                    v-if="store.currentProduct.gallery.length > 1"
                    class="grid gap-3"
                    style="grid-template-columns: repeat(3, 1fr)"
                >
                    <button
                        v-for="(image, i) in store.currentProduct.gallery"
                        :key="i"
                        type="button"
                        class="overflow-hidden transition-all duration-200 focus-visible:ring-2"
                        style="aspect-ratio: 1/1; border-radius: var(--r-lg)"
                        :style="activeGalleryIndex === i
                            ? 'box-shadow: 0 0 0 2px var(--primary)'
                            : 'box-shadow: none; opacity: .7'"
                        :aria-label="`Ver imagen ${i + 1}`"
                        :aria-pressed="activeGalleryIndex === i"
                        @click="activeGalleryIndex = i"
                    >
                        <img
                            :src="image.thumbnail"
                            :alt="`${store.currentProduct.name} — imagen ${i + 1}`"
                            class="w-full h-full object-cover"
                            loading="lazy"
                        />
                    </button>
                </div>
            </div>

            <!-- ── Product info ────────────────────────────────────────────── -->
            <div class="fade-in-delay-1 flex flex-col gap-5">
                <!-- Category + SKU label -->
                <div class="label-gilt">
                    {{ store.currentProduct.categories[0]?.name ?? 'Producto' }}
                    <template v-if="store.currentProduct.id">
                        · SKU-{{ store.currentProduct.id }}
                    </template>
                </div>

                <!-- Product name -->
                <h1
                    class="serif"
                    style="font-size: 52px; margin: 0; line-height: 1.02"
                >
                    {{ store.currentProduct.name }}
                </h1>

                <!-- Price + stock badge -->
                <div class="flex items-center gap-3 flex-wrap">
                    <span
                        class="serif"
                        style="font-size: 32px; color: var(--primary)"
                        data-testid="product-price"
                    >
                        {{ displayPrice }}
                    </span>
                    <span
                        v-if="!isOutOfStock && !isLowStock"
                        class="bloom bloom-success"
                        data-testid="in-stock-badge"
                    >
                        <Check :size="12" /> En existencia
                    </span>
                    <span
                        v-else-if="isLowStock"
                        class="bloom bloom-warning"
                        data-testid="low-stock-badge"
                    >
                        Quedan pocas
                    </span>
                    <span
                        v-else-if="isOutOfStock"
                        class="bloom bloom-error"
                        data-testid="out-of-stock-badge"
                    >
                        Agotado
                    </span>
                </div>

                <!-- Description -->
                <p
                    v-if="store.currentProduct.description"
                    class="leading-relaxed"
                    style="color: var(--on-surface-variant); font-size: 15px; line-height: 1.7"
                >
                    {{ store.currentProduct.description }}
                </p>

                <!-- Variant selector -->
                <div v-if="hasVariants" data-testid="variant-selector">
                    <StorefrontVariantSelector
                        :variants="store.currentProduct.variants ?? []"
                        @update:resolved="(v) => resolvedVariant = v"
                    />
                </div>

                <!-- Qty stepper + add-to-cart -->
                <div class="flex items-center gap-3 mt-2">
                    <!-- Stepper pill -->
                    <div
                        class="inline-flex items-center gap-1"
                        style="background: var(--surface-low); border-radius: 999px; padding: 4px"
                    >
                        <button
                            type="button"
                            class="btn-icon"
                            style="width: 36px; height: 36px"
                            aria-label="Reducir cantidad"
                            :disabled="quantity <= 1"
                            @click="decrementQty"
                        >
                            <Minus :size="16" />
                        </button>
                        <span
                            class="font-bold text-center select-none"
                            style="min-width: 32px; font-size: 15px"
                            data-testid="quantity-display"
                        >
                            {{ quantity }}
                        </span>
                        <button
                            type="button"
                            class="btn-icon"
                            style="width: 36px; height: 36px"
                            aria-label="Aumentar cantidad"
                            @click="incrementQty"
                        >
                            <Plus :size="16" />
                        </button>
                    </div>

                    <!-- Add to cart -->
                    <button
                        type="button"
                        class="btn btn-primary flex-1 justify-center"
                        :disabled="addToCartDisabled"
                        :aria-disabled="addToCartDisabled"
                        data-testid="add-to-cart-btn"
                        @click="handleAddToCart"
                    >
                        <component :is="addedFeedback ? Check : ShoppingBag" :size="16" />
                        {{ addedFeedback ? 'Anadido' : addToCartLabel }}
                    </button>
                </div>

                <!-- Detail card: shipping, packaging, handmade -->
                <div
                    class="mt-4"
                    style="background: var(--surface-low); border-radius: var(--r-xl); padding: 20px"
                >
                    <div class="label-gilt" style="margin-bottom: 12px">El detalle</div>
                    <ul
                        class="flex flex-col gap-2.5 text-sm"
                        style="list-style: none; padding: 0; margin: 0; color: var(--on-surface-variant)"
                    >
                        <li class="flex items-center gap-2.5">
                            <Truck :size="16" />
                            Envio express disponible
                        </li>
                        <li class="flex items-center gap-2.5">
                            <Gift :size="16" />
                            Empaque firma incluido sin costo
                        </li>
                        <li class="flex items-center gap-2.5">
                            <Sparkles :size="16" />
                            Hecho a mano · pieza unica
                        </li>
                    </ul>
                </div>

                <!-- Tags -->
                <div v-if="store.currentProduct.tags.length > 0" class="pt-1">
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
        </section>

        <!-- ── Related products ───────────────────────────────────────────── -->
        <section
            v-if="relatedProducts.length"
            class="px-6 pb-14 sm:px-10 lg:px-20 lg:pb-24"
        >
            <div class="label-gilt" style="margin-bottom: 12px">Tambien te puede gustar</div>
            <h3 class="serif" style="font-size: 36px; margin: 0 0 24px">Del mismo jardin.</h3>

            <div
                class="grid grid-cols-2 md:grid-cols-3 gap-5"
            >
                <RouterLink
                    v-for="product in relatedProducts"
                    :key="product.id"
                    :to="{ name: 'storefront.product', params: { slug: product.slug } }"
                    class="group block text-left card-hover"
                    style="transition: transform .35s ease"
                >
                    <div
                        class="relative overflow-hidden mb-3"
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
                    </div>
                    <div class="serif" style="font-size: 18px; margin-bottom: 4px">{{ product.name }}</div>
                    <div style="font-size: 14px; color: var(--primary); font-weight: 600">
                        {{ store.formatPrice(product.base_price_cents) }}
                    </div>
                </RouterLink>
            </div>
        </section>
    </template>

    <!-- Error / not found state -->
    <div
        v-else-if="store.error"
        class="text-center py-20 text-sm"
        style="color: var(--on-surface-variant)"
    >
        Producto no encontrado.
        <RouterLink
            :to="{ name: 'storefront.products' }"
            class="hover:underline ml-1"
            style="color: var(--primary)"
        >
            Volver al catalogo
        </RouterLink>
    </div>
</template>
