<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { Plus, Trash2, GripVertical } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppButton from '@/components/base/AppButton.vue'
import type { ProductOptionInput } from '@/types/domain/Product'

interface VariantRow {
    key: string
    options: Record<string, string>
    sku: string
    price_cents: number | null
    image_url: string | null
}

const props = withDefaults(defineProps<{
    /** Option definitions driving the matrix */
    options: ProductOptionInput[]
    /** Base price in cents — used as default when a variant has no price override */
    basePriceCents: number
    /** Existing variant rows to pre-populate (edit mode) */
    modelValue?: VariantRow[]
}>(), {
    modelValue: () => [],
})

const emit = defineEmits<{
    'update:modelValue': [variants: VariantRow[]]
    'update:options': [options: ProductOptionInput[]]
}>()

// ── Local option state ──────────────────────────────────────────────────────
interface LocalOption {
    name: string
    values: string[]
    newValue: string
}

const localOptions = ref<LocalOption[]>(
    props.options.map((o) => ({ name: o.name, values: [...o.values], newValue: '' }))
)

function addOption(): void {
    localOptions.value.push({ name: '', values: [], newValue: '' })
}

function removeOption(index: number): void {
    localOptions.value.splice(index, 1)
    rebuildMatrix()
}

function addValue(optIndex: number): void {
    const opt = localOptions.value[optIndex]
    const trimmed = opt.newValue.trim()
    if (trimmed === '' || opt.values.includes(trimmed)) return
    opt.values.push(trimmed)
    opt.newValue = ''
    rebuildMatrix()
}

function removeValue(optIndex: number, valIndex: number): void {
    localOptions.value[optIndex].values.splice(valIndex, 1)
    rebuildMatrix()
}

// ── Matrix generation ───────────────────────────────────────────────────────
const variantRows = ref<VariantRow[]>([...props.modelValue])

function cartesianProduct(options: LocalOption[]): Array<Record<string, string>> {
    const filled = options.filter((o) => o.name.trim() !== '' && o.values.length > 0)
    if (filled.length === 0) return []

    let result: Array<Record<string, string>> = [{}]

    for (const opt of filled) {
        const append: Array<Record<string, string>> = []
        for (const existing of result) {
            for (const val of opt.values) {
                append.push({ ...existing, [opt.name]: val })
            }
        }
        result = append
    }
    return result
}

function skuFromOptions(combo: Record<string, string>): string {
    return Object.values(combo)
        .map((v) => v.toLowerCase().replace(/\s+/g, '-'))
        .join('-')
}

function rebuildMatrix(): void {
    const combos = cartesianProduct(localOptions.value)
    const existingMap = new Map(variantRows.value.map((r) => [r.key, r]))

    variantRows.value = combos.map((combo) => {
        const key = JSON.stringify(combo)
        const existing = existingMap.get(key)
        return existing ?? {
            key,
            options: combo,
            sku: skuFromOptions(combo),
            price_cents: null,
            image_url: null,
        }
    })

    // Emit updated options and variants upward
    emit('update:options', localOptions.value.map((o) => ({ name: o.name, values: o.values })))
    emitVariants()
}

function emitVariants(): void {
    emit('update:modelValue', variantRows.value)
}

watch(() => localOptions.value, rebuildMatrix, { deep: true })

// ── Computed helpers ────────────────────────────────────────────────────────
const hasOptions = computed(() => localOptions.value.some((o) => o.name.trim() !== '' && o.values.length > 0))

function displayPrice(row: VariantRow): string {
    const cents = row.price_cents ?? props.basePriceCents
    return (cents / 100).toFixed(2)
}

function updateRowPrice(row: VariantRow, value: string): void {
    const parsed = parseFloat(value)
    row.price_cents = isNaN(parsed) ? null : Math.round(parsed * 100)
    emitVariants()
}

