<script setup lang="ts">
interface Props {
    modelValue: string
    type?: string
    label?: string
    placeholder?: string
    error?: string
    autocomplete?: string
    id?: string
}

const props = withDefaults(defineProps<Props>(), {
    type: 'text',
    label: '',
    placeholder: '',
    error: '',
    autocomplete: 'off',
    id: '',
})

defineEmits<{
    'update:modelValue': [value: string]
}>()

// Generate a stable id for the label association when none is provided.
const inputId = props.id || `input-${Math.random().toString(36).slice(2, 8)}`
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label
            v-if="props.label"
            :for="inputId"
            class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
        >
            {{ props.label }}
        </label>

        <input
            :id="inputId"
            :value="props.modelValue"
            :type="props.type"
            :placeholder="props.placeholder"
            :autocomplete="props.autocomplete"
            :class="[
                'w-full px-4 py-2.5 rounded-xl',
                'bg-surface-low text-on-surface text-sm',
                'placeholder:text-on-surface-variant/50',
                'transition-colors duration-150',
                'focus:outline-none focus:ring-2 focus:ring-primary/30',
                'dark:bg-surface-mid dark:text-on-surface',
                props.error
                    ? 'ring-2 ring-error/60'
                    : '',
            ]"
            @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        >

        <p v-if="props.error" class="text-xs text-error">
            {{ props.error }}
        </p>
    </div>
</template>
