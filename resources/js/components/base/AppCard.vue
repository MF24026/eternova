<script setup lang="ts">
import { computed } from 'vue'

interface Props {
    padding?: 'none' | 'sm' | 'md' | 'lg'
    /** When true, adds hover + cursor-pointer interactive state */
    interactive?: boolean
    /** When false, removes the ambient shadow */
    elevated?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    padding: 'md',
    interactive: false,
    elevated: true,
})

const emit = defineEmits<{
    click: [event: MouseEvent]
}>()

const paddingClasses: Record<NonNullable<Props['padding']>, string> = {
    none: '',
    sm: 'p-4',
    md: 'p-6',
    lg: 'p-8',
}

const classes = computed(() => [
    'bg-surface-lowest rounded-xl dark:bg-surface-low',
    props.elevated ? 'shadow-[var(--shadow-ambient)]' : '',
    paddingClasses[props.padding],
    props.interactive
        ? 'cursor-pointer transition-all duration-150 hover:shadow-[var(--shadow-lifted)] hover:-translate-y-0.5 active:translate-y-0'
        : '',
])
</script>

<template>
    <div
        :class="classes"
        v-bind="interactive ? { role: 'button', tabindex: 0 } : {}"
        @click="interactive ? emit('click', $event) : undefined"
        @keydown.enter="interactive ? emit('click', $event as unknown as MouseEvent) : undefined"
    >
        <slot />
    </div>
</template>
