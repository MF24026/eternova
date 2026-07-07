<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed } from 'vue'
import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-vue-next'
import { useMediaQuery } from '@/composables/useMediaQuery'

export interface TableColumn<Row = Record<string, unknown>> {
    key: string
    label: string
    sortable?: boolean
    align?: 'left' | 'center' | 'right'
    width?: string
    /** Hide this column in the mobile stacked-card layout (< md). */
    hideOnMobile?: boolean
    render?: (row: Row) => string
}

interface Props {
    columns: TableColumn<T>[]
    rows: T[]
    sortKey?: string
    sortDir?: 'asc' | 'desc'
    loading?: boolean
    rowKey?: string
}

const props = withDefaults(defineProps<Props>(), {
    sortKey: '',
    sortDir: 'asc',
    loading: false,
    rowKey: 'id',
})

const emit = defineEmits<{
    sort: [key: string, dir: 'asc' | 'desc']
    'row-click': [row: T]
}>()

function handleSort(col: TableColumn<T>): void {
    if (!col.sortable) return
    const nextDir = props.sortKey === col.key && props.sortDir === 'asc' ? 'desc' : 'asc'
    emit('sort', col.key, nextDir)
}

const alignClass: Record<string, string> = {
    left: 'text-left',
    center: 'text-center',
    right: 'text-right',
}

// Columns shown in the mobile stacked-card layout. Consumers can opt a noisy
// column out with `hideOnMobile` so the card stays scannable.
const mobileColumns = computed(() => props.columns.filter((col) => !col.hideOnMobile))

// Render only the layout that applies (md = 768px). Rendering both and hiding one
// with CSS duplicated every slotted cell testid/id in the DOM.
const isDesktop = useMediaQuery('(min-width: 768px)')
</script>

<template>
    <div class="w-full overflow-x-auto rounded-xl bg-surface-lowest dark:bg-surface-low shadow-[var(--shadow-ambient)]">
        <!-- Desktop / tablet: classic table (md and up) -->
        <table v-if="isDesktop" class="w-full text-sm" data-testid="app-table-desktop">
            <thead>
                <tr class="bg-surface-low dark:bg-surface-mid">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :class="[
                            'px-4 py-3 text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant',
                            alignClass[col.align ?? 'left'],
                            col.sortable ? 'cursor-pointer select-none hover:text-on-surface transition-colors' : '',
                        ]"
                        :style="col.width ? { width: col.width } : {}"
                        :aria-sort="sortKey === col.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : undefined"
                        @click="handleSort(col)"
                    >
                        <span class="inline-flex items-center gap-1">
                            {{ col.label }}
                            <span v-if="col.sortable" class="text-on-surface-variant" aria-hidden="true">
                                <ChevronUp
                                    v-if="sortKey === col.key && sortDir === 'asc'"
                                    :size="12"
                                />
                                <ChevronDown
                                    v-else-if="sortKey === col.key && sortDir === 'desc'"
                                    :size="12"
                                />
                                <ChevronsUpDown v-else :size="12" class="opacity-40" />
                            </span>
                        </span>
                    </th>
                </tr>
            </thead>

            <tbody>
                <!-- Loading skeleton rows -->
                <template v-if="loading">
                    <tr
                        v-for="n in 5"
                        :key="`skeleton-${n}`"
                        class="odd:bg-surface-low/40 dark:odd:bg-surface-mid/30"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-3"
                        >
                            <div class="h-4 rounded bg-surface-high dark:bg-surface-mid animate-pulse" />
                        </td>
                    </tr>
                </template>

                <!-- Data rows -->
                <template v-else>
                    <tr
                        v-for="row in rows"
                        :key="String(row[rowKey])"
                        class="odd:bg-surface-low/40 dark:odd:bg-surface-mid/30 hover:bg-surface-mid dark:hover:bg-surface-high transition-colors cursor-pointer"
                        @click="emit('row-click', row)"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            :class="[
                                'px-4 py-3 text-on-surface',
                                alignClass[col.align ?? 'left'],
                            ]"
                        >
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ col.render ? col.render(row) : String(row[col.key] ?? '') }}
                            </slot>
                        </td>
                    </tr>

                    <!-- Empty state -->
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="columns.length"
                            class="px-4 py-12 text-center text-sm text-on-surface-variant"
                        >
                            <slot name="empty">
                                Sin resultados
                            </slot>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>

        <!-- Mobile: stacked cards (< md). Reuses the same cell slots so consumers
             need no changes; each row becomes a label:value card. -->
        <div v-else class="flex flex-col gap-2 p-2" data-testid="app-table-mobile-cards">
            <!-- Loading skeleton cards -->
            <template v-if="loading">
                <div
                    v-for="n in 5"
                    :key="`m-skeleton-${n}`"
                    class="rounded-lg bg-surface-low/50 dark:bg-surface-mid/40 p-3"
                >
                    <div
                        v-for="col in mobileColumns"
                        :key="col.key"
                        class="flex items-center justify-between gap-3 py-1"
                    >
                        <div class="h-3 w-16 rounded bg-surface-high dark:bg-surface-mid animate-pulse" />
                        <div class="h-3 w-24 rounded bg-surface-high dark:bg-surface-mid animate-pulse" />
                    </div>
                </div>
            </template>

            <!-- Data cards -->
            <template v-else>
                <div
                    v-for="row in rows"
                    :key="`m-${String(row[rowKey])}`"
                    class="rounded-lg bg-surface-low/50 dark:bg-surface-mid/40 hover:bg-surface-mid dark:hover:bg-surface-high transition-colors cursor-pointer p-3 flex flex-col gap-1.5"
                    @click="emit('row-click', row)"
                >
                    <div
                        v-for="col in mobileColumns"
                        :key="col.key"
                        class="flex items-start justify-between gap-3"
                    >
                        <span class="shrink-0 text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                            {{ col.label }}
                        </span>
                        <span class="min-w-0 text-sm text-on-surface text-right">
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ col.render ? col.render(row) : String(row[col.key] ?? '') }}
                            </slot>
                        </span>
                    </div>
                </div>

                <!-- Empty state -->
                <div
                    v-if="rows.length === 0"
                    class="px-4 py-12 text-center text-sm text-on-surface-variant"
                >
                    <slot name="empty">
                        Sin resultados
                    </slot>
                </div>
            </template>
        </div>
    </div>
</template>
