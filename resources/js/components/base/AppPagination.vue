<script setup lang="ts">
import { computed } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import type { PaginatedMeta } from '@/composables/usePaginated'

interface Props {
    meta: PaginatedMeta
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'page-change': [page: number]
}>()

const pages = computed<number[]>(() => {
    const { current_page, last_page } = props.meta
    if (last_page <= 7) {
        return Array.from({ length: last_page }, (_, i) => i + 1)
    }
    const near = new Set([1, last_page, current_page, current_page - 1, current_page + 1])
    return Array.from(near)
        .filter((p) => p >= 1 && p <= last_page)
        .sort((a, b) => a - b)
})

function shouldShowEllipsis(pages: number[], index: number): boolean {
    if (index === 0) return false
    return pages[index] - pages[index - 1] > 1
}
</script>

<template>
    <nav
        class="flex items-center justify-between gap-4 flex-wrap"
        aria-label="Paginacion"
    >
        <!-- Summary text -->
        <p class="text-xs text-on-surface-variant">
            <span v-if="meta.from !== null && meta.to !== null">
                {{ meta.from }}&ndash;{{ meta.to }} de {{ meta.total }}
            </span>
            <span v-else>
                {{ meta.total }} resultados
            </span>
        </p>

        <!-- Page buttons -->
        <div class="flex items-center gap-1">
            <!-- Previous -->
            <button
                type="button"
                class="btn-icon"
                :disabled="meta.current_page <= 1"
                aria-label="Pagina anterior"
                @click="emit('page-change', meta.current_page - 1)"
            >
                <ChevronLeft :size="16" />
            </button>

            <template v-for="(page, index) in pages" :key="page">
                <!-- Ellipsis gap -->
                <span
                    v-if="shouldShowEllipsis(pages, index)"
                    class="w-8 h-8 flex items-center justify-center text-xs text-on-surface-variant"
                    aria-hidden="true"
                >
                    &hellip;
                </span>

                <button
                    type="button"
                    :class="[
                        'w-8 h-8 flex items-center justify-center rounded-lg text-xs font-medium transition-colors',
                        page === meta.current_page
                            ? 'bg-primary text-on-primary'
                            : 'text-on-surface-variant hover:bg-surface-high dark:hover:bg-surface-mid',
                    ]"
                    :aria-current="page === meta.current_page ? 'page' : undefined"
                    @click="emit('page-change', page)"
                >
                    {{ page }}
                </button>
            </template>

            <!-- Next -->
            <button
                type="button"
                class="btn-icon"
                :disabled="meta.current_page >= meta.last_page"
                aria-label="Pagina siguiente"
                @click="emit('page-change', meta.current_page + 1)"
            >
                <ChevronRight :size="16" />
            </button>
        </div>
    </nav>
</template>
