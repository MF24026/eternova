<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'success', 'warning', 'error', 'info', 'bloom'].includes(v),
    },
});

const classes = computed(() => {
    const variants = {
        default: 'bg-surface-container-highest text-on-surface',
        success: 'bg-success/10 text-success',
        warning: 'bg-warning/10 text-warning',
        error: 'bg-error/10 text-error',
        info: 'bg-info/10 text-info',
        bloom: 'bg-secondary-container text-on-secondary-container',
    };

    return `inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold label-gilt ${variants[props.variant]}`;
});
</script>

<template>
    <span :class="classes">
        <slot />
    </span>
</template>
