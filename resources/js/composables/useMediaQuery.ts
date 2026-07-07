import { ref, onMounted, onUnmounted, type Ref } from 'vue'

/**
 * Reactive `matchMedia` boolean. Read synchronously at setup so the first render
 * is already correct (no flash), then kept in sync via the change listener.
 *
 * Used to render only the layout that applies at the current viewport instead of
 * rendering both and hiding one with CSS — which duplicates ids/testids in the DOM.
 */
export function useMediaQuery(query: string): Ref<boolean> {
    const matches = ref(false)
    let mql: MediaQueryList | null = null

    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        mql = window.matchMedia(query)
        matches.value = mql.matches
    }

    function update(e: MediaQueryListEvent): void {
        matches.value = e.matches
    }

    onMounted(() => mql?.addEventListener('change', update))
    onUnmounted(() => mql?.removeEventListener('change', update))

    return matches
}
