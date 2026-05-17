<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'tertiary', 'secondary', 'ghost', 'icon'].includes(v),
    },
    type: {
        type: String,
        default: 'button',
    },
    href: String,
    disabled: Boolean,
    loading: Boolean,
    fullWidth: Boolean,
});

defineEmits(['click']);

const classes = computed(() => {
    const map = {
        primary: 'btn btn-primary',
        tertiary: 'btn btn-tertiary',
        secondary: 'btn-secondary',
        ghost: 'btn btn-ghost',
        icon: 'btn-icon',
    };
    return [
        map[props.variant],
        props.fullWidth ? 'w-full justify-center' : '',
        (props.disabled || props.loading) ? 'opacity-50 pointer-events-none' : '',
    ].filter(Boolean).join(' ');
});

const tag = computed(() => (props.href ? 'a' : 'button'));
</script>

<template>
    <component
        :is="tag"
        :type="tag === 'button' ? type : undefined"
        :href="href"
        :class="classes"
        :disabled="disabled || loading"
        @click="$emit('click', $event)"
    >
        <svg
            v-if="loading"
            class="animate-spin"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
        >
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25"/>
            <path d="M4 12a8 8 0 0 1 8-8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <slot/>
    </component>
</template>
