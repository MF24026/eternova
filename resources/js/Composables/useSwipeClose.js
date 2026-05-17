import { ref, onMounted, onUnmounted } from 'vue';

export function useSwipeClose(elementRef, { onClose, direction = 'right', threshold = 150 } = {}) {
    const startX = ref(0);
    const startY = ref(0);
    const currentX = ref(0);
    const isSwiping = ref(false);
    const translateX = ref(0);

    const handleTouchStart = (e) => {
        startX.value = e.touches[0].clientX;
        startY.value = e.touches[0].clientY;
        isSwiping.value = true;
    };

    const handleTouchMove = (e) => {
        if (!isSwiping.value) return;

        currentX.value = e.touches[0].clientX;
        const diffX = currentX.value - startX.value;
        const diffY = Math.abs(e.touches[0].clientY - startY.value);

        if (diffY > Math.abs(diffX)) {
            isSwiping.value = false;
            translateX.value = 0;
            return;
        }

        if (direction === 'right' && diffX > 0) {
            translateX.value = diffX;
            e.preventDefault();
        } else if (direction === 'left' && diffX < 0) {
            translateX.value = diffX;
            e.preventDefault();
        }
    };

    const handleTouchEnd = () => {
        if (Math.abs(translateX.value) > threshold && onClose) {
            onClose();
        }
        translateX.value = 0;
        isSwiping.value = false;
    };

    onMounted(() => {
        const el = elementRef.value;
        if (!el) return;
        el.addEventListener('touchstart', handleTouchStart, { passive: true });
        el.addEventListener('touchmove', handleTouchMove, { passive: false });
        el.addEventListener('touchend', handleTouchEnd, { passive: true });
    });

    onUnmounted(() => {
        const el = elementRef.value;
        if (!el) return;
        el.removeEventListener('touchstart', handleTouchStart);
        el.removeEventListener('touchmove', handleTouchMove);
        el.removeEventListener('touchend', handleTouchEnd);
    });

    return {
        translateX,
        isSwiping,
    };
}
