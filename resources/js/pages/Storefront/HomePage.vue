<script setup lang="ts">
/**
 * S2-E2 — Storefront homepage for a tenant subdomain.
 *
 * Routing decision (see router/index.ts):
 *   The original `/` route pointed to a generic marketing/SaaS landing page.
 *   That page has been moved to `/welcome` (as a placeholder). The storefront
 *   homepage now owns `/` because on a TENANT subdomain the root is always the
 *   public catalog. When a dedicated SaaS marketing site is built (S2-E7 or
 *   later sprint) it will live at the main domain and can reclaim its own path.
 */
import { onMounted, watchEffect } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowRight } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useHead } from '@/composables/useHead'
import StorefrontProductCard from '@/components/composite/storefront/StorefrontProductCard.vue'
import StorefrontCategoryChip from '@/components/composite/storefront/StorefrontCategoryChip.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'

const store = useStorefrontStore()

onMounted(async () => {
    await Promise.all([
        store.fetchTenant(),
        store.fetchFeatured(),
        store.fetchCategories(),
    ])
})

// S2-E7: inject og:title, og:image, og:type for social link previews.
// Re-runs after fetchTenant resolves so the title reflects the real business name.
watchEffect(() => {
    const tenant = store.tenant

    useHead({
        title: tenant?.business_name
            ? (tenant.tagline ? `${tenant.business_name} — ${tenant.tagline}` : tenant.business_name)
            : 'Tienda',
        description: tenant?.description ?? (tenant?.business_name ? `Catalogo de ${tenant.business_name}` : undefined),
        image: tenant?.logo_url ?? undefined,
        url: window.location.href,
        type: 'website',
    })
})
</script>

<template>
    <!-- Hero section -->
    <section
        class="relative overflow-hidden px-4 sm:px-6 lg:px-8 py-20 sm:py-28"
        style="background: var(--gradient-bloom)"
    >
        <!-- Decorative bloom blobs -->
        <div
            class="pointer-events-none absolute -top-16 -right-16 w-64 h-64 rounded-full opacity-40 petal-anim"
            style="background: var(--primary-container)"
        />
        <div
            class="pointer-events-none absolute -bottom-12 -left-8 w-48 h-48 rounded-full opacity-30 petal-anim"
            style="background: var(--secondary-container); animation-delay: -4s"
        />

        <div class="relative max-w-3xl mx-auto text-center fade-in">
            <p class="label-gilt mb-4">Bienvenida</p>

            <h1 class="font-serif text-4xl sm:text-5xl lg:text-6xl font-semibold tracking-tighter text-on-surface mb-4">
                {{ store.tenant?.business_name ?? 'Nuestra Tienda' }}
            </h1>

            <p
                v-if="store.tenant?.tagline"
                class="text-lg sm:text-xl text-on-surface-variant leading-relaxed mb-8"
            >
                {{ store.tenant.tagline }}
            </p>

            <RouterLink
                :to="{ name: 'storefront.products' }"
                class="btn btn-primary inline-flex items-center gap-2 fade-in-delay-1"
            >
                Ver catalogo
                <ArrowRight :size="16" />
            </RouterLink>
        </div>
    </section>

    <!-- Categories section -->
    <section
        v-if="store.categories.length > 0"
        class="px-4 sm:px-6 lg:px-8 py-12"
        style="background: var(--surface-low)"
    >
        <div class="max-w-7xl mx-auto">
            <p class="label-gilt mb-6">Categorias</p>

            <div class="flex flex-wrap gap-2">
                <StorefrontCategoryChip
                    :category="null"
                    :active="false"
                    data-testid="category-chip-all"
                    @select="() => {}"
                />
                <StorefrontCategoryChip
                    v-for="cat in store.categories"
                    :key="cat.id"
                    :category="cat"
                    :active="false"
                    :data-testid="`category-chip-${cat.slug}`"
                    @select="(slug) => $router.push({ name: 'storefront.products', query: slug ? { category_slug: slug } : {} })"
                />
            </div>
        </div>
    </section>

    <!-- Featured products section -->
    <section class="px-4 sm:px-6 lg:px-8 py-12">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <p class="label-gilt mb-1">Destacados</p>
                    <h2 class="font-serif text-2xl font-semibold text-on-surface tracking-tighter">
                        Productos favoritos
                    </h2>
                </div>
                <RouterLink
                    :to="{ name: 'storefront.products' }"
                    class="btn-secondary text-sm hidden sm:flex items-center gap-1"
                >
                    Ver todos
                    <ArrowRight :size="14" />
                </RouterLink>
            </div>

            <div v-if="store.isLoading" class="flex justify-center py-16">
                <AppSpinner />
            </div>

            <div
                v-else-if="store.featured.length > 0"
                class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4"
                data-testid="featured-products-grid"
            >
                <StorefrontProductCard
                    v-for="product in store.featured"
                    :key="product.id"
                    :product="product"
                    :data-testid="`product-card-${product.slug}`"
                />
            </div>

            <p
                v-else
                class="text-center text-on-surface-variant py-12 text-sm"
            >
                Pronto encontraras productos aqui.
            </p>

            <!-- Mobile "ver todos" CTA -->
            <div class="mt-8 text-center sm:hidden">
                <RouterLink
                    :to="{ name: 'storefront.products' }"
                    class="btn btn-primary inline-flex items-center gap-2"
                >
                    Ver catalogo completo
                    <ArrowRight :size="16" />
                </RouterLink>
            </div>
        </div>
    </section>
</template>
