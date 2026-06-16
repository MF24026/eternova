import { reactive } from 'vue'

/**
 * Promise-based confirmation dialog, design-system styled (dark-mode aware,
 * keyboard accessible) — a drop-in replacement for native window.confirm().
 *
 * A single <ConfirmDialog /> host (mounted once in the admin layout) renders the
 * shared state below; any component calls `confirm()` and awaits the boolean:
 *
 *   const { confirm } = useConfirm()
 *   if (!(await confirm({ title: 'Archivar producto', message: '...', variant: 'danger' }))) return
 */

export interface ConfirmOptions {
    title: string
    message?: string
    confirmLabel?: string
    cancelLabel?: string
    variant?: 'default' | 'danger'
}

interface ConfirmState {
    open: boolean
    title: string
    message: string
    confirmLabel: string
    cancelLabel: string
    variant: 'default' | 'danger'
}

const state = reactive<ConfirmState>({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    cancelLabel: 'Cancelar',
    variant: 'default',
})

let resolver: ((value: boolean) => void) | null = null

export function useConfirm() {
    function confirm(options: ConfirmOptions): Promise<boolean> {
        // Resolve a still-open previous prompt as cancelled before reusing state.
        resolver?.(false)

        state.title = options.title
        state.message = options.message ?? ''
        state.confirmLabel = options.confirmLabel ?? 'Confirmar'
        state.cancelLabel = options.cancelLabel ?? 'Cancelar'
        state.variant = options.variant ?? 'default'
        state.open = true

        return new Promise<boolean>((resolve) => {
            resolver = resolve
        })
    }

    return { confirm }
}

/**
 * Internal accessor for the ConfirmDialog host component only.
 */
export function useConfirmHost() {
    function settle(value: boolean): void {
        state.open = false
        const resolve = resolver
        resolver = null
        resolve?.(value)
    }

    return { state, settle }
}
