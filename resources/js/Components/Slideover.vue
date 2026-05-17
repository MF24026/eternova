<script setup>
import { ref, watch } from 'vue';
import { X } from 'lucide-vue-next';
import { useSwipeClose } from '@/Composables/useSwipeClose';

const props = defineProps({
    open: Boolean,
    title: String,
    subtitle: String,
});

const emit = defineEmits(['close']);

const panelRef = ref(null);

const { translateX } = useSwipeClose(panelRef, {
    onClose: () => emit('close'),
    direction: 'right',
    threshold: 150,
});

watch(() => props.open, (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : '';
});
</script>

<template>
    <Teleport to="body">
        <div :class="['slideover-scrim', { open }]" @click="$emit('close')"/>
        <aside
            ref="panelRef"
            :class="['slideover', { open }]"
            :style="translateX ? { transform: `translateX(${translateX}px)` } : null"
        >
            <header class="row" style="padding: 24px 24px 16px; align-items: flex-start; gap: 12px;">
                <div class="grow">
                    <div v-if="subtitle" class="label-gilt" style="margin-bottom: 6px">{{ subtitle }}</div>
                    <h2 v-if="title" class="serif" style="margin: 0; font-size: 28px; line-height: 1.1">{{ title }}</h2>
                </div>
                <button class="btn-icon" aria-label="Cerrar" @click="$emit('close')">
                    <X :size="20"/>
                </button>
            </header>
            <div class="scroll grow" style="padding: 0 24px 24px">
                <slot/>
            </div>
            <footer v-if="$slots.footer" style="padding: 16px 24px 24px; background: var(--surface-low)">
                <slot name="footer"/>
            </footer>
        </aside>
    </Teleport>
</template>
