import { computed } from 'vue'
import { useUiStore } from '@/stores/ui'

type ThemePreference = 'light' | 'dark' | 'system'

/**
 * Composable for dark mode toggle, wrapping the ui store.
 * Persists preference as `theme` key in localStorage.
 * The ui store applies the `dark` class on <html>.
 */
export function useTheme() {
    const ui = useUiStore()

    const isDark = computed(() => ui.darkMode)

    function toggle(): void {
        ui.toggleDarkMode()
    }

    // Per-tenant admin palette (Ethereal rosa / Minimalista), orthogonal to
    // light/dark. Distinct from `setTheme` below, which is the light/dark toggle.
    const palette = computed(() => ui.theme)

    function setPalette(next: 'ethereal' | 'minimal'): void {
        ui.setTheme(next)
    }

    function setTheme(preference: ThemePreference): void {
        if (preference === 'system') {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
            // Use the public store API to align dark mode state
            if (prefersDark !== ui.darkMode) ui.toggleDarkMode()
            localStorage.setItem('eternova-theme', 'system')
        } else {
            const wantDark = preference === 'dark'
            if (wantDark !== ui.darkMode) ui.toggleDarkMode()
            localStorage.setItem('eternova-theme', preference)
        }
    }

    return {
        isDark,
        toggle,
        setTheme,
        palette,
        setPalette,
    }
}
