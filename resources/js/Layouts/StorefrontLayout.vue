<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import BrandMark from '@/Components/BrandMark.vue';
import {
    ShoppingBag,
    User,
    Sun,
    Moon,
    Menu,
    X,
    Search,
    MessageCircle,
    Phone,
    MapPin,
} from 'lucide-vue-next';

defineProps({
    cartCount: { type: Number, default: 0 },
});

defineEmits(['open-cart']);

const { isDark, toggle: toggleDark } = useDarkMode();
const page = usePage();
const mobileMenuOpen = ref(false);

const navigation = [
    { id: 'home', name: 'Casa', href: '/' },
    { id: 'rosas', name: 'Rosas Eternas', href: '/catalog/rosas' },
    { id: 'peluches', name: 'Peluches', href: '/catalog/peluches' },
    { id: 'carteras', name: 'Carteras', href: '/catalog/carteras' },
    { id: 'historia', name: 'Historia', href: '/historia' },
];

const footerColumns = [
    { title: 'Tienda', links: ['Rosas Eternas', 'Peluches', 'Carteras', 'Llaveros'] },
    { title: 'Atelier', links: ['Nuestra historia', 'Proceso', 'Cuidados', 'Contacto'] },
    { title: 'Ayuda', links: ['Envios', 'Devoluciones', 'WhatsApp', 'Seguimiento'] },
];

const isActive = (href) => {
    if (href === '/') return page.url === '/';
    return page.url.startsWith(href);
};
</script>

<template>
    <div style="min-height: 100vh; display: flex; flex-direction: column; background: var(--surface)">
        <!-- Glass navbar -->
        <header class="glass storefront-nav">
            <button
                class="btn-icon storefront-nav-menu"
                @click="mobileMenuOpen = !mobileMenuOpen"
            >
                <component :is="mobileMenuOpen ? X : Menu" :size="20"/>
            </button>

            <Link href="/" style="display: flex; align-items: center; gap: 10px; text-decoration: none">
                <BrandMark
                    :size="32"
                    primary-text="Carol"
                    secondary-text="Creaciones"
                    :wordmark-size="20"
                />
            </Link>

            <nav class="storefront-nav-links">
                <Link
                    v-for="l in navigation"
                    :key="l.id"
                    :href="l.href"
                    :class="['storefront-nav-link', { active: isActive(l.href) }]"
                >
                    {{ l.name }}
                </Link>
            </nav>

            <div class="grow"/>

            <button class="btn-icon storefront-nav-search" aria-label="Buscar">
                <Search :size="20"/>
            </button>

            <button class="btn-icon" aria-label="Modo" @click="toggleDark">
                <component :is="isDark ? Sun : Moon" :size="20"/>
            </button>

            <Link href="/login" class="btn-icon" aria-label="Cuenta">
                <User :size="20"/>
            </Link>

            <button
                class="btn-icon"
                aria-label="Carrito"
                style="position: relative"
                @click="$emit('open-cart')"
            >
                <ShoppingBag :size="20"/>
                <span v-if="cartCount > 0" class="storefront-nav-badge">{{ cartCount }}</span>
            </button>
        </header>

        <!-- Mobile menu drawer -->
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-2"
        >
            <nav v-if="mobileMenuOpen" class="storefront-mobile-menu">
                <Link
                    v-for="l in navigation"
                    :key="l.id"
                    :href="l.href"
                    class="storefront-mobile-link"
                    @click="mobileMenuOpen = false"
                >
                    {{ l.name }}
                </Link>
            </nav>
        </Transition>

        <!-- Page content -->
        <main style="flex: 1">
            <slot/>
        </main>

        <!-- Footer -->
        <footer class="storefront-footer">
            <div class="storefront-footer-grid">
                <div>
                    <div class="serif" style="font-size: 26px; margin-bottom: 12px">Carol Creaciones</div>
                    <p style="color: var(--on-surface-variant); line-height: 1.6; font-size: 14px; max-width: 320px">
                        Atelier de arreglos eternos, peluches y accesorios. Hecho a mano en San Salvador.
                    </p>
                    <div style="display: flex; gap: 10px; margin-top: 20px">
                        <a href="#" class="btn-icon"><MessageCircle :size="20"/></a>
                        <a href="#" class="btn-icon"><Phone :size="20"/></a>
                        <a href="#" class="btn-icon"><MapPin :size="20"/></a>
                    </div>
                </div>
                <div v-for="col in footerColumns" :key="col.title">
                    <div class="label-gilt" style="margin-bottom: 16px">{{ col.title }}</div>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px">
                        <li v-for="link in col.links" :key="link">
                            <a
                                href="#"
                                style="color: var(--on-surface-variant); font-size: 14px; text-decoration: none"
                            >
                                {{ link }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            <div
                style="
                    margin-top: 48px;
                    color: var(--on-surface-variant);
                    font-size: 12px;
                    text-align: center;
                "
            >
                © 2026 Carol Creaciones · San Salvador, El Salvador
            </div>
        </footer>
    </div>
</template>

<style scoped>
.storefront-nav {
    position: sticky;
    top: 0;
    z-index: 50;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}

.storefront-nav-menu {
    display: inline-flex;
}

.storefront-nav-links {
    display: none;
    gap: 28px;
    margin-left: 24px;
}

.storefront-nav-link {
    font-size: 13px;
    font-weight: 500;
    color: var(--on-surface-variant);
    letter-spacing: 0.02em;
    position: relative;
    padding-bottom: 4px;
    text-decoration: none;
    transition: color 0.2s ease;
}

.storefront-nav-link:hover,
.storefront-nav-link.active {
    color: var(--primary);
}

.storefront-nav-link.active::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 1px;
    background: var(--primary);
}

.storefront-nav-search {
    display: none;
}

.storefront-nav-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    background: var(--primary);
    color: var(--on-primary);
    border-radius: 99px;
    font-size: 10px;
    padding: 2px 6px;
    font-weight: 700;
    min-width: 18px;
    text-align: center;
}

.storefront-mobile-menu {
    padding: 16px 20px;
    background: var(--surface-low);
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.storefront-mobile-link {
    display: block;
    border-radius: var(--r-lg);
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 500;
    color: var(--on-surface-variant);
    text-decoration: none;
    transition: background 0.2s ease;
}

.storefront-mobile-link:hover {
    background: var(--surface-mid);
    color: var(--on-surface);
}

.storefront-footer {
    background: var(--surface-low);
    padding: 40px 24px 32px;
    margin-top: 80px;
}

.storefront-footer-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 40px;
    max-width: 1200px;
    margin: 0 auto;
}

@media (min-width: 768px) {
    .storefront-nav {
        padding: 20px 40px;
    }
    .storefront-nav-menu {
        display: none;
    }
    .storefront-nav-links {
        display: flex;
    }
    .storefront-nav-search {
        display: inline-flex;
    }
    .storefront-footer {
        padding: 64px 80px 32px;
    }
    .storefront-footer-grid {
        grid-template-columns: 1.5fr 1fr 1fr 1fr;
    }
}
</style>
