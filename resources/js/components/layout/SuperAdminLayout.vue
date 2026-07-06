<script setup lang="ts">
import { ref, computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
    Building2,
    BarChart3,
    Menu,
    X,
    LogOut,
    ChevronLeft,
    ChevronRight,
    Shield,
} from 'lucide-vue-next'
import { useAuth } from '@/composables/useAuth'
import AppSpinner from '@/components/base/AppSpinner.vue'

interface NavItem {
    id: string
    label: string
    to: string
    icon: unknown
}

const route = useRoute()
const router = useRouter()
const { currentUser, logout } = useAuth()

const sidebarOpen = ref(false)
const sidebarCollapsed = ref(false)
const signingOut = ref(false)

// Suscripciones + Soporte have no backend yet (7b scope = Tenants + Metricas); re-add when their
// APIs land so the nav never points at a 404.
const navItems: NavItem[] = [
    { id: 'tenants', label: 'Tenants', to: '/super-admin/tenants', icon: Building2 },
    { id: 'metrics', label: 'Metricas', to: '/super-admin/metrics', icon: BarChart3 },
]

const userInitial = computed(() => {
    const name = currentUser.value?.name ?? 'S'
    return name.charAt(0).toUpperCase()
})

function isActive(to: string): boolean {
    return route.path.startsWith(to)
}

async function handleLogout() {
    signingOut.value = true
    try {
        await logout()
        await router.push({ name: 'login' })
    } finally {
        signingOut.value = false
    }
}
</script>

<template>
    <div class="superadmin-shell">
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
                class="superadmin-backdrop"
                aria-hidden="true"
                @click="sidebarOpen = false"
            />
        </Transition>

        <!-- Sidebar -->
        <aside
            :class="[
                'superadmin-sidebar',
                { collapsed: sidebarCollapsed, open: sidebarOpen },
            ]"
            aria-label="Navegacion super-admin"
        >
            <!-- Brand -->
            <div
                class="flex items-center gap-3 px-2 pb-6"
                :style="{ justifyContent: sidebarCollapsed ? 'center' : 'flex-start' }"
            >
                <RouterLink
                    to="/super-admin"
                    class="flex items-center gap-2 text-white/90 font-serif font-semibold tracking-tighter text-base"
                    @click="sidebarOpen = false"
                >
                    <span class="w-8 h-8 rounded-lg bg-white/15 flex items-center justify-center shrink-0">
                        <Shield :size="16" class="text-white" />
                    </span>
                    <span
                        class="whitespace-nowrap overflow-hidden transition-all duration-200"
                        :class="sidebarCollapsed ? 'w-0 opacity-0' : 'w-auto opacity-100'"
                    >
                        Super Admin
                    </span>
                </RouterLink>

                <button
                    type="button"
                    class="ml-auto p-1.5 rounded-lg text-white/70 hover:text-white hover:bg-white/10 transition-colors lg:hidden"
                    aria-label="Cerrar menu"
                    @click="sidebarOpen = false"
                >
                    <X :size="16" />
                </button>
            </div>

            <!-- Nav -->
            <nav class="flex flex-col gap-1 flex-1 overflow-y-auto">
                <RouterLink
                    v-for="item in navItems"
                    :key="item.id"
                    :to="item.to"
                    :class="[
                        'superadmin-nav-item',
                        { active: isActive(item.to) },
                    ]"
                    :title="sidebarCollapsed ? item.label : undefined"
                    @click="sidebarOpen = false"
                >
                    <component :is="item.icon" :size="18" class="shrink-0" />
                    <span
                        class="text-sm font-medium whitespace-nowrap overflow-hidden transition-all duration-200"
                        :class="sidebarCollapsed ? 'w-0 opacity-0' : 'w-auto opacity-100'"
                    >
                        {{ item.label }}
                    </span>
                </RouterLink>
            </nav>

            <!-- Bottom: user + logout -->
            <div class="mt-auto pt-4 flex flex-col gap-2">
                <div
                    class="flex items-center gap-2 px-3 py-2 rounded-xl bg-white/8"
                    :class="sidebarCollapsed ? 'justify-center' : ''"
                >
                    <span class="w-7 h-7 rounded-full bg-white/20 flex items-center justify-center text-white text-xs font-bold shrink-0">
                        {{ userInitial }}
                    </span>
                    <span
                        class="text-xs text-white/80 truncate transition-all duration-200"
                        :class="sidebarCollapsed ? 'w-0 opacity-0' : 'w-auto opacity-100'"
                    >
                        {{ currentUser?.name }}
                    </span>
                </div>

                <button
                    type="button"
                    :class="[
                        'superadmin-nav-item text-white/60 hover:text-white',
                        { 'justify-center': sidebarCollapsed },
                    ]"
                    :disabled="signingOut"
                    @click="handleLogout"
                >
                    <AppSpinner v-if="signingOut" size="sm" />
                    <LogOut v-else :size="18" class="shrink-0" />
                    <span
                        class="text-sm font-medium whitespace-nowrap overflow-hidden transition-all duration-200"
                        :class="sidebarCollapsed ? 'w-0 opacity-0' : 'w-auto opacity-100'"
                    >
                        Cerrar sesión
                    </span>
                </button>

                <button
                    type="button"
                    :class="['superadmin-nav-item hidden lg:flex', sidebarCollapsed ? 'justify-center' : '']"
                    style="color: rgba(255,255,255,0.5)"
                    :aria-label="sidebarCollapsed ? 'Expandir sidebar' : 'Colapsar sidebar'"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <component :is="sidebarCollapsed ? ChevronRight : ChevronLeft" :size="18" />
                    <span
                        class="text-sm font-medium whitespace-nowrap overflow-hidden transition-all duration-200"
                        :class="sidebarCollapsed ? 'w-0 opacity-0' : 'w-auto opacity-100'"
                    >
                        Colapsar
                    </span>
                </button>
            </div>
        </aside>

        <!-- Main -->
        <div class="superadmin-main">
            <!-- Topbar -->
            <header class="superadmin-topbar">
                <button
                    type="button"
                    class="p-2 rounded-xl text-on-surface hover:bg-surface-low transition-colors lg:hidden"
                    aria-label="Abrir menu"
                    @click="sidebarOpen = true"
                >
                    <Menu :size="20" />
                </button>

                <div class="flex items-center gap-2">
                    <Shield :size="16" class="text-secondary" />
                    <span class="text-sm font-semibold text-on-surface-variant">Super Admin</span>
                </div>

                <div class="grow" />

                <div class="text-xs text-on-surface-variant">
                    {{ currentUser?.email }}
                </div>
            </header>

            <!-- Content -->
            <main class="superadmin-content">
                <slot />
            </main>
        </div>
    </div>
