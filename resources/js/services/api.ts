import axios, { type AxiosError, type AxiosInstance } from 'axios'

const api: AxiosInstance = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL ?? '/api/v1',
    withCredentials: true,
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
})

// Fetch the CSRF cookie once per session before any state-mutating request.
let csrfFetched = false

api.interceptors.request.use(async (config) => {
    if (
        !csrfFetched &&
        ['post', 'put', 'patch', 'delete'].includes(config.method ?? '')
    ) {
        await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
        csrfFetched = true
    }
    return config
})

// On 401 clear local auth state and redirect to login.
// Lazy imports break the circular dependency: api.ts -> stores/auth -> services/AuthService -> api.ts
api.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
        if (error.response?.status === 401) {
            import('@/stores/auth').then(({ useAuthStore }) => {
                useAuthStore().clearLocal()
            })
            import('@/router').then(({ default: router }) => {
                const current = router.currentRoute.value

                // Only force a login redirect on routes that actually require auth.
                // Public routes (the storefront) call fetchMe() on boot, which 401s
                // for anonymous visitors — that is expected and must NOT bounce them
                // to /login. The router guard already skips public routes, but this
                // interceptor runs asynchronously and would otherwise win the race.
                const requiresAuth = current.matched.some(
                    (record) => record.meta.requiresAuth === true,
                )

                if (requiresAuth && current.name !== 'login') {
                    router.push({
                        name: 'login',
                        query: { redirect: current.fullPath },
                    })
                }
            })
        }
        return Promise.reject(error)
    },
)

export default api
