<script setup>
import { ref, watch } from 'vue';
import { X } from 'lucide-vue-next';
import { useSwipeClose } from '@/Composables/useSwipeClose';

const props = defineProps({
    open: Boolean,
    title: String,
    side: { type: String, default: 'right' },
});

const emit = defineEmits(['close']);

const panelRef = ref(null);

const { translateX } = useSwipeClose(panelRef, {
    onClose: () => emit('close'),
    direction: props.side === 'right' ? 'right' : 'left',
    threshold: 150,
});

watch(() => props.open, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-300"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 bg-on-surface/20"
                @click="$emit('close')"
            />
        </Transition>

        <Transition
            :enter-active-class="'transition-transform duration-300 ease-out'"
            :enter-from-class="side === 'right' ? 'translate-x-full' : '-translate-x-full'"
            enter-to-class="translate-x-0"
            :leave-active-class="'transition-transform duration-300 ease-in'"
            leave-from-class="translate-x-0"
            :leave-to-class="side === 'right' ? 'translate-x-full' : '-translate-x-full'"
        >
            <div
                v-if="open"
                ref="panelRef"
                :class="[
                    'fixed inset-y-0 z-50 w-full max-w-md bg-surface-container-lowest shadow-ambient flex flex-col',
                    side === 'right' ? 'right-0' : 'left-0',
                ]"
                :style="{ transform: translateX ? `translateX(${translateX}px)` : undefined }"
            >
                <!-- Header -->
                <div class="flex items-center justify-between px-6 py-4">
                    <h2 v-if="title" class="headline-serif text-lg font-semibold text-on-surface">
                        {{ title }}
                    </h2>
                    <button
                        class="flex h-9 w-9 items-center justify-center rounded-xl hover:bg-surface-container-high transition-colors"
                        @click="$emit('close')"
                    >
                        <X class="h-5 w-5 text-on-surface-variant" />
                    </button>
                </div>

                <!-- Content -->
                <div class="flex-1 overflow-y-auto px-6 pb-6">
                    <slot />
                </div>

                <!-- Footer -->
                <div v-if="$slots.footer" class="px-6 py-4 bg-surface-container-low">
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
