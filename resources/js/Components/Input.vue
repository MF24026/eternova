<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    label: String,
    type: { type: String, default: 'text' },
    placeholder: String,
    error: String,
    required: Boolean,
    disabled: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const inputClasses = computed(() => [
    'w-full rounded-xl px-4 py-3 text-sm text-on-surface placeholder-on-surface-variant/50',
    'bg-surface-container-low transition-colors duration-200',
    'focus:bg-surface-container-highest focus:outline-none focus:ring-0',
    props.error && 'ring-1 ring-error',
    props.disabled && 'opacity-50 cursor-not-allowed',
]);
</script>

<template>
    <div>
        <label
            v-if="label"
            class="label-gilt mb-1.5 block text-[11px] font-semibold text-on-surface-variant"
        >
            {{ label }}
            <span v-if="required" class="text-error">*</span>
        </label>
        <input
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            :required="required"
            :disabled="disabled"
            :class="inputClasses"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <p v-if="error" class="mt-1 text-xs text-error">{{ error }}</p>
    </div>
</template>
