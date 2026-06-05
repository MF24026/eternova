import { createRouter, createWebHistory } from 'vue-router'
import { authGuard } from './guards'

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            name: 'home',
            component: () => import('@/pages/HomePage.vue'),
        },
        {
            path: '/login',
            name: 'login',
            component: () => import('@/pages/Auth/LoginPage.vue'),
            meta: { guestOnly: true },
        },
        {
            path: '/signup',
            name: 'signup',
            component: () => import('@/pages/Auth/SignupPage.vue'),
            meta: { guestOnly: true },
        },
        {
            path: '/admin/dashboard',
            name: 'admin.dashboard',
            component: () => import('@/pages/Admin/DashboardPage.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: () => import('@/pages/NotFoundPage.vue'),
        },
    ],
})

router.beforeEach(authGuard)

export default router
