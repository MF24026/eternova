<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import {
    ShoppingBag,
    User,
    Sun,
    Moon,
    Menu,
    X,
} from 'lucide-vue-next';

const { isDark, toggle: toggleDark } = useDarkMode();
const page = usePage();
const mobileMenuOpen = ref(false);

const cartCount = computed(() => {
    return 0; // Will be connected to Pinia cart store
});

const navigation = [
    { name: 'Catalogo', href: '/catalog' },
    { name: 'Reservas', href: '/reservations/create' },
];
</script>

<template>
    <div class="min-h-screen flex flex-col bg-surface">
        <!-- Navbar -->
        <header class="sticky top-0 z-40 glass">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <!-- Brand -->
                    <Link href="/" class="flex items-center gap-2">
                        <span class="headline-serif text-lg font-semibold text-on-surface">
                            Carol Creaciones
                        </span>
                    </Link>

                    <!-- Desktop nav -->
                    <nav class="hidden md:flex items-center gap-8">
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            class="label-gilt text-xs font-medium text-on-surface-variant hover:text-primary transition-colors"
                        >
                            {{ item.name }}
                        </Link>
                    </nav>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button
                            class="flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors"
                            @click="toggleDark"
                        >
                            <Moon v-if="!isDark" class="h-5 w-5 text-on-surface-variant" />
                            <Sun v-else class="h-5 w-5 text-on-surface-variant" />
                        </button>

                        <Link
                            href="/cart"
                            class="relative flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors"
                        >
                            <ShoppingBag class="h-5 w-5 text-on-surface-variant" />
                            <span
                                v-if="cartCount > 0"
                                class="absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-on-primary"
                            >
                                {{ cartCount }}
                            </span>
                        </Link>

                        <Link
                            href="/login"
                            class="flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors"
                        >
                            <User class="h-5 w-5 text-on-surface-variant" />
                        </Link>

                        <!-- Mobile menu button -->
                        <button
                            class="flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors md:hidden"
                            @click="mobileMenuOpen = !mobileMenuOpen"
                        >
                            <X v-if="mobileMenuOpen" class="h-5 w-5 text-on-surface" />
                            <Menu v-else class="h-5 w-5 text-on-surface" />
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
                    <nav v-if="mobileMenuOpen" class="pb-4 md:hidden">
                        <div class="space-y-1">
                            <Link
                                v-for="item in navigation"
                                :key="item.name"
                                :href="item.href"
                                class="block rounded-xl px-4 py-2.5 text-sm font-medium text-on-surface-variant hover:bg-surface-container-high transition-colors"
                                @click="mobileMenuOpen = false"
                            >
                                {{ item.name }}
                            </Link>
                        </div>
                    </nav>
                </Transition>
            </div>
        </header>

        <!-- Page content -->
        <main class="flex-1">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="bg-surface-container-low">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12">
                <div class="text-center">
                    <p class="headline-serif text-lg font-semibold text-on-surface">
                        Carol Creaciones
                    </p>
                    <div class="mt-4 flex justify-center gap-6">
                        <a href="#" class="label-gilt text-[11px] text-on-surface-variant hover:text-primary transition-colors">
                            Privacidad
                        </a>
                        <a href="#" class="label-gilt text-[11px] text-on-surface-variant hover:text-primary transition-colors">
                            Terminos
                        </a>
                        <a href="#" class="label-gilt text-[11px] text-on-surface-variant hover:text-primary transition-colors">
                            Contacto
                        </a>
                    </div>
                    <p class="mt-6 text-xs text-on-surface-variant italic font-serif">
                        Handcrafted for Thoughtful Gifting.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
