<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
    LayoutDashboard,
    ShoppingBag,
    Package,
    CreditCard,
    ClipboardList,
    Calendar,
    FileText,
    Receipt,
    Users,
    Settings,
    Wallet,
    Sun,
    Moon,
    Menu,
    X,
    ChevronLeft,
    ChevronRight,
    Bell,
    Search,
    LogOut,
} from 'lucide-vue-next'
import { useAuth } from '@/composables/useAuth'
import { useTheme } from '@/composables/useTheme'
import AppSpinner from '@/components/base/AppSpinner.vue'
import OnboardingTour from '@/components/composite/OnboardingTour.vue'
import ConfirmDialog from '@/components/composite/ConfirmDialog.vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '@/stores/notifications'
import NotificationsDropdown from '@/components/layout/NotificationsDropdown.vue'

interface NavItem {
    id: string
    label: string
    to: string
    icon: unknown
    ownerOnly?: boolean
}

const route = useRoute()
const router = useRouter()
const { currentUser, logout } = useAuth()
const { isDark, toggle: toggleDark } = useTheme()
const notifications = useNotificationsStore()

const sidebarOpen = ref(false)
const sidebarCollapsed = ref(false)
const userMenuOpen = ref(false)
const signingOut = ref(false)

const navItems: NavItem[] = [
    { id: 'dashboard', label: 'Panel', to: '/admin/dashboard', icon: LayoutDashboard },
    { id: 'pos', label: 'Punto de venta', to: '/admin/pos', icon: CreditCard },
    { id: 'orders', label: 'Pedidos', to: '/admin/orders', icon: ClipboardList },
    { id: 'reservations', label: 'Reservas', to: '/admin/reservations', icon: Calendar },
    { id: 'inventory', label: 'Inventario', to: '/admin/inventory', icon: Package },
    { id: 'products', label: 'Productos', to: '/admin/products', icon: ShoppingBag },
    { id: 'categories', label: 'Categorias', to: '/admin/categories', icon: LayoutDashboard },
    { id: 'expenses', label: 'Gastos', to: '/admin/expenses', icon: Receipt },
    { id: 'quotations', label: 'Cotizaciones', to: '/admin/quotations', icon: FileText },
    { id: 'customers', label: 'Clientes', to: '/admin/customers', icon: Users },
    { id: 'settings', label: 'Ajustes', to: '/admin/settings', icon: Settings },
    { id: 'billing', label: 'Facturación', to: '/admin/billing', icon: Wallet, ownerOnly: true },
]

// The role lives on the current tenant membership, not on the user (see UserResource / the
// ReservationsPage pattern). Billing is Owner-only (the API 403s the rest); hide it for non-owners.
const isOwner = computed(
    () => currentUser.value?.tenants.find((t) => t.is_current)?.role === 'owner',
)
const visibleNavItems = computed(() =>
    navItems.filter((item) => !item.ownerOnly || isOwner.value),
)

const userInitial = computed(() => {
    const name = currentUser.value?.name ?? 'A'
    return name.charAt(0).toUpperCase()
})

function isActive(to: string): boolean {
    return route.path.startsWith(to)
}

function closeSidebar() {
    sidebarOpen.value = false
}

function toggleSidebar() {
    sidebarOpen.value = !sidebarOpen.value
}

function toggleCollapse() {
    sidebarCollapsed.value = !sidebarCollapsed.value
}

async function handleLogout() {
    signingOut.value = true
    userMenuOpen.value = false
    try {
        await logout()
        await router.push({ name: 'login' })
    } finally {
        signingOut.value = false
    }
}

function handleClickOutsideUserMenu(e: MouseEvent) {
    const target = e.target as HTMLElement
    if (!target.closest('[data-user-menu]')) {
        userMenuOpen.value = false
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutsideUserMenu)
    notifications.startPolling()
})
onUnmounted(() => {
    document.removeEventListener('click', handleClickOutsideUserMenu)
    notifications.stopPolling()
})

// ── Onboarding tour: shown once per tenant on the owner's first visit ───────────
const showTour = ref(false)

const currentMembership = computed(() =>
    currentUser.value?.tenants.find((t) => t.is_current) ?? null,
)
const tourStorageKey = computed(() =>
    currentMembership.value ? `eternova_onboarding_seen_${currentMembership.value.id}` : null,
)

