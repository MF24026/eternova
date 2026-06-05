<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Menu, X, ArrowRight } from 'lucide-vue-next'

const mobileMenuOpen = ref(false)
</script>

<template>
    <div class="min-h-screen flex flex-col bg-surface text-on-surface">
        <!-- Marketing nav -->
        <header class="sticky top-0 z-40 bg-surface/80 backdrop-blur-[24px]">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="h-16 flex items-center justify-between gap-6">
                    <!-- Brand -->
                    <RouterLink
                        to="/"
                        class="font-serif font-semibold text-primary tracking-tighter text-xl shrink-0"
                    >
                        Eternova
                    </RouterLink>

                    <!-- Desktop nav links -->
                    <nav class="hidden md:flex items-center gap-8">
                        <RouterLink
                            to="/#funcionalidades"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors"
                        >
                            Funcionalidades
                        </RouterLink>
                        <RouterLink
                            to="/precios"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors"
                        >
                            Precios
                        </RouterLink>
                    </nav>

                    <!-- CTA actions -->
                    <div class="hidden md:flex items-center gap-3">
                        <RouterLink
                            to="/login"
                            class="text-sm text-on-surface-variant hover:text-primary transition-colors"
                        >
                            Iniciar sesion
                        </RouterLink>
                        <RouterLink
                            to="/signup"
                            class="flex items-center gap-1.5 text-sm px-5 py-2 rounded-full font-medium text-on-primary transition-all duration-200 hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                            style="background: var(--gradient)"
                        >
                            Empezar gratis
                            <ArrowRight :size="14" />
                        </RouterLink>
                    </div>

                    <!-- Mobile toggle -->
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

            <!-- Mobile nav -->
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
                    <nav class="px-4 py-4 flex flex-col gap-2">
                        <RouterLink
                            to="/#funcionalidades"
                            class="py-2.5 text-sm text-on-surface hover:text-primary transition-colors"
                            @click="mobileMenuOpen = false"
                        >
                            Funcionalidades
                        </RouterLink>
                        <RouterLink
                            to="/precios"
                            class="py-2.5 text-sm text-on-surface hover:text-primary transition-colors"
                            @click="mobileMenuOpen = false"
                        >
                            Precios
                        </RouterLink>
                        <RouterLink
                            to="/login"
                            class="py-2.5 text-sm text-on-surface-variant"
                            @click="mobileMenuOpen = false"
                        >
                            Iniciar sesion
                        </RouterLink>
                        <RouterLink
                            to="/signup"
                            class="mt-2 flex items-center justify-center gap-2 py-3 rounded-full text-sm font-medium text-on-primary"
                            style="background: var(--gradient)"
                            @click="mobileMenuOpen = false"
                        >
                            Empezar gratis
                            <ArrowRight :size="14" />
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
        <footer class="bg-surface-mid mt-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
                <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-1">
                        <p class="font-serif text-xl text-primary tracking-tighter mb-4">Eternova</p>
                        <p class="text-sm text-on-surface-variant leading-relaxed">
                            Gestion integral para negocios de regalos, flores y accesorios en Latinoamerica.
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant mb-4">Producto</p>
                        <div class="flex flex-col gap-2.5">
                            <RouterLink to="/#funcionalidades" class="text-sm text-on-surface-variant hover:text-primary transition-colors">Funcionalidades</RouterLink>
                            <RouterLink to="/precios" class="text-sm text-on-surface-variant hover:text-primary transition-colors">Precios</RouterLink>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant mb-4">Soporte</p>
                        <div class="flex flex-col gap-2.5">
                            <RouterLink to="/docs" class="text-sm text-on-surface-variant hover:text-primary transition-colors">Documentacion</RouterLink>
                            <RouterLink to="/contacto" class="text-sm text-on-surface-variant hover:text-primary transition-colors">Contacto</RouterLink>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant mb-4">Legal</p>
                        <div class="flex flex-col gap-2.5">
                            <RouterLink to="/privacidad" class="text-sm text-on-surface-variant hover:text-primary transition-colors">Privacidad</RouterLink>
                            <RouterLink to="/terminos" class="text-sm text-on-surface-variant hover:text-primary transition-colors">Terminos</RouterLink>
                        </div>
                    </div>
                </div>
                <div class="mt-12 pt-6 border-t border-outline-variant text-center">
                    <p class="text-xs text-on-surface-variant">
                        &copy; 2026 Eternova. Todos los derechos reservados.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
