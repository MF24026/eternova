import { defineStore } from 'pinia'
import { ref } from 'vue'
import { TOAST_DURATION_MS } from '@/utils/constants'

type ToastType = 'success' | 'error' | 'info' | 'warning'

interface Toast {
    id: number
    type: ToastType
    message: string
}

let nextId = 0

export const useUiStore = defineStore('ui', () => {
    const toasts = ref<Toast[]>([])
    const sidebarOpen = ref(false)
    const darkMode = ref(false)
    const theme = ref<'ethereal' | 'minimal'>('ethereal')

    function initDarkMode(): void {
        const stored = localStorage.getItem('eternova-dark-mode')
        darkMode.value = stored === 'true'
        applyDarkMode()
    }

    // Per-tenant admin palette. Cached in localStorage for instant paint on the
    // next load (no flash of rosa); the tenant's value from /me is authoritative
    // and reconciled after bootstrap.
    function initTheme(): void {
        const stored = localStorage.getItem('eternova-admin-theme')
        theme.value = stored === 'minimal' ? 'minimal' : 'ethereal'
        applyTheme()
    }

    function setTheme(next: 'ethereal' | 'minimal'): void {
        theme.value = next
        localStorage.setItem('eternova-admin-theme', next)
        applyTheme()
    }

    function applyTheme(): void {
        if (theme.value === 'minimal') {
            document.documentElement.classList.add('theme-minimal')
        } else {
            document.documentElement.classList.remove('theme-minimal')
        }
    }

    function toggleDarkMode(): void {
        darkMode.value = !darkMode.value
        localStorage.setItem('eternova-dark-mode', String(darkMode.value))
        applyDarkMode()
    }

    function applyDarkMode(): void {
        if (darkMode.value) {
            document.documentElement.classList.add('dark')
        } else {
            document.documentElement.classList.remove('dark')
        }
    }

    function addToast(type: ToastType, message: string): void {
        const id = ++nextId
        toasts.value.push({ id, type, message })
        setTimeout(() => {
            removeToast(id)
        }, TOAST_DURATION_MS)
    }

    function removeToast(id: number): void {
        const index = toasts.value.findIndex((t) => t.id === id)
        if (index !== -1) {
            toasts.value.splice(index, 1)
        }
    }

    return {
        toasts,
        sidebarOpen,
        darkMode,
        theme,
        initDarkMode,
        toggleDarkMode,
        initTheme,
        setTheme,
        addToast,
        removeToast,
    }
})
