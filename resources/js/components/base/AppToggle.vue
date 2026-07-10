<script setup lang="ts">
// Accessible on/off switch. The knob is anchored with an explicit `left`
// offset (not the button's default text-align), so translating it can never
// push it outside the track — the overflow bug the hand-rolled toggles had.
defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<{
    modelValue: boolean
    disabled?: boolean
    /** Accessible name when the toggle has no visible <label> beside it. */
    ariaLabel?: string
}>(), {
    disabled: false,
    ariaLabel: '',
})

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()

function toggle(): void {
    if (props.disabled) return
    emit('update:modelValue', !props.modelValue)
}
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="ariaLabel || undefined"
        :disabled="disabled"
        class="relative w-11 h-6 rounded-full shrink-0 transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
        :style="{ background: modelValue ? 'var(--primary)' : 'var(--surface-high)' }"
        v-bind="$attrs"
        @click="toggle"
    >
        <span
            class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform duration-200"
            :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
        />
    </button>
</template>
