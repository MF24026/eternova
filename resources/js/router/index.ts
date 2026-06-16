import { createRouter, createWebHistory } from 'vue-router'
import { authGuard } from './guards'

const router = createRouter({
    history: createWebHistory(),
    routes: [
        // ── Storefront routes (public, tenant-subdomain root) ────────────────
        // These own `/`, `/products`, and `/products/:slug` because on a TENANT
        // subdomain the root is always the public catalog. The original generic
        // HomePage has been moved to `/welcome` below. When a dedicated SaaS
        // marketing site is built it will reclaim the main-domain root.
        {
            path: '/',
            name: 'storefront.home',
            component: () => import('@/pages/Storefront/HomePage.vue'),
            meta: { layout: 'storefront', public: true },
        },
        {
            path: '/products',
            name: 'storefront.products',
            component: () => import('@/pages/Storefront/ProductsListPage.vue'),
            meta: { layout: 'storefront', public: true },
        },
        {
            path: '/products/:slug',
            name: 'storefront.product',
            component: () => import('@/pages/Storefront/ProductDetailPage.vue'),
            meta: { layout: 'storefront', public: true },
        },

        // ── SaaS marketing / generic landing (moved from `/`) ───────────────
        {
            path: '/welcome',
            name: 'home',
            component: () => import('@/pages/HomePage.vue'),
            meta: { layout: 'marketing' },
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('@/pages/Auth/LoginPage.vue'),
            // No layout wrapper: LoginPage is a full-screen self-contained design
            // (gradient-bloom backdrop + bloom petals) matching the Carol Creaciones prototype.
            meta: { guestOnly: true },
        },
        {
            path: '/signup',
            name: 'signup',
            component: () => import('@/pages/Onboarding/SignupWizardPage.vue'),
            meta: { guestOnly: true, layout: 'onboarding' },
        },
        {
            path: '/forgot-password',
            name: 'forgot-password',
            component: () => import('@/pages/Auth/ForgotPasswordPage.vue'),
            meta: { guestOnly: true },
        },
        {
            path: '/reset-password',
            name: 'reset-password',
            component: () => import('@/pages/Auth/ResetPasswordPage.vue'),
            meta: { guestOnly: true },
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
            component: () => import('@/pages/Admin/ProductsListPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/products/new',
            name: 'admin.products.create',
            component: () => import('@/pages/Admin/ProductFormPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/products/:id/edit',
            name: 'admin.products.edit',
            component: () => import('@/pages/Admin/ProductFormPage.vue'),
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
            meta: { requiresAuth: true, layout: 'admin', title: 'Inventario' },
        },
        {
            path: '/admin/inventory/movements',
            name: 'admin.inventory.movements',
            component: () => import('@/pages/Admin/Inventory/MovementsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin', title: 'Movimientos de Inventario' },
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
            path: '/admin/orders/:id',
            name: 'admin.orders.detail',
            // Stub replaced by S4-E6 — providing the route now so row-click
            // navigation works before the detail page is fully built.
            component: () => import('@/pages/Admin/OrderDetailPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/reservations',
            name: 'admin.reservations',
            component: () => import('@/pages/Admin/ReservationsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/reservations/:id',
            name: 'admin.reservations.detail',
            // Stub in place so row/card clicks navigate without 404.
            // Full detail page is built in S5-E7.
            component: () => import('@/pages/Admin/ReservationDetailPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/expenses',
            name: 'admin.expenses',
            component: () => import('@/pages/Admin/ExpensesPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/expenses/report',
            name: 'admin.expenses.report',
            component: () => import('@/pages/Admin/ExpenseReportPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/quotations',
            name: 'admin.quotations',
            component: () => import('@/pages/Admin/QuotationsPage.vue'),
            meta: { requiresAuth: true, layout: 'admin' },
        },
        {
            path: '/admin/quotations/:id',
            name: 'admin.quotations.detail',
            // Stub in place so row/card clicks navigate without 404.
            // Full detail page is built in S7-E8.
            component: () => import('@/pages/Admin/QuotationDetailPage.vue'),
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

        // ── Public tracking ─────────────────────────────────────────────────
        // No `layout` key → LayoutSwitcher falls back to bare <slot /> (default).
        // No `requiresAuth` → auth guard never redirects to /login for this route.
        {
            path: '/track/:token',
            name: 'track.show',
            component: () => import('@/pages/Public/OrderTrackingPage.vue'),
            meta: { public: true },
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
