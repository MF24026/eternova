<script setup lang="ts">
import { computed, useSlots } from 'vue'

interface Props {
    // Accepts number/null so forms can bind numeric or nullable fields directly;
    // the component always emits a string (use the .number v-model modifier to
    // coerce back to a number on the parent side).
    modelValue: string | number | null
    type?: string
    label?: string
    placeholder?: string
    error?: string
    helpText?: string
    autocomplete?: string
    id?: string
    required?: boolean
    disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    type: 'text',
    label: '',
    placeholder: '',
    error: '',
    helpText: '',
    autocomplete: 'off',
    id: '',
    required: false,
    disabled: false,
})

const emit = defineEmits<{
    'update:modelValue': [value: string]
    blur: [event: FocusEvent]
    focus: [event: FocusEvent]
}>()

const slots = useSlots()

// Stable id for label association
const inputId = computed(() => props.id || `input-${Math.random().toString(36).slice(2, 8)}`)

const inputClasses = computed(() => [
    'w-full px-4 py-2.5 rounded-xl',
    'bg-surface-low text-on-surface text-sm',
    'placeholder:text-on-surface-variant/50',
    'transition-colors duration-150',
    'focus:outline-none focus:ring-2 focus:ring-primary/30',
    'dark:bg-surface-mid dark:text-on-surface',
    'disabled:opacity-50 disabled:cursor-not-allowed',
    // Icon slot padding adjustment applied via slotted class
    slots.icon ? 'pl-10' : '',
    props.error ? 'ring-2 ring-error/60' : '',
])
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <label
            v-if="label"
            :for="inputId"
            class="flex items-center gap-1 text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
        >
            {{ label }}
            <span
                v-if="required"
                class="text-error text-xs"
                aria-hidden="true"
            >*</span>
        </label>

        <div class="relative">
            <!-- Leading icon slot -->
            <span
                v-if="slots.icon"
                class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                aria-hidden="true"
            >
                <slot name="icon" />
            </span>

            <input
                :id="inputId"
                :value="modelValue"
                :type="type"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :disabled="disabled"
                :aria-invalid="!!error || undefined"
                :aria-describedby="error ? `${inputId}-error` : helpText ? `${inputId}-help` : undefined"
                :class="inputClasses"
                @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
                @blur="emit('blur', $event)"
                @focus="emit('focus', $event)"
            />
        </div>

        <p
            v-if="error"
            :id="`${inputId}-error`"
            class="text-xs text-error"
            role="alert"
        >
            {{ error }}
        </p>

        <p
            v-else-if="helpText"
            :id="`${inputId}-help`"
            class="text-xs text-on-surface-variant"
        >
            {{ helpText }}
        </p>
    </div>
</template>