function maybeStartTour(): void {
    const key = tourStorageKey.value
    if (key === null) return
    // Suppress the tour under browser automation (e2e) so it never blocks other
    // specs — unless a test explicitly opts in via the force flag. In production
    // navigator.webdriver is false, so the tour behaves normally.
    if (navigator.webdriver === true && localStorage.getItem('eternova_force_tour') !== '1') return
    // Only the owner gets the first-run tour, and only if they haven't seen it.
    if (currentMembership.value?.role !== 'owner') return
    if (localStorage.getItem(key) === '1') return
    // Anchor the welcome tour to the dashboard it describes — don't pop it over
    // whatever page the owner happens to land on first (e.g. a bookmarked
    // /admin/settings). If they land elsewhere, it shows when they reach it.
    if (route.name !== 'admin.dashboard') return
    showTour.value = true
}

function onTourFinish(): void {
    const key = tourStorageKey.value
    if (key !== null) localStorage.setItem(key, '1')
}

// Start the tour once the current user (and tenant context) is known, and also
// when the owner navigates onto the dashboard (covers landing elsewhere first).
watch(currentMembership, (membership) => {
    if (membership) maybeStartTour()
}, { immediate: true })

watch(() => route.name, () => maybeStartTour())
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
                aria-hidden="true"
                @click="closeSidebar"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'sidebar admin-sidebar',
                { collapsed: sidebarCollapsed, open: sidebarOpen },
            ]"
            :aria-label="'Navegacion principal'"
        >
            <!-- Brand -->
            <div
                class="row"
                :style="{
                    gap: '10px',
                    padding: '0 8px 24px',
                    justifyContent: sidebarCollapsed ? 'center' : 'flex-start',
                }"
            >
                <RouterLink
                    to="/admin/dashboard"
                    class="flex items-center gap-2 text-primary font-serif font-semibold tracking-tighter text-lg truncate"
                    @click="closeSidebar"
                >
                    <span class="shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-primary to-primary-container flex items-center justify-center text-on-primary text-sm font-bold">
                        E
                    </span>
                    <span
                        class="label-txt transition-opacity duration-200"
                        :class="sidebarCollapsed ? 'opacity-0 w-0 overflow-hidden' : 'opacity-100'"
                    >
                        Eternova
                    </span>
                </RouterLink>

                <button
                    type="button"
                    class="btn-icon admin-sidebar-close ml-auto"
                    aria-label="Cerrar menu"
                    @click="closeSidebar"
                >
                    <X :size="18" />
                </button>
            </div>

            <!-- Nav items -->
            <nav class="stack scroll flex-1" style="gap: 4px">
                <RouterLink
                    v-for="item in visibleNavItems"
                    :key="item.id"
                    :to="item.to"
                    :class="['sidebar-item', { active: isActive(item.to) }]"
                    :title="sidebarCollapsed ? item.label : undefined"
                    @click="closeSidebar"
                >
                    <component :is="item.icon" :size="18" class="shrink-0" />
                    <span class="label-txt">{{ item.label }}</span>
                </RouterLink>
            </nav>

            <!-- Collapse toggle (desktop only) -->
            <button
                type="button"
                class="sidebar-item admin-sidebar-collapse mt-2"
                style="color: var(--on-surface-variant)"
                :aria-label="sidebarCollapsed ? 'Expandir sidebar' : 'Colapsar sidebar'"
                @click="toggleCollapse"
            >
                <component :is="sidebarCollapsed ? ChevronRight : ChevronLeft" :size="18" />
                <span class="label-txt">Colapsar</span>
            </button>
        </aside>

        <!-- Main content -->
        <div class="admin-main">
            <!-- Topbar -->
            <header class="admin-topbar">
                <button
                    type="button"
                    class="btn-icon admin-topbar-menu"
                    aria-label="Abrir menu"
                    @click="toggleSidebar"
                >
                    <Menu :size="20" />
                </button>

                <div class="flex flex-col">
                    <h1 class="serif m-0 text-2xl leading-none text-on-surface">
                        Panel de administracion
                    </h1>
                </div>

                <div class="grow" />

                <!-- Search (desktop) -->
                <div class="admin-topbar-search">
                    <input
                        class="field"
                        placeholder="Buscar..."
                        style="padding-left: 44px"
                        aria-label="Buscar"
                    />
                    <Search
                        :size="18"
                        style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)"
                        aria-hidden="true"
                    />
                </div>

                <!-- Dark mode toggle -->
                <button
                    type="button"
                    class="btn-icon"
                    :aria-label="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
                    @click="toggleDark"
                >
                    <component :is="isDark ? Sun : Moon" :size="20" />
                </button>

                <!-- Notifications -->
                <div class="relative">
                    <button
                        type="button"
                        class="btn-icon relative"
                        aria-label="Notificaciones"
                        data-notif-bell
                        data-testid="notif-bell"
                        :aria-expanded="notifications.open"
                        aria-haspopup="menu"
                        @click="notifications.toggle()"
                    >
                        <Bell :size="20" />
                        <span
                            v-if="notifications.unreadCount > 0"
                            class="notif-badge"
                            data-testid="notif-badge"
                        >{{ notifications.unreadCount > 9 ? '9+' : notifications.unreadCount }}</span>
                    </button>
                    <NotificationsDropdown />
                </div>

                <!-- User menu -->
                <div class="relative" data-user-menu>
                    <button
                        type="button"
                        class="admin-topbar-user"
                        :aria-expanded="userMenuOpen"
                        aria-haspopup="menu"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <span class="admin-topbar-avatar" aria-hidden="true">
                            {{ userInitial }}
                        </span>
                        <div class="hidden lg:flex flex-col leading-tight">
                            <span class="text-xs font-semibold text-on-surface">{{ currentUser?.name ?? 'Admin' }}</span>
                        </div>
                    </button>

                    <Transition
                        enter-active-class="transition-all duration-150 ease-out"
                        enter-from-class="opacity-0 translate-y-1"
                        enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition-all duration-100 ease-in"
                        leave-from-class="opacity-100 translate-y-0"
                        leave-to-class="opacity-0 translate-y-1"
                    >
                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 mt-2 w-52 rounded-xl bg-surface-lowest shadow-[var(--shadow-lifted)] py-1 z-50 dark:bg-surface-mid"
                            role="menu"
                        >
                            <div class="px-4 py-2.5 mb-1 bg-surface-low dark:bg-surface-high rounded-t-xl">
                                <p class="text-xs font-semibold text-on-surface">{{ currentUser?.name }}</p>
                                <p class="text-xs text-on-surface-variant truncate">{{ currentUser?.email }}</p>
                            </div>
                            <button
                                type="button"
                                class="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-on-surface hover:bg-surface-low transition-colors dark:hover:bg-surface-high"
                                role="menuitem"
                                :disabled="signingOut"
                                @click="handleLogout"
                            >
                                <AppSpinner v-if="signingOut" size="sm" />
                                <LogOut v-else class="w-4 h-4" aria-hidden="true" />
                                Cerrar sesion
                            </button>
                        </div>
                    </Transition>
                </div>
            </header>

            <!-- Page content -->
            <main class="admin-content">
                <slot />
            </main>
        </div>

        <!-- First-login onboarding tour (owner, once per tenant) -->
        <OnboardingTour v-model="showTour" @finish="onTourFinish" />

        <!-- Single host for useConfirm() promise-based confirmations -->
        <ConfirmDialog />
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
    display: inline-flex;
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
    position: sticky;
    top: 0;
    z-index: 40;
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

.notif-badge {
    position: absolute;
    top: -0.15rem;
    right: -0.15rem;
    min-width: 1.05rem;
    height: 1.05rem;
    padding: 0 0.28rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    color: #fff;
    background: var(--primary);
    border-radius: var(--r-full);
}

.admin-topbar-user {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px;
    border-radius: 99px;
    background: transparent;
    transition: background 0.2s ease;
    cursor: pointer;
}

.admin-topbar-user:hover {
    background: var(--surface-low);
}

.admin-topbar-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--gradient-soft);
    display: grid;
    place-items: center;
    color: var(--primary-dim);
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
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
        max-width: 300px;
        flex: 1;
    }

    .admin-topbar-user {
        padding: 4px 12px 4px 4px;
        background: var(--surface-low);
    }

    .admin-content {
        padding: 24px 32px;
    }
}
</style>
