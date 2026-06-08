<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { ShoppingCart, Menu, X } from 'lucide-vue-next'
import { useStorefrontStore } from '@/stores/storefront'
import { useStorefrontBranding } from '@/composables/useStorefrontBranding'
import { useCartStore } from '@/stores/cart'
import CartSlideover from '@/components/composite/storefront/CartSlideover.vue'

const store = useStorefrontStore()
const cart = useCartStore()
const mobileMenuOpen = ref(false)
const cartOpen = ref(false)

onMounted(async () => {
    await store.fetchTenant()
    useStorefrontBranding()
})
</script>

<template>
    <div class="min-h-screen flex flex-col bg-surface text-on-surface">
        <!-- Top navbar: glassmorphism, sticky -->
        <header class="sticky top-0 z-40 glass border-b" style="border-color: var(--outline-variant)">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="h-16 flex items-center justify-between gap-4">
                    <!-- Brand: logo image or business name text -->
                    <RouterLink
                        :to="{ name: 'storefront.home' }"
                        class="shrink-0 flex items-center gap-2"
                        :aria-label="store.tenant?.business_name ?? 'Inicio'"
                    >
                        <img
                            v-if="store.tenant?.logo_url"
                            :src="store.tenant.logo_url"
                            :alt="store.tenant.business_name"
                            class="h-8 w-auto object-contain"
                        />
                        <span
                            v-else
                            class="font-serif font-semibold tracking-tighter text-xl"
                            style="color: var(--brand-primary, var(--primary))"
                        >
                            {{ store.tenant?.business_name ?? 'Tienda' }}
                        </span>
                    </RouterLink>

                    <!-- Desktop nav -->
                    <nav class="hidden md:flex items-center gap-6">
                        <RouterLink
                            :to="{ name: 'storefront.home' }"
                            class="text-sm text-on-surface-variant hover:text-on-surface transition-colors"
                        >
                            Inicio
                        </RouterLink>
                        <RouterLink
                            :to="{ name: 'storefront.products' }"
                            class="text-sm text-on-surface-variant hover:text-on-surface transition-colors"
                        >
                            Catalogo
                        </RouterLink>
                    </nav>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <!-- Cart icon with item count badge -->
                        <button
                            type="button"
                            class="btn-icon relative"
                            aria-label="Carrito de compras"
                            data-testid="cart-icon-btn"
                            @click="cartOpen = true"
                        >
                            <ShoppingCart :size="20" />
                            <span
                                v-if="cart.count > 0"
                                class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full
                                       text-[10px] font-bold leading-[18px] text-center
                                       text-on-primary"
                                style="background: var(--primary)"
                                data-testid="cart-badge"
                                aria-label="`${cart.count} items en el carrito`"
                            >
                                {{ cart.count > 99 ? '99+' : cart.count }}
                            </span>
                        </button>

                        <!-- Mobile menu toggle -->
                        <button
                            type="button"
                            class="btn-icon md:hidden"
                            :aria-label="mobileMenuOpen ? 'Cerrar menu' : 'Abrir menu'"
                            @click="mobileMenuOpen = !mobileMenuOpen"
                        >
                            <component :is="mobileMenuOpen ? X : Menu" :size="20" />
                        </button>
                    </div>
                </div>
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
                <div
                    v-if="mobileMenuOpen"
                    class="md:hidden bg-surface-lowest"
                >
                    <nav class="px-4 py-3 flex flex-col gap-1">
                        <RouterLink
                            :to="{ name: 'storefront.home' }"
                            class="py-2.5 text-sm text-on-surface hover:text-primary transition-colors font-medium"
                            @click="mobileMenuOpen = false"
                        >
                            Inicio
                        </RouterLink>
                        <RouterLink
                            :to="{ name: 'storefront.products' }"
                            class="py-2.5 text-sm text-on-surface hover:text-primary transition-colors font-medium"
                            @click="mobileMenuOpen = false"
                        >
                            Catalogo
                        </RouterLink>
                    </nav>
                </div>
            </Transition>
        </header>

        <!-- Page content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Cart slideover — mounted at layout level so it persists across page navigations -->
        <CartSlideover v-model="cartOpen" />

        <!-- Footer -->
        <footer class="mt-16" style="background: var(--surface-low)">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
                    <div>
                        <p
                            class="font-serif text-lg tracking-tighter mb-1"
                            style="color: var(--brand-primary, var(--primary))"
                        >
                            {{ store.tenant?.business_name ?? 'Tienda' }}
                        </p>
                        <p
                            v-if="store.tenant?.tagline"
                            class="text-sm text-on-surface-variant leading-relaxed"
                        >
                            {{ store.tenant.tagline }}
                        </p>
                    </div>

                    <nav class="flex flex-col sm:items-end gap-2">
                        <RouterLink
                            :to="{ name: 'storefront.products' }"
                            class="text-sm text-on-surface-variant hover:text-on-surface transition-colors"
                        >
                            Catalogo
                        </RouterLink>
                        <a
                            v-if="store.tenant?.whatsapp_number"
                            :href="`https://wa.me/${store.tenant.whatsapp_number}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-sm text-on-surface-variant hover:text-on-surface transition-colors"
                        >
                            Contacto por WhatsApp
                        </a>
                    </nav>
                </div>

                <div class="mt-8 pt-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
                     style="border-top: 1px solid var(--outline-variant)">
                    <p class="text-xs text-on-surface-variant">
                        &copy; {{ new Date().getFullYear() }}
                        {{ store.tenant?.business_name ?? 'Tienda' }}. Todos los derechos reservados.
                    </p>
                    <!-- SaaS attribution — appears in small secondary text per brand guidelines -->
                    <p class="text-xs text-on-surface-variant opacity-60">
                        Con tecnologia de Eternova
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
