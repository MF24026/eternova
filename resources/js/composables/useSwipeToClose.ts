import { useSwipe } from '@vueuse/core'
import type { MaybeRefOrGetter } from 'vue'

/**
 * Swipe-to-close handling for a slideover panel, built on @vueuse/core's
 * useSwipe (single source of truth — no hand-rolled touch math).
 *
 * Attach to the panel element. A swipe past the threshold in the panel's
 * dismiss direction invokes onClose. Right-docked panels close on a rightward
 * swipe, left-docked panels on a leftward swipe. Vertical gestures are ignored
 * so the panel body can still scroll.
 *
 * Usage:
 *   const panel = ref<HTMLElement | null>(null)
 *   useSwipeToClose(panel, 'right', () => emit('update:modelValue', false))
 */
export function useSwipeToClose(
    target: MaybeRefOrGetter<HTMLElement | null | undefined>,
    side: 'right' | 'left',
    onClose: () => void,
    threshold = 60,
): void {
    useSwipe(target, {
        threshold,
        onSwipeEnd(_event, direction) {
            if (side === 'right' && direction === 'right') onClose()
            if (side === 'left' && direction === 'left') onClose()
        },
    })
}
