import { ref } from 'vue'

/**
 * Manages open/close state and swipe-to-close touch handling for a slideover panel.
 * Supports right-side and left-side variants.
 *
 * Usage:
 *   const { isOpen, open, close, onTouchStart, onTouchMove, onTouchEnd } = useSlideover()
 */
export function useSlideover(side: 'right' | 'left' = 'right') {
    const isOpen = ref(false)

    // Touch state
    let startX = 0
    let startY = 0
    let isDragging = false
    const SWIPE_THRESHOLD = 60

    function open(): void {
        isOpen.value = true
    }

    function close(): void {
        isOpen.value = false
    }

    function toggle(): void {
        isOpen.value = !isOpen.value
    }

    function onTouchStart(e: TouchEvent): void {
        const touch = e.touches[0]
        startX = touch.clientX
        startY = touch.clientY
        isDragging = true
    }

    function onTouchMove(e: TouchEvent): void {
        if (!isDragging) return
        const touch = e.touches[0]
        const deltaX = touch.clientX - startX
        const deltaY = touch.clientY - startY

        // Ignore mostly-vertical swipes
        if (Math.abs(deltaY) > Math.abs(deltaX)) {
            isDragging = false
            return
        }

        // Prevent page scroll while swiping horizontally
        e.preventDefault()
    }

    function onTouchEnd(e: TouchEvent): void {
        if (!isDragging) return
        isDragging = false

        const touch = e.changedTouches[0]
        const deltaX = touch.clientX - startX

        // Right panel: swipe right to close
        if (side === 'right' && deltaX > SWIPE_THRESHOLD) {
            close()
        }
        // Left panel: swipe left to close
        if (side === 'left' && deltaX < -SWIPE_THRESHOLD) {
            close()
        }
    }

    return {
        isOpen,
        open,
        close,
        toggle,
        onTouchStart,
        onTouchMove,
        onTouchEnd,
    }
}
