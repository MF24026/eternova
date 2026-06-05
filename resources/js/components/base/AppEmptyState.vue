<script setup lang="ts">
interface Props {
    title: string
    description?: string
}

withDefaults(defineProps<Props>(), {
    description: '',
})
</script>

<template>
    <div class="flex flex-col items-center justify-center py-16 px-6 text-center">
        <!-- Illustration slot -->
        <div v-if="$slots.illustration" class="mb-6 text-on-surface-variant opacity-50">
            <slot name="illustration" />
        </div>

        <!-- Default flower petal illustration -->
        <div
            v-else
            class="w-16 h-16 rounded-full mb-6 flex items-center justify-center"
            style="background: var(--gradient-soft)"
        >
            <svg
                viewBox="0 0 40 40"
                class="w-8 h-8 text-primary"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                aria-hidden="true"
            >
                <circle cx="20" cy="20" r="4" fill="currentColor" opacity="0.5" />
                <ellipse cx="20" cy="10" rx="4" ry="7" fill="currentColor" opacity="0.3" />
                <ellipse cx="20" cy="30" rx="4" ry="7" fill="currentColor" opacity="0.3" />
                <ellipse cx="10" cy="20" rx="7" ry="4" fill="currentColor" opacity="0.3" />
                <ellipse cx="30" cy="20" rx="7" ry="4" fill="currentColor" opacity="0.3" />
            </svg>
        </div>

        <h3 class="font-serif text-lg text-on-surface tracking-tighter mb-2">
            {{ title }}
        </h3>

        <p v-if="description" class="text-sm text-on-surface-variant max-w-xs leading-relaxed mb-6">
            {{ description }}
        </p>

        <!-- CTA slot -->
        <div v-if="$slots.cta" class="mt-2">
            <slot name="cta" />
        </div>
    </div>
</template>
