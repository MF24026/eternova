<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'soft', 'primary', 'success', 'warning', 'error', 'info'].includes(v),
    },
});

const classes = computed(() => {
    if (props.variant === 'default') return 'bloom';
    return `bloom bloom-${props.variant}`;
});
</script>

<template>
    <span :class="classes">
        <slot/>
    </span>
</template>
