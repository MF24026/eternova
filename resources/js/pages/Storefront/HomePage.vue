<script setup lang="ts">
import { ref, onMounted, watchEffect } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowRight } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useHead } from '@/composables/useHead'
import AppSpinner from '@/components/base/AppSpinner.vue'
import Petal from '@/components/base/Petal.vue'
import Surrogate from '@/components/base/Surrogate.vue'
import ProductImage from '@/components/base/ProductImage.vue'

const store = useStorefrontStore()

onMounted(async () => {
    await Promise.all([
        store.fetchTenant(),
        store.fetchFeatured(),
        store.fetchCategories(),
    ])
})

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

// Tone cycles from product id so surrogate fallbacks look varied.
const TONES = ['rose', 'lilac', 'cream', 'sage'] as const
type SurrogateTone = typeof TONES[number]

function toneFromId(id: number): SurrogateTone {
    return TONES[id % TONES.length]
}

// Active curated-grid tab (null = all)
const activeCategoryTab = ref<string | null>(null)
</script>

<template>
    <!-- ── Hero ────────────────────────────────────────────────────────────── -->
    <section
        class="relative overflow-hidden px-6 py-12 sm:px-10 sm:py-16 lg:px-20 lg:py-20"
        style="background: var(--gradient-bloom)"
    >
        <!-- Ambient petals (desktop only, hidden on tiny screens) -->
        <div
            class="petal petal-anim absolute hidden sm:block"
            style="top: -60px; right: -80px; width: 360px; height: 360px; opacity: .65"
            aria-hidden="true"
        >
            <Petal tone="lilac" :size="1" />
        </div>
        <div
            class="petal petal-anim absolute hidden sm:block"
            style="bottom: -100px; left: -80px; width: 280px; height: 280px; opacity: .45; animation-delay: -5s"
            aria-hidden="true"
        >
            <Petal tone="rose" :size="1" />
        </div>

        <!-- Two-column on desktop, stacked on mobile -->
        <div class="relative grid grid-cols-1 md:grid-cols-2 items-center gap-8 lg:gap-16"
             style="--hero-cols: 1.1fr 1fr"
        >
            <!-- Left: copy + CTAs -->
            <div class="fade-in">
                <div class="label-gilt" style="margin-bottom: 16px">
                    {{ store.tenant?.tagline ? store.tenant.tagline.split(' ').slice(0, 3).join(' ') : 'Coleccion' }}
                </div>
                <h1
                    class="serif"
                    style="font-size: clamp(40px, 6vw, 72px); line-height: .98; margin: 0 0 24px; font-weight: 400; letter-spacing: -.03em"
                >
                    <template v-if="store.tenant?.tagline">
                        {{ store.tenant.tagline }}<br />
                        <em style="font-style: italic; color: var(--primary)">para siempre.</em>
                    </template>
                    <template v-else>
                        Cada detalle<br />
                        es <em style="font-style: italic; color: var(--primary)">una historia</em><br />
                        que perdura.
                    </template>
                </h1>
                <p
                    class="leading-relaxed"
                    style="color: var(--on-surface-variant); font-size: 17px; max-width: 460px; margin-bottom: 32px"
                >
                    {{ store.tenant?.description ?? 'Articulos curados a mano para los momentos que merecen quedarse.' }}
                </p>
                <div class="flex gap-3 flex-wrap">
                    <RouterLink
                        :to="{ name: 'storefront.products' }"
                        class="btn btn-primary"
                    >
                        Explorar la coleccion
                        <ArrowRight :size="16" />
                    </RouterLink>
                    <RouterLink
                        v-if="store.categories.length"
                        :to="{ name: 'storefront.products', query: { category_slug: store.categories[0]?.slug } }"
                        class="btn btn-secondary"
                    >
                        Ver categorias
                    </RouterLink>
                </div>
            </div>

            <!-- Right: asymmetric 4-image mosaic (hidden on mobile to avoid overflow) -->
            <div
                class="fade-in-delay-1 relative hidden md:block"
                style="aspect-ratio: 4/5; display: grid; grid-template-columns: repeat(6,1fr); grid-template-rows: repeat(6,1fr); gap: 8px"
            >
                <!-- Top-left large (4/6 × 4/6) -->
                <div style="grid-column: 1/5; grid-row: 1/5">
                    <div class="relative w-full h-full overflow-hidden" style="border-radius: var(--r-xl)">
                        <img
                            v-if="store.featured[0]?.default_image_url"
                            :src="store.featured[0].default_image_url"
                            :alt="store.featured[0].name"
                            class="w-full h-full object-cover"
                        />
                        <Surrogate
                            v-else
                            kind="rose"
                            :tone="store.featured[0] ? toneFromId(store.featured[0].id) : 'rose'"
                            :fill="true"
                        />
                    </div>
                </div>
                <!-- Top-right narrow (2/6 × 3/6) -->
                <div style="grid-column: 5/7; grid-row: 2/5">
                    <div class="relative w-full h-full overflow-hidden" style="border-radius: var(--r-xl)">
                        <img
                            v-if="store.featured[1]?.default_image_url"
                            :src="store.featured[1].default_image_url"
                            :alt="store.featured[1].name"
                            class="w-full h-full object-cover"
                        />
                        <Surrogate v-else kind="peluche" tone="lilac" :fill="true" />
                    </div>
                </div>
                <!-- Bottom-center (3/6 × 2/6) -->
                <div style="grid-column: 3/6; grid-row: 5/7">
                    <div class="relative w-full h-full overflow-hidden" style="border-radius: var(--r-xl)">
                        <img
                            v-if="store.featured[2]?.default_image_url"
                            :src="store.featured[2].default_image_url"
                            :alt="store.featured[2].name"
                            class="w-full h-full object-cover"
                        />
                        <Surrogate v-else kind="bolso" tone="cream" :fill="true" />
                    </div>
                </div>
                <!-- Bottom-left (2/6 × 2/6) -->
                <div style="grid-column: 1/3; grid-row: 5/7">
                    <div class="relative w-full h-full overflow-hidden" style="border-radius: var(--r-xl)">
                        <img
                            v-if="store.featured[3]?.default_image_url"
                            :src="store.featured[3].default_image_url"
                            :alt="store.featured[3].name"
                            class="w-full h-full object-cover"
                        />
                        <Surrogate v-else kind="llavero" tone="rose" :fill="true" />
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ── Featured Categories ─────────────────────────────────────────────── -->
    <section
        v-if="store.categories.length"
        class="px-6 py-14 sm:px-10 sm:py-20 lg:px-20 lg:py-24"
    >
        <div class="flex items-baseline justify-between gap-4 mb-8 flex-wrap">
            <div>
                <div class="label-gilt" style="margin-bottom: 12px">Categorias</div>
                <h2 class="serif" style="font-size: clamp(28px, 4vw, 48px); margin: 0">
                    Cuatro maneras<br class="hidden sm:block" /> de hacer memoria.
                </h2>
            </div>
            <RouterLink :to="{ name: 'storefront.products' }" class="btn btn-secondary hidden md:inline-flex">
                Ver todo
            </RouterLink>
        </div>

        <!-- 2 cols on mobile, 4 on desktop -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <RouterLink
                v-for="(cat, i) in store.categories.slice(0, 4)"
                :key="cat.id"
                :to="{ name: 'storefront.products', query: { category_slug: cat.slug } }"
                class="card card-hover"
                style="padding: 0; overflow: hidden; text-align: left; aspect-ratio: 3/4; display: flex; flex-direction: column; background: var(--surface-lowest)"
            >
                <div class="relative flex-1">
                    <img
                        v-if="cat.image_url"
                        :src="cat.image_url"
                        :alt="cat.name"
                        class="absolute inset-0 w-full h-full object-cover"
                    />
                    <Surrogate
                        v-else
                        :kind="(['rose','peluche','bolso','llavero'] as const)[i % 4]"
                        :tone="(['rose','lilac','cream','sage'] as const)[i % 4]"
                        :fill="true"
                    />
                </div>
                <div style="padding: 20px">
                    <div class="label-gilt" style="margin-bottom: 6px">
                        <template v-if="cat.products_count > 0">{{ cat.products_count }} productos</template>
                        <template v-else>Coleccion</template>
                    </div>
                    <div class="serif" style="font-size: 20px">{{ cat.name }}</div>
                </div>
            </RouterLink>
        </div>
    </section>

    <!-- ── Curated grid (featured products) ───────────────────────────────── -->
    <section class="tier px-6 py-14 sm:px-10 sm:py-20 lg:px-20 lg:py-24">
        <div class="flex items-baseline justify-between gap-4 mb-8 flex-wrap">
            <div>
                <div class="label-gilt" style="margin-bottom: 12px">Curados a mano</div>
                <h2 class="serif" style="font-size: clamp(28px, 4vw, 48px); margin: 0">Lo que florece esta semana.</h2>
            </div>
            <div class="tabs">
                <button
                    class="tab"
                    :class="{ active: activeCategoryTab === null }"
                    @click="activeCategoryTab = null"
                >
                    Todo
                </button>
                <button
                    v-for="cat in store.categories.slice(0, 3)"
                    :key="cat.slug"
                    class="tab hidden sm:block"
                    :class="{ active: activeCategoryTab === cat.slug }"
                    @click="activeCategoryTab = cat.slug"
                >
                    {{ cat.name }}
                </button>
            </div>
        </div>

        <div v-if="store.isLoading" class="flex justify-center py-16">
            <AppSpinner />
        </div>

        <div
            v-else-if="store.featured.length"
            class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-5"
            data-testid="featured-products-grid"
        >
            <RouterLink
                v-for="product in store.featured
                    .filter(p => !activeCategoryTab || p.categories.some(c => c.slug === activeCategoryTab))
                    .slice(0, 8)"
                :key="product.id"
                :to="{ name: 'storefront.product', params: { slug: product.slug } }"
                class="group block text-left card-hover"
                style="transition: transform .35s ease"
            >
                <div
                    class="relative overflow-hidden mb-3"
                    style="aspect-ratio: 1/1; border-radius: var(--r-xl)"
                >
                    <ProductImage
                        :src="product.default_image_url"
                        :alt="product.name"
                        :seed="product.id"
                        img-class="transition-transform duration-500 group-hover:scale-105"
                    />
                </div>
                <div class="label-gilt" style="margin-bottom: 4px">
                    {{ product.categories[0]?.name ?? 'Producto' }}
                </div>
                <div class="serif" style="font-size: 17px; margin-bottom: 4px">{{ product.name }}</div>
                <div style="font-size: 14px; font-weight: 600; color: var(--primary)">
                    {{ store.formatPrice(product.base_price_cents) }}
                </div>
            </RouterLink>
        </div>

        <p v-else class="text-center py-12 text-sm" style="color: var(--on-surface-variant)">
            Pronto encontraras productos aqui.
        </p>
    </section>

    <!-- ── Story section ──────────────────────────────────────────────────── -->
    <section
        class="relative overflow-hidden px-6 py-14 sm:px-10 sm:py-20 lg:px-20 lg:py-32"
    >
        <!-- Ambient petal -->
        <div
            class="petal petal-anim absolute pointer-events-none hidden lg:block"
            style="right: -120px; top: -60px; width: 380px; height: 380px; opacity: .35"
            aria-hidden="true"
        >
            <Petal tone="rose" :size="1" />
        </div>

        <!-- One column on mobile, two on desktop -->
        <div class="grid grid-cols-1 md:grid-cols-2 items-center gap-10 lg:gap-20">
            <!-- Left: decorative gradient panel -->
            <div
                class="relative overflow-hidden"
                style="aspect-ratio: 4/5; border-radius: var(--r-2xl)"
            >
                <div class="absolute inset-0" style="background: var(--gradient-soft)" />
                <div class="absolute inset-0" style="opacity: .8">
                    <Petal tone="rose" :size="1" />
                </div>
                <div
                    class="glass absolute"
                    style="bottom: 24px; left: 24px; right: 24px; padding: 20px; border-radius: var(--r-lg)"
                >
                    <div class="label-gilt" style="margin-bottom: 6px">Atelier</div>
                    <div class="serif" style="font-size: 22px">
                        {{ store.tenant?.business_name ?? 'Nuestro atelier' }}
                    </div>
                </div>
            </div>

            <!-- Right: copy -->
            <div>
                <div class="label-gilt" style="margin-bottom: 16px">Nuestra historia</div>
                <h2
                    class="serif"
                    style="font-size: clamp(28px, 4vw, 56px); margin: 0 0 24px; line-height: 1.02"
                >
                    Hecho despacio,<br />con manos que recuerdan.
                </h2>
                <p
                    class="leading-relaxed"
                    style="color: var(--on-surface-variant); font-size: 16px; line-height: 1.7; margin-bottom: 24px; max-width: 540px"
                >
                    {{ store.tenant?.description
                        ?? 'Cada pieza es creada con atencion al detalle, pensada para perdurar. Catalogos curados para los momentos que merecen quedarse.' }}
                </p>
                <RouterLink
                    :to="{ name: 'storefront.products' }"
                    class="btn btn-tertiary inline-flex"
                >
                    Ver la coleccion completa
                    <ArrowRight :size="16" />
                </RouterLink>
            </div>
        </div>
    </section>
</template>
