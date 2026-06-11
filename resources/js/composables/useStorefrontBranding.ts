import { watch } from 'vue'
import { useStorefrontStore } from '@/stores/storefront'
import { useTheme } from '@/composables/useTheme'

// Design system defaults — used when a tenant has no custom colours.
const DEFAULT_PRIMARY = '#7c545d'
const DEFAULT_SECONDARY = '#5a4b71'

// Luminance threshold: primaries above this are considered "light" → use dark text.
const LUMINANCE_THRESHOLD = 0.45

/**
 * Returns the sRGB relative luminance of a hex colour (#rrggbb).
 * Implements the WCAG 2.1 formula for readability decisions.
 */
function relativeLuminance(hex: string): number {
    const r = parseInt(hex.slice(1, 3), 16) / 255
    const g = parseInt(hex.slice(3, 5), 16) / 255
    const b = parseInt(hex.slice(5, 7), 16) / 255

    const linearise = (c: number): number =>
        c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)

    return 0.2126 * linearise(r) + 0.7152 * linearise(g) + 0.0722 * linearise(b)
}

/**
 * Returns a legible text colour for use on top of the given background hex.
 * - Light background (luminance > threshold) → dark on-surface text (#3d2f32)
 * - Dark background                          → near-white text (#fff7f7)
 */
function readableTextOn(hex: string): string {
    return relativeLuminance(hex) > LUMINANCE_THRESHOLD ? '#3d2f32' : '#fff7f7'
}

/**
 * Guards against tenants that store colours as 3-digit shorthands or with
 * uppercase. Normalises to lowercase 6-digit #rrggbb, or returns null when the
 * value is not a parseable colour so callers can fall back to the default.
 */
function normalisedHex(raw: string | null | undefined): string | null {
    if (!raw) return null
    const trimmed = raw.trim()

    // Already 7-char #rrggbb
    if (/^#[0-9a-fA-F]{6}$/.test(trimmed)) {
        return trimmed.toLowerCase()
    }

    // 4-char #rgb shorthand — expand to #rrggbb
    if (/^#[0-9a-fA-F]{3}$/.test(trimmed)) {
        const [, r, g, b] = trimmed
        return `#${r}${r}${g}${g}${b}${b}`.toLowerCase()
    }

    return null
}

/**
 * Injects per-tenant brand colours as CSS custom properties on document root,
 * re-derived for the current dark/light mode.
 *
 * Token derivation:
 *   --primary            = brand primary (as-is)
 *   --primary-dim        = LIGHT: brand mixed 85% toward black (subtle darkening)
 *                          DARK:  brand mixed 70% toward white (lighter variant)
 *   --primary-container  = LIGHT: brand mixed 22% toward white (pastel tint)
 *                          DARK:  brand mixed 60% toward black (deep container)
 *   --on-primary         = computed from luminance: dark text on light primaries,
 *                          near-white on dark primaries
 *   --secondary-*        = same logic applied to the secondary brand colour
 *   --brand-primary/secondary = aliases kept for backward-compat
 *
 * Only the --primary* / --secondary* family is set inline.  Surface tokens
 * (--surface*, --on-surface*, --outline-*) are intentionally left to the .dark
 * class block so the page chrome goes dark correctly.
 *
 * Call once from StorefrontLayout on mount.  Internal watchers keep tokens in
 * sync when the tenant loads and when the user toggles dark mode.
 */
export function useStorefrontBranding(): void {
    const store = useStorefrontStore()
    const { isDark } = useTheme()

    function derivedTokens(
        primary: string,
        secondary: string,
        dark: boolean,
    ): Record<string, string> {
        const onPrimary = readableTextOn(primary)

        if (dark) {
            return {
                '--primary': primary,
                '--primary-dim': `color-mix(in srgb, ${primary} 70%, white)`,
                '--primary-container': `color-mix(in srgb, ${primary} 60%, black)`,
                '--on-primary': onPrimary,

                '--secondary': secondary,
                '--secondary-container': `color-mix(in srgb, ${secondary} 60%, black)`,

                '--brand-primary': primary,
                '--brand-secondary': secondary,
            }
        }

        return {
            '--primary': primary,
            '--primary-dim': `color-mix(in srgb, ${primary} 85%, black)`,
            '--primary-container': `color-mix(in srgb, ${primary} 22%, white)`,
            '--on-primary': onPrimary,

            '--secondary': secondary,
            '--secondary-container': `color-mix(in srgb, ${secondary} 22%, white)`,

            '--brand-primary': primary,
            '--brand-secondary': secondary,
        }
    }

    function applyBranding(primaryRaw: string | null, secondaryRaw: string | null): void {
        const primary = normalisedHex(primaryRaw) ?? DEFAULT_PRIMARY
        const secondary = normalisedHex(secondaryRaw) ?? DEFAULT_SECONDARY

        const tokens = derivedTokens(primary, secondary, isDark.value)
        const root = document.documentElement

        for (const [prop, value] of Object.entries(tokens)) {
            root.style.setProperty(prop, value)
        }
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

    function rederiveFromCurrentTenant(): void {
        if (!store.tenant) return
        applyBranding(store.tenant.primary_color, store.tenant.secondary_color)
    }

    // Apply immediately when tenant is already loaded (intra-SPA navigation).
    if (store.tenant) {
        applyBranding(store.tenant.primary_color, store.tenant.secondary_color)
        applyFavicon(store.tenant.favicon_url)
    }

    // Re-derive when the tenant data arrives for the first time.
    watch(
        () => store.tenant,
        (t) => {
            if (!t) return
            applyBranding(t.primary_color, t.secondary_color)
            applyFavicon(t.favicon_url)
        },
    )

    // Re-derive whenever dark mode toggles — inline styles win over .dark class
    // so the container/dim tokens must be recalculated for each mode.
    watch(isDark, rederiveFromCurrentTenant)
}
