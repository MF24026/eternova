import { useUiStore } from '@/stores/ui'

/**
 * Convenience composable for dispatching toast notifications.
 * Call this from any component or page without importing the ui store directly.
 */
export function useToast() {
    const ui = useUiStore()

    return {
        success: (message: string) => ui.addToast('success', message),
        error: (message: string) => ui.addToast('error', message),
        info: (message: string) => ui.addToast('info', message),
        warning: (message: string) => ui.addToast('warning', message),
    }
}
