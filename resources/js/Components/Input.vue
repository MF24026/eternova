<script setup>
import { computed, useAttrs } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    label: String,
    type: { type: String, default: 'text' },
    placeholder: String,
    error: String,
    id: String,
});

defineEmits(['update:modelValue']);

const attrs = useAttrs();
const inputId = computed(() => props.id || `field-${Math.random().toString(36).slice(2, 8)}`);
</script>

<template>
    <div class="stack">
        <label v-if="label" :for="inputId" class="field-label">{{ label }}</label>
        <input
            :id="inputId"
            class="field"
            :type="type"
            :value="modelValue"
            :placeholder="placeholder"
            v-bind="attrs"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <span v-if="error" class="text-xs mt-1.5" style="color: var(--error)">{{ error }}</span>
    </div>
</template>
