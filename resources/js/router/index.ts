import { createRouter, createWebHistory } from 'vue-router'
import { authGuard } from './guards'

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            name: 'home',
            component: () => import('@/pages/HomePage.vue'),
            meta: { layout: 'marketing' },
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('@/pages/Auth/LoginPage.vue'),
            meta: { guestOnly: true, layout: 'onboarding' },
        },
        {
            path: '/signup',
            name: 'signup',
            component: () => import('@/pages/Onboarding/SignupWizardPage.vue'),
            meta: { guestOnly: true, layout: 'onboarding' },
        },

        // ── Admin routes ────────────────────────────────────────────────────
        {
            path: '/admin/dashboard',
            name: 'admin.dashboard',
            component: () => import('@/pages/Admin/DashboardPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/products',
            name: 'admin.products',
            component: () => import('@/pages/Admin/ProductsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/categories',
            name: 'admin.categories',
            component: () => import('@/pages/Admin/CategoriesPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/inventory',
            name: 'admin.inventory',
            component: () => import('@/pages/Admin/InventoryPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/pos',
            name: 'admin.pos',
            component: () => import('@/pages/Admin/POSPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/orders',
            name: 'admin.orders',
            component: () => import('@/pages/Admin/OrdersPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/reservations',
            name: 'admin.reservations',
            component: () => import('@/pages/Admin/ReservationsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/expenses',
            name: 'admin.expenses',
            component: () => import('@/pages/Admin/ExpensesPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/quotations',
            name: 'admin.quotations',
            component: () => import('@/pages/Admin/QuotationsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/customers',
            name: 'admin.customers',
            component: () => import('@/pages/Admin/CustomersPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/settings',
            name: 'admin.settings',
            component: () => import('@/pages/Admin/SettingsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },

        // ── Catch-all ────────────────────────────────────────────────────────
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: () => import('@/pages/NotFoundPage.vue'),
        },
    ],
})

router.beforeEach(authGuard)

export default router
