<script setup lang="ts">
/**
 * Renders per-option pickers (e.g. "Color", "Talla") derived from the variant
 * matrix and resolves the selected combination to a concrete variant.
 *
 * Algorithm:
 * 1. Collect all distinct option names from variants[].options.
 * 2. For each option name, collect the distinct values that appear.
 * 3. Track one selected value per option name in `selectedOptions`.
 * 4. The resolved variant is the first variant whose options exactly match
 *    every selectedOptions entry.
 *
 * The parent receives the resolved variant (or null when no combination matches)
 * via the `update:resolved` emit so it can update the displayed price + stock.
 *
 * S2-E5 note: the resolved variant is also emitted so the cart button in
 * ProductDetailPage can pass it to the cart store once S2-E5 is implemented.
 */
import { ref, computed, watch, onMounted } from 'vue'
import type { StorefrontVariant } from '@/types/domain/Storefront'

interface Props {
    variants: StorefrontVariant[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:resolved': [variant: StorefrontVariant | null]
}>()

// Derive the list of distinct option names preserving insertion order.
const optionNames = computed((): string[] => {
    const seen = new Set<string>()
    const names: string[] = []

    for (const variant of props.variants) {
        for (const key of Object.keys(variant.options)) {
            if (!seen.has(key)) {
                seen.add(key)
                names.push(key)
            }
        }
    }

    return names
})

// For each option name, the ordered set of distinct values.
const optionValues = computed((): Record<string, string[]> => {
    const map: Record<string, Set<string>> = {}

    for (const variant of props.variants) {
        for (const [key, value] of Object.entries(variant.options)) {
            if (!map[key]) map[key] = new Set<string>()
            map[key].add(value)
        }
    }

    const result: Record<string, string[]> = {}
    for (const [key, set] of Object.entries(map)) {
        result[key] = [...set]
    }

    return result
})

// One selected value per option name — initialised to the first available value.
const selectedOptions = ref<Record<string, string>>({})

function initializeDefaults(): void {
    const defaults: Record<string, string> = {}
    for (const name of optionNames.value) {
        const values = optionValues.value[name]
        if (values && values.length > 0) {
            defaults[name] = values[0]
        }
    }
    selectedOptions.value = defaults
}

onMounted(initializeDefaults)
watch(() => props.variants, initializeDefaults)

// The resolved variant: the first variant whose options are an exact match.
const resolvedVariant = computed((): StorefrontVariant | null => {
    if (Object.keys(selectedOptions.value).length === 0) return null

    return (
        props.variants.find((v) =>
            optionNames.value.every((name) => v.options[name] === selectedOptions.value[name]),
        ) ?? null
    )
})

// Notify the parent whenever the resolved variant changes.
watch(resolvedVariant, (v) => emit('update:resolved', v), { immediate: true })

// Returns true when a specific value is unavailable given the currently selected
// options for every OTHER option name.
function isValueUnavailable(optionName: string, value: string): boolean {
    return !props.variants.some((v) => {
        if (v.options[optionName] !== value) return false

        return optionNames.value
            .filter((n) => n !== optionName)
            .every((n) => !selectedOptions.value[n] || v.options[n] === selectedOptions.value[n])
    })
}
</script>

<template>
    <div class="space-y-4">
        <div
            v-for="optionName in optionNames"
            :key="optionName"
        >
            <p class="label-gilt mb-2">{{ optionName }}</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="value in optionValues[optionName]"
                    :key="value"
                    type="button"
                    class="px-4 py-2 rounded-full text-sm font-semibold transition-all duration-200 focus-visible:ring-2 focus-visible:ring-offset-1 disabled:opacity-40 disabled:cursor-not-allowed"
                    :class="selectedOptions[optionName] === value
                        ? 'text-on-primary shadow-sm'
                        : 'text-on-surface-variant hover:text-on-surface'"
                    :style="selectedOptions[optionName] === value
                        ? 'background: var(--brand-primary, var(--primary))'
                        : 'background: var(--surface-high)'"
                    :aria-pressed="selectedOptions[optionName] === value"
                    :disabled="isValueUnavailable(optionName, value)"
                    @click="selectedOptions[optionName] = value"
                >
                    {{ value }}
                </button>
            </div>
        </div>
    </div>
</template>