function updateRowSku(row: VariantRow, value: string): void {
    row.sku = value
    emitVariants()
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Option definitions -->
        <div
            v-for="(opt, optIdx) in localOptions"
            :key="optIdx"
            class="rounded-xl p-4"
            style="background: var(--surface-low)"
        >
            <div class="flex items-center gap-2 mb-3">
                <GripVertical :size="14" class="text-on-surface-variant/50 shrink-0" />
                <AppInput
                    :model-value="opt.name"
                    placeholder="Nombre de opcion (ej. Color)"
                    class="flex-1"
                    @update:model-value="(v) => { opt.name = String(v); rebuildMatrix() }"
                />
                <button
                    type="button"
                    class="btn-icon w-8 h-8 shrink-0"
                    aria-label="Eliminar opcion"
                    @click="removeOption(optIdx)"
                >
                    <Trash2 :size="13" />
                </button>
            </div>

            <!-- Values chips -->
            <div class="flex flex-wrap gap-2 mb-2 pl-6">
                <span
                    v-for="(val, valIdx) in opt.values"
                    :key="valIdx"
                    class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium"
                    style="background: var(--primary-container); color: var(--on-primary-container)"
                >
                    {{ val }}
                    <button
                        type="button"
                        class="ml-1 opacity-70 hover:opacity-100"
                        :aria-label="`Eliminar valor ${val}`"
                        @click="removeValue(optIdx, valIdx)"
                    >
                        &times;
                    </button>
                </span>
            </div>

            <!-- Add value input -->
            <div class="flex items-center gap-2 pl-6">
                <AppInput
                    v-model="opt.newValue"
                    :placeholder="`Agregar valor (ej. ${optIdx === 0 ? 'Rojo' : 'S'})`"
                    class="flex-1"
                    @keydown.enter.prevent="addValue(optIdx)"
                />
                <AppButton
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="addValue(optIdx)"
                >
                    Agregar
                </AppButton>
            </div>
        </div>

        <AppButton
            type="button"
            variant="secondary"
            :icon="Plus"
            size="sm"
            class="self-start"
            @click="addOption"
        >
            Agregar opcion
        </AppButton>

        <!-- Variant matrix table -->
        <div v-if="hasOptions && variantRows.length > 0" class="overflow-x-auto rounded-xl" style="background: var(--surface-low)">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom: 1px solid var(--outline-variant)">
                        <th
                            v-for="opt in localOptions.filter(o => o.name.trim() !== '' && o.values.length > 0)"
                            :key="opt.name"
                            class="text-left px-4 py-3 text-on-surface-variant font-medium"
                        >
                            {{ opt.name }}
                        </th>
                        <th class="text-left px-4 py-3 text-on-surface-variant font-medium">SKU</th>
                        <th class="text-left px-4 py-3 text-on-surface-variant font-medium">Precio</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in variantRows"
                        :key="row.key"
                        style="border-bottom: 1px solid var(--outline-variant)"
                        class="last:border-b-0"
                    >
                        <td
                            v-for="(val, optName) in row.options"
                            :key="optName"
                            class="px-4 py-2 text-on-surface"
                        >
                            {{ val }}
                        </td>
                        <td class="px-4 py-2">
                            <input
                                :value="row.sku"
                                type="text"
                                class="w-full bg-transparent text-on-surface focus:outline-none"
                                @input="updateRowSku(row, ($event.target as HTMLInputElement).value)"
                            />
                        </td>
                        <td class="px-4 py-2">
                            <input
                                :value="displayPrice(row)"
                                type="number"
                                step="0.01"
                                min="0"
                                class="w-24 bg-transparent text-on-surface focus:outline-none"
                                @change="updateRowPrice(row, ($event.target as HTMLInputElement).value)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else-if="localOptions.length > 0 && !hasOptions" class="text-sm text-on-surface-variant pl-2">
            Agrega valores a las opciones para ver la matrix de variantes.
        </p>
    </div>
</template>
