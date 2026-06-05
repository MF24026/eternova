<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ShoppingCart, LogIn, Menu, X } from 'lucide-vue-next'

const mobileMenuOpen = ref(false)
</script>

<template>
    <div class="min-h-screen flex flex-col bg-surface text-on-surface">
        <!-- Top navbar -->
        <header class="sticky top-0 z-40 bg-surface-lowest/80 backdrop-blur-[24px]">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="h-16 flex items-center justify-between gap-4">
                    <!-- Brand -->
                    <RouterLink
                        to="/"
                        class="font-serif font-semibold text-primary tracking-tighter text-xl shrink-0"
                    >
                        Eternova
                    </RouterLink>

                    <!-- Desktop nav -->
                    <nav class="hidden md:flex items-center gap-6">
                        <RouterLink
                            to="/catalogo"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors"
                        >
                            Catalogo
                        </RouterLink>
                        <RouterLink
                            to="/novedades"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors"
                        >
                            Novedades
                        </RouterLink>
                    </nav>

                    <!-- Actions -->
                    <div class="flex items-center gap-3">
                        <!-- Cart -->
                        <RouterLink
                            to="/carrito"
                            class="btn-icon relative"
                            aria-label="Carrito de compras"
                        >
                            <ShoppingCart :size="20" />
                            <span
                                class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-primary text-on-primary text-xs flex items-center justify-center font-semibold"
                                aria-hidden="true"
                            >
                                0
                            </span>
                        </RouterLink>

                        <!-- Login link -->
                        <RouterLink
                            to="/login"
                            class="hidden sm:flex items-center gap-1.5 text-sm text-on-surface-variant hover:text-primary transition-colors"
                        >
                            <LogIn :size="16" />
                            Ingresar
                        </RouterLink>

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
                    class="md:hidden bg-surface-lowest border-t border-outline-variant"
                >
                    <nav class="px-4 py-3 flex flex-col gap-1">
                        <RouterLink
                            to="/catalogo"
                            class="py-2.5 text-sm text-on-surface hover:text-primary transition-colors"
                            @click="mobileMenuOpen = false"
                        >
                            Catalogo
                        </RouterLink>
                        <RouterLink
                            to="/novedades"
                            class="py-2.5 text-sm text-on-surface hover:text-primary transition-colors"
                            @click="mobileMenuOpen = false"
                        >
                            Novedades
                        </RouterLink>
                        <RouterLink
                            to="/login"
                            class="py-2.5 text-sm text-primary font-medium"
                            @click="mobileMenuOpen = false"
                        >
                            Ingresar
                        </RouterLink>
                    </nav>
                </div>
            </Transition>
        </header>

        <!-- Page content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="bg-surface-low mt-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <p class="font-serif text-lg text-primary tracking-tighter mb-3">Eternova</p>
                        <p class="text-sm text-on-surface-variant leading-relaxed">
                            Rosas eternas, recuerdos que perduran.
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant mb-3">
                            Tienda
                        </p>
                        <div class="flex flex-col gap-2">
                            <RouterLink to="/catalogo" class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                                Catalogo
                            </RouterLink>
                            <RouterLink to="/pedidos" class="text-sm text-on-surface-variant hover:text-primary transition-colors">
                                Mis pedidos
                            </RouterLink>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant mb-3">
                            Contacto
                        </p>
                        <p class="text-sm text-on-surface-variant">
                            Disponible por WhatsApp
                        </p>
                    </div>
                </div>
                <div class="mt-10 pt-6 border-t border-outline-variant text-center">
                    <p class="text-xs text-on-surface-variant">
                        &copy; 2026 Eternova. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
