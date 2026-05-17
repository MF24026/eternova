<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import BrandMark from '@/Components/BrandMark.vue';
import {
    Layout as LayoutIcon,
    CreditCard,
    ClipboardList,
    Calendar,
    Package,
    Layers,
    Filter,
    Receipt,
    FileText,
    Users,
    Settings,
    Sun,
    Moon,
    Menu,
    X,
    Bell,
    Search,
    ChevronLeft,
    ChevronRight,
} from 'lucide-vue-next';

defineProps({
    title: { type: String, default: '' },
    breadcrumb: { type: String, default: '' },
});

const { isDark, toggle: toggleDark } = useDarkMode();
const page = usePage();
const user = computed(() => page.props.auth?.user);

const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);

const items = [
    { id: 'dashboard', label: 'Panel', href: '/admin/dashboard', icon: LayoutIcon },
    { id: 'pos', label: 'Punto de venta', href: '/admin/pos', icon: CreditCard },
    { id: 'orders', label: 'Pedidos', href: '/admin/orders', icon: ClipboardList },
    { id: 'reservations', label: 'Reservas', href: '/admin/reservations', icon: Calendar },
    { id: 'inventory', label: 'Inventario', href: '/admin/inventory', icon: Package },
    { id: 'products', label: 'Productos', href: '/admin/products', icon: Layers },
    { id: 'categories', label: 'Categorias', href: '/admin/categories', icon: Filter },
    { id: 'expenses', label: 'Gastos', href: '/admin/expenses', icon: Receipt },
    { id: 'quotes', label: 'Cotizaciones', href: '/admin/quotations', icon: FileText },
    { id: 'customers', label: 'Clientes', href: '/admin/customers', icon: Users },
    { id: 'settings', label: 'Ajustes', href: '/admin/settings', icon: Settings },
];

const isActive = (href) => page.url.startsWith(href);
</script>

<template>
    <div class="admin-shell">
        <!-- Mobile backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-300"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="sidebarOpen"
                class="admin-backdrop"
                @click="sidebarOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'sidebar admin-sidebar',
                { collapsed: sidebarCollapsed, open: sidebarOpen },
            ]"
        >
            <div
                class="row"
                :style="{
                    gap: '10px',
                    padding: '0 8px 24px',
                    justifyContent: sidebarCollapsed ? 'center' : 'flex-start',
                }"
            >
                <Link href="/admin/dashboard" style="display: flex; align-items: center; gap: 10px">
                    <BrandMark
                        :size="36"
                        :show-wordmark="!sidebarCollapsed"
                        primary-text="Eternova"
                        :wordmark-size="18"
                    />
                </Link>
                <button
                    class="btn-icon admin-sidebar-close"
                    @click="sidebarOpen = false"
                >
                    <X :size="18"/>
                </button>
            </div>

            <nav class="stack scroll" style="gap: 4px; flex: 1">
                <Link
                    v-for="it in items"
                    :key="it.id"
                    :href="it.href"
                    :class="['sidebar-item', { active: isActive(it.href) }]"
                    :title="sidebarCollapsed ? it.label : undefined"
                    @click="sidebarOpen = false"
                >
                    <component :is="it.icon" :size="18"/>
                    <span class="label-txt">{{ it.label }}</span>
                </Link>
            </nav>

            <button
                class="sidebar-item admin-sidebar-collapse"
                style="margin-top: 8px; color: var(--on-surface-variant)"
                @click="sidebarCollapsed = !sidebarCollapsed"
            >
                <component :is="sidebarCollapsed ? ChevronRight : ChevronLeft" :size="18"/>
                <span class="label-txt">Colapsar</span>
            </button>
        </aside>

        <!-- Main content area -->
        <div class="admin-main">
            <header class="admin-topbar">
                <button
                    class="btn-icon admin-topbar-menu"
                    @click="sidebarOpen = true"
                >
                    <Menu :size="20"/>
                </button>
                <div>
                    <div v-if="breadcrumb" class="label" style="margin-bottom: 4px">{{ breadcrumb }}</div>
                    <h1 v-if="title" class="serif" style="margin: 0; font-size: 28px; line-height: 1">{{ title }}</h1>
                </div>
                <div class="grow"/>

                <div class="admin-topbar-search">
                    <input
                        class="field"
                        placeholder="Buscar pedidos, clientes..."
                        style="padding-left: 44px"
                    />
                    <Search
                        :size="18"
                        style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)"
                    />
                </div>

                <button class="btn-icon" aria-label="Modo" @click="toggleDark">
                    <component :is="isDark ? Sun : Moon" :size="20"/>
                </button>

                <button class="btn-icon" aria-label="Notificaciones" style="position: relative">
                    <Bell :size="20"/>
                    <span class="admin-topbar-dot"/>
                </button>

                <div class="admin-topbar-user">
                    <span class="admin-topbar-avatar">
                        {{ user?.name?.charAt(0)?.toUpperCase() ?? 'A' }}
                    </span>
                    <div style="display: flex; flex-direction: column; line-height: 1.1">
                        <span style="font-size: 13px; font-weight: 600">{{ user?.name ?? 'Atelier' }}</span>
                        <span style="font-size: 11px; color: var(--on-surface-variant)">
                            {{ user?.role ?? 'Admin' }}
                        </span>
                    </div>
                </div>
            </header>

            <main class="admin-content">
                <slot/>
            </main>
        </div>
    </div>
</template>

<style scoped>
.admin-shell {
    min-height: 100vh;
    display: flex;
    background: var(--surface);
}

.admin-sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 80;
    transform: translateX(-100%);
    transition: transform 0.3s ease, width 0.35s ease, padding 0.35s ease;
}

.admin-sidebar.open {
    transform: translateX(0);
}

.admin-sidebar-close {
    margin-left: auto;
}

.admin-sidebar-collapse {
    display: none;
}

.admin-backdrop {
    position: fixed;
    inset: 0;
    z-index: 70;
    background: rgba(61, 47, 50, 0.28);
}

.admin-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.admin-topbar {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    background: var(--surface);
}

.admin-topbar-menu {
    display: inline-flex;
}

.admin-topbar-search {
    display: none;
}

.admin-topbar-dot {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    border-radius: 99px;
    background: var(--primary);
}

.admin-topbar-user {
    display: none;
}

.admin-topbar-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--gradient-soft);
    display: grid;
    place-items: center;
    color: var(--on-primary);
    font-size: 12px;
    font-weight: 700;
}

.admin-content {
    padding: 20px;
    flex: 1;
}

@media (min-width: 1024px) {
    .admin-sidebar {
        position: sticky;
        height: 100vh;
        transform: translateX(0) !important;
    }

    .admin-sidebar-close,
    .admin-topbar-menu,
    .admin-backdrop {
        display: none;
    }

    .admin-sidebar-collapse {
        display: flex;
    }

    .admin-topbar {
        padding: 20px 32px;
    }

    .admin-topbar-search {
        display: block;
        position: relative;
        max-width: 320px;
        flex: 1;
    }

    .admin-topbar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 4px 12px 4px 4px;
        border-radius: 99px;
        background: var(--surface-low);
    }

    .admin-content {
        padding: 24px 32px;
    }
}
</style>
