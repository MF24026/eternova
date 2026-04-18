<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'secondary', 'tertiary', 'danger'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
    disabled: Boolean,
    loading: Boolean,
});

defineEmits(['click']);

const classes = computed(() => {
    const base = 'inline-flex items-center justify-center font-medium transition-all duration-200 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/50';

    const variants = {
        primary: 'gradient-signature text-on-primary hover:opacity-90 active:scale-[0.98]',
        secondary: 'bg-transparent text-primary underline underline-offset-4 decoration-primary/30 hover:decoration-primary',
        tertiary: 'bg-surface-container-high text-on-surface hover:bg-surface-container-highest active:scale-[0.98]',
        danger: 'bg-error text-on-primary hover:opacity-90 active:scale-[0.98]',
    };

    const sizes = {
        sm: 'px-4 py-1.5 text-xs',
        md: 'px-6 py-2.5 text-sm',
        lg: 'px-8 py-3 text-base',
    };

    return [
        base,
        variants[props.variant],
        sizes[props.size],
        (props.disabled || props.loading) && 'opacity-50 pointer-events-none',
    ].filter(Boolean).join(' ');
});
</script>

<template>
    <button
        :class="classes"
        :disabled="disabled || loading"
        @click="$emit('click', $event)"
    >
        <svg
            v-if="loading"
            class="mr-2 h-4 w-4 animate-spin"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <slot />
    </button>
</template>
