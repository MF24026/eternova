<script setup>
defineProps({
    padding: { type: String, default: 'md' },
    hoverable: Boolean,
});
</script>

<template>
    <div
        :class="[
            'rounded-xl bg-surface-container-lowest transition-all duration-200',
            hoverable && 'hover:shadow-ambient cursor-pointer',
            {
                'p-4': padding === 'sm',
                'p-6': padding === 'md',
                'p-8': padding === 'lg',
                'p-0': padding === 'none',
            },
        ]"
    >
        <slot />
    </div>
</template>
