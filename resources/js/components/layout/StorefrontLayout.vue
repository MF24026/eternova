<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { ShoppingBag, Menu, X, Flower, MessageCircle, Phone, MapPin } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useStorefrontBranding } from '@/composables/useStorefrontBranding'
import { useCartStore } from '@/stores/cart'
import CartSlideover from '@/components/composite/storefront/CartSlideover.vue'
import Petal from '@/components/base/Petal.vue'

const store = useStorefrontStore()
const cart = useCartStore()
const mobileMenuOpen = ref(false)
const cartOpen = ref(false)

onMounted(async () => {
    await store.fetchTenant()
    useStorefrontBranding()
})

// Split business name into two words so we can accent the second in primary color,
// mirroring the "Carol Creaciones" prototype pattern.
function businessNameParts(name: string): [string, string] {
    const parts = name.trim().split(/\s+/)
    if (parts.length === 1) return [parts[0], '']
    const first = parts[0]
    const rest = parts.slice(1).join(' ')
    return [first, rest]
}
</script>

<template>
    <div class="min-h-screen flex flex-col overflow-x-clip" style="background: var(--surface); color: var(--on-surface)">

        <!-- Ambient floating petals (subtle, non-interactive) -->
        <div
            class="petal petal-anim pointer-events-none fixed"
            style="top: -60px; right: -80px; width: 360px; height: 360px; opacity: .12; z-index: 0"
            aria-hidden="true"
        >
            <Petal tone="lilac" :size="1" />
        </div>
        <div
            class="petal petal-anim pointer-events-none fixed"
            style="bottom: -100px; left: -80px; width: 280px; height: 280px; opacity: .08; z-index: 0; animation-delay: -7s"
            aria-hidden="true"
        >
            <Petal tone="rose" :size="1" />
        </div>

        <!-- Glass sticky nav -->
        <header
            class="glass sticky top-0 z-40 px-5 py-4 sm:px-10 lg:px-10"
        >
            <div class="flex items-center gap-6">

                <!-- Mobile menu toggle -->
                <button
                    type="button"
                    class="btn-icon md:hidden"
                    :aria-label="mobileMenuOpen ? 'Cerrar menu' : 'Abrir menu'"
                    @click="mobileMenuOpen = !mobileMenuOpen"
                >
                    <component :is="mobileMenuOpen ? X : Menu" :size="20" />
                </button>

                <!-- Brand logo / name -->
                <RouterLink
                    :to="{ name: 'storefront.home' }"
                    class="flex items-center gap-2.5 shrink-0"
                    :aria-label="store.tenant?.business_name ?? 'Inicio'"
                >
                    <img
                        v-if="store.tenant?.logo_url"
                        :src="store.tenant.logo_url"
                        :alt="store.tenant.business_name"
                        class="h-8 w-auto object-contain"
                    />
                    <template v-else>
                        <!-- Gradient circle with flower icon when no logo -->
                        <span
                            class="flex items-center justify-center shrink-0 rounded-full"
                            style="width: 32px; height: 32px; background: var(--gradient); color: var(--on-primary)"
                            aria-hidden="true"
                        >
                            <Flower :size="18" />
                        </span>
                        <span
                            class="serif"
                            style="font-size: 22px"
                        >
                            {{ businessNameParts(store.tenant?.business_name ?? 'Tienda')[0] }}
                            <span
                                v-if="businessNameParts(store.tenant?.business_name ?? '')[1]"
                                style="color: var(--primary)"
                            >{{ ' ' + businessNameParts(store.tenant?.business_name ?? '')[1] }}</span>
                        </span>
                    </template>
                </RouterLink>

                <!-- Desktop nav -->
                <nav class="hidden md:flex items-center gap-7 ml-6">
                    <RouterLink
                        :to="{ name: 'storefront.home' }"
                        class="text-sm font-medium transition-colors"
                        style="color: var(--on-surface-variant); letter-spacing: .02em"
                        active-class="!text-primary"
                    >
                        Casa
                    </RouterLink>
                    <RouterLink
                        :to="{ name: 'storefront.products' }"
                        class="text-sm font-medium transition-colors"
                        style="color: var(--on-surface-variant); letter-spacing: .02em"
                        active-class="!text-primary"
                    >
                        Catalogo
                    </RouterLink>
                    <template v-if="store.categories.length">
                        <RouterLink
                            v-for="cat in store.categories.slice(0, 3)"
                            :key="cat.slug"
                            :to="{ name: 'storefront.products', query: { category_slug: cat.slug } }"
                            class="text-sm font-medium transition-colors"
                            style="color: var(--on-surface-variant); letter-spacing: .02em"
                            active-class="!text-primary"
                        >
                            {{ cat.name }}
                        </RouterLink>
                    </template>
                    <a
                        v-if="store.tenant?.whatsapp_number"
                        :href="`https://wa.me/${store.tenant.whatsapp_number}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm font-medium transition-colors"
                        style="color: var(--on-surface-variant); letter-spacing: .02em"
                    >
                        Contacto
                    </a>
                </nav>

                <!-- Spacer -->
                <div class="grow" />

                <!-- Cart icon -->
                <button
                    type="button"
                    class="btn-icon relative"
                    aria-label="Carrito de compras"
                    data-testid="cart-icon-btn"
                    @click="cartOpen = true"
                >
                    <ShoppingBag :size="20" />
                    <span
                        v-if="cart.count > 0"
                        class="absolute top-0.5 right-0.5 min-w-[18px] h-[18px] px-1 rounded-full
                               text-[10px] font-bold leading-[18px] text-center"
                        style="background: var(--primary); color: var(--on-primary)"
                        data-testid="cart-badge"
                    >
                        {{ cart.count > 99 ? '99+' : cart.count }}
                    </span>
                </button>
            </div>

            <!-- Mobile nav dropdown -->
            <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <nav
                    v-if="mobileMenuOpen"
                    class="md:hidden pt-4 pb-2 flex flex-col gap-1"
                >
                    <RouterLink
                        :to="{ name: 'storefront.home' }"
                        class="py-2.5 text-sm font-medium transition-colors"
                        style="color: var(--on-surface-variant)"
                        @click="mobileMenuOpen = false"
                    >
                        Casa
                    </RouterLink>
                    <RouterLink
                        :to="{ name: 'storefront.products' }"
                        class="py-2.5 text-sm font-medium transition-colors"
                        style="color: var(--on-surface-variant)"
                        @click="mobileMenuOpen = false"
                    >
                        Catalogo
                    </RouterLink>
                    <RouterLink
                        v-for="cat in store.categories.slice(0, 4)"
                        :key="cat.slug"
                        :to="{ name: 'storefront.products', query: { category_slug: cat.slug } }"
                        class="py-2.5 text-sm font-medium transition-colors"
                        style="color: var(--on-surface-variant)"
                        @click="mobileMenuOpen = false"
                    >
                        {{ cat.name }}
                    </RouterLink>
                    <a
                        v-if="store.tenant?.whatsapp_number"
                        :href="`https://wa.me/${store.tenant.whatsapp_number}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="py-2.5 text-sm font-medium"
                        style="color: var(--on-surface-variant)"
                        @click="mobileMenuOpen = false"
                    >
                        Contacto
                    </a>
                </nav>
            </Transition>
        </header>

        <!-- Page content -->
        <main class="flex-1 relative z-10">
            <slot />
        </main>

        <!-- Cart slideover — layout-level so it persists across navigation -->
        <CartSlideover v-model="cartOpen" />

        <!-- Footer -->
        <footer class="px-6 py-10 sm:px-10 lg:px-20 lg:pt-16 lg:pb-8 mt-20" style="background: var(--surface-low)">
            <div
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10"
            >
                <!-- Brand column -->
                <div>
                    <div class="serif" style="font-size: 26px; margin-bottom: 12px">
                        {{ businessNameParts(store.tenant?.business_name ?? 'Tienda')[0] }}
                        <span
                            v-if="businessNameParts(store.tenant?.business_name ?? '')[1]"
                            style="color: var(--primary)"
                        > {{ businessNameParts(store.tenant?.business_name ?? '')[1] }}</span>
                    </div>
                    <p
                        v-if="store.tenant?.tagline || store.tenant?.description"
                        class="leading-relaxed text-sm"
                        style="color: var(--on-surface-variant); max-width: 320px"
                    >
                        {{ store.tenant?.tagline ?? store.tenant?.description }}
                    </p>
                    <p
                        v-else
                        class="leading-relaxed text-sm"
                        style="color: var(--on-surface-variant); max-width: 320px"
                    >
                        Catalogo de productos disponibles. Contactanos por WhatsApp para realizar tu pedido.
                    </p>
                    <div class="flex gap-2.5 mt-5">
                        <a
                            v-if="store.tenant?.whatsapp_number"
                            :href="`https://wa.me/${store.tenant.whatsapp_number}`"
                            class="btn-icon"
                            aria-label="WhatsApp"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <MessageCircle :size="18" />
                        </a>
                        <a
                            v-if="store.tenant?.whatsapp_number"
                            :href="`tel:+${store.tenant.whatsapp_number}`"
                            class="btn-icon"
                            aria-label="Telefono"
                        >
                            <Phone :size="18" />
                        </a>
                        <a href="#" class="btn-icon" aria-label="Ubicacion">
                            <MapPin :size="18" />
                        </a>
                    </div>
                </div>

                <!-- Tienda -->
                <div>
                    <div class="label-gilt" style="margin-bottom: 16px">Tienda</div>
                    <ul class="flex flex-col gap-2.5" style="list-style: none; padding: 0; margin: 0">
                        <li
                            v-for="cat in store.categories.slice(0, 4)"
                            :key="cat.slug"
                        >
                            <RouterLink
                                :to="{ name: 'storefront.products', query: { category_slug: cat.slug } }"
                                class="text-sm transition-colors hover:text-on-surface"
                                style="color: var(--on-surface-variant); text-decoration: none"
                            >
                                {{ cat.name }}
                            </RouterLink>
                        </li>
                        <li>
                            <RouterLink
                                :to="{ name: 'storefront.products' }"
                                class="text-sm transition-colors hover:text-on-surface"
                                style="color: var(--on-surface-variant); text-decoration: none"
                            >
                                Ver todo
                            </RouterLink>
                        </li>
                    </ul>
                </div>

                <!-- Ayuda -->
                <div>
                    <div class="label-gilt" style="margin-bottom: 16px">Ayuda</div>
                    <ul class="flex flex-col gap-2.5" style="list-style: none; padding: 0; margin: 0">
                        <li v-if="store.tenant?.whatsapp_number">
                            <a
                                :href="`https://wa.me/${store.tenant.whatsapp_number}`"
                                class="text-sm transition-colors hover:text-on-surface"
                                style="color: var(--on-surface-variant); text-decoration: none"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                WhatsApp
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Powered by -->
                <div>
                    <div class="label-gilt" style="margin-bottom: 16px">Plataforma</div>
                    <p class="text-xs" style="color: var(--on-surface-variant)">
                        Con tecnologia de Eternova
                    </p>
                </div>
            </div>

            <!-- Bottom bar — background shift, no 1px border -->
            <div
                class="mt-12 pt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                style="border-top: 1px solid var(--outline-variant)"
            >
                <p class="text-xs" style="color: var(--on-surface-variant)">
                    &copy; {{ new Date().getFullYear() }} {{ store.tenant?.business_name ?? 'Tienda' }}
                </p>
                <p class="text-xs opacity-60" style="color: var(--on-surface-variant)">
                    Powered by Eternova
                </p>
            </div>
        </footer>
    </div>
</template>
