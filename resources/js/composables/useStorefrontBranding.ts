import { watch } from 'vue'
import { useStorefrontStore } from '@/stores/storefront'

// Design system defaults to fall back to when a tenant has no custom colors.
const DEFAULT_PRIMARY = '#7c545d'
const DEFAULT_SECONDARY = '#5a4b71'

/**
 * Injects tenant brand colors as CSS custom properties on the document root.
 * Call once from StorefrontLayout.vue on mount.
 *
 * The storefront components reference `--brand-primary` and `--brand-secondary`
 * directly so each tenant's public catalog is visually themed without touching
 * global design tokens.
 */
export function useStorefrontBranding() {
    const store = useStorefrontStore()

    function applyBranding(primary: string | null, secondary: string | null): void {
        const root = document.documentElement
        root.style.setProperty('--brand-primary', primary ?? DEFAULT_PRIMARY)
        root.style.setProperty('--brand-secondary', secondary ?? DEFAULT_SECONDARY)
    }

    function applyFavicon(faviconUrl: string | null): void {
        if (!faviconUrl) return

        const existing = document.querySelector<HTMLLinkElement>("link[rel~='icon']")
        if (existing) {
            existing.href = faviconUrl
        } else {
            const link = document.createElement('link')
            link.rel = 'icon'
            link.href = faviconUrl
            document.head.appendChild(link)
        }
    }

    // Apply immediately if tenant is already loaded (navigating between storefront pages).
    if (store.tenant) {
        applyBranding(store.tenant.primary_color, store.tenant.secondary_color)
        applyFavicon(store.tenant.favicon_url)
    }

    // Also watch for the first load completing.
    watch(
        () => store.tenant,
        (t) => {
            if (!t) return
            applyBranding(t.primary_color, t.secondary_color)
            applyFavicon(t.favicon_url)
        },
    )
}