</template>

<style scoped>
.superadmin-shell {
    min-height: 100vh;
    display: flex;
    background: var(--surface);
}

.superadmin-sidebar {
    width: 240px;
    flex-shrink: 0;
    background: #2c1f23;
    display: flex;
    flex-direction: column;
    padding: 24px 12px;
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    z-index: 80;
    transform: translateX(-100%);
    transition: transform 0.3s ease, width 0.35s ease, padding 0.35s ease;
}

.superadmin-sidebar.open {
    transform: translateX(0);
}

.superadmin-sidebar.collapsed {
    width: 72px;
    padding: 24px 10px;
}

.superadmin-backdrop {
    position: fixed;
    inset: 0;
    z-index: 70;
    background: rgba(0, 0, 0, 0.5);
}

.superadmin-nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: 0.75rem;
    color: rgba(255, 255, 255, 0.65);
    transition: background 0.2s ease, color 0.2s ease;
    cursor: pointer;
    text-decoration: none;
}

.superadmin-nav-item:hover {
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.9);
}

.superadmin-nav-item.active {
    background: rgba(248, 196, 207, 0.15);
    color: #f8c4cf;
}

.superadmin-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.superadmin-topbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    background: var(--surface-lowest);
    border-bottom: 1px solid rgba(194, 173, 176, 0.12);
    position: sticky;
    top: 0;
    z-index: 40;
}

.superadmin-content {
    padding: 24px 20px;
    flex: 1;
}

@media (min-width: 1024px) {
    .superadmin-sidebar {
        position: sticky;
        height: 100vh;
        transform: translateX(0) !important;
    }

    .superadmin-topbar {
        padding: 16px 32px;
    }

    .superadmin-content {
        padding: 24px 32px;
    }
}
</style>
