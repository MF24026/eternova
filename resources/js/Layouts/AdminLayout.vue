<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import {
    LayoutDashboard,
    ShoppingBag,
    Package,
    ClipboardList,
    CalendarCheck,
    Receipt,
    FileText,
    Users,
    Settings,
    Sun,
    Moon,
    Menu,
    X,
    LogOut,
    ChevronLeft,
} from 'lucide-vue-next';

const { isDark, toggle: toggleDark } = useDarkMode();
const page = usePage();
const user = computed(() => page.props.auth?.user);

const sidebarOpen = ref(false);
const sidebarCollapsed = ref(false);

const navigation = [
    { name: 'Dashboard', href: '/admin/dashboard', icon: LayoutDashboard },
    { name: 'POS', href: '/admin/pos', icon: ShoppingBag },
    { name: 'Inventario', href: '/admin/inventory', icon: Package },
    { name: 'Pedidos', href: '/admin/orders', icon: ClipboardList },
    { name: 'Reservas', href: '/admin/reservations', icon: CalendarCheck },
    { name: 'Gastos', href: '/admin/expenses', icon: Receipt },
    { name: 'Cotizaciones', href: '/admin/quotations', icon: FileText },
    { name: 'Clientes', href: '/admin/customers', icon: Users },
    { name: 'Configuracion', href: '/admin/settings', icon: Settings },
];

const isActive = (href) => {
    return page.url.startsWith(href);
};
</script>

<template>
    <div class="min-h-screen bg-surface">
        <!-- Mobile sidebar backdrop -->
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
                class="fixed inset-0 z-40 bg-on-surface/20 lg:hidden"
                @click="sidebarOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 flex flex-col bg-surface-container-lowest transition-all duration-300',
                sidebarCollapsed ? 'w-20' : 'w-64',
                sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
        >
            <!-- Brand -->
            <div class="flex h-16 items-center justify-between px-4">
                <Link href="/admin/dashboard" class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary">
                        <span class="text-sm font-bold text-on-primary">CC</span>
                    </div>
                    <div v-if="!sidebarCollapsed" class="overflow-hidden">
                        <p class="headline-serif text-sm font-semibold text-on-surface">Carol Creaciones</p>
                        <p class="label-gilt text-[10px] text-on-surface-variant">Gestion Integral</p>
                    </div>
                </Link>
                <button
                    class="hidden lg:flex items-center justify-center h-8 w-8 rounded-lg hover:bg-surface-container-high transition-colors"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <ChevronLeft
                        :class="['h-4 w-4 text-on-surface-variant transition-transform', sidebarCollapsed && 'rotate-180']"
                    />
                </button>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="item.href"
                    :class="[
                        'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                        isActive(item.href)
                            ? 'bg-primary text-on-primary'
                            : 'text-on-surface-variant hover:bg-surface-container-high',
                    ]"
                >
                    <component :is="item.icon" class="h-5 w-5 flex-shrink-0" />
                    <span v-if="!sidebarCollapsed">{{ item.name }}</span>
                </Link>
            </nav>

            <!-- User section -->
            <div class="border-t border-surface-container-high/50 p-3">
                <div class="flex items-center gap-3 rounded-xl px-3 py-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-surface-container-highest">
                        <span class="text-xs font-semibold text-on-surface">
                            {{ user?.name?.charAt(0)?.toUpperCase() ?? 'A' }}
                        </span>
                    </div>
                    <div v-if="!sidebarCollapsed" class="flex-1 overflow-hidden">
                        <p class="truncate text-sm font-medium text-on-surface">{{ user?.name ?? 'Admin' }}</p>
                        <p class="truncate text-xs text-on-surface-variant">{{ user?.email ?? '' }}</p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div :class="['transition-all duration-300', sidebarCollapsed ? 'lg:pl-20' : 'lg:pl-64']">
            <!-- Top bar -->
            <header class="sticky top-0 z-30 flex h-16 items-center justify-between gap-4 px-4 sm:px-6 glass">
                <button
                    class="flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors lg:hidden"
                    @click="sidebarOpen = true"
                >
                    <Menu class="h-5 w-5 text-on-surface" />
                </button>

                <div class="flex-1" />

                <div class="flex items-center gap-2">
                    <button
                        class="flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors"
                        @click="toggleDark"
                    >
                        <Moon v-if="!isDark" class="h-5 w-5 text-on-surface-variant" />
                        <Sun v-else class="h-5 w-5 text-on-surface-variant" />
                    </button>

                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="flex items-center justify-center h-10 w-10 rounded-xl hover:bg-surface-container-high transition-colors"
                    >
                        <LogOut class="h-5 w-5 text-on-surface-variant" />
                    </Link>
                </div>
            </header>

            <!-- Page content -->
            <main class="p-4 sm:p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
