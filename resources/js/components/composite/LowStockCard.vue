<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { PackageCheck, ChevronRight } from 'lucide-vue-next'
import AppBadge from '@/components/base/AppBadge.vue'
import { useStockBadge } from '@/composables/useStockBadge'
import type { LowStockItem } from '@/types/domain/Dashboard'

defineProps<{ items: LowStockItem[] }>()

const { forAvailable } = useStockBadge()
</script>

<template>
    <div class="card" style="padding: 24px" data-testid="low-stock-card">
        <div class="flex items-center justify-between gap-3 mb-4">
            <p class="label-gilt">Reposición necesaria</p>
            <RouterLink
                v-if="items.length > 0"
                to="/admin/inventory?low_stock=1"
                class="inline-flex items-center gap-1 text-xs font-semibold text-primary"
                data-testid="low-stock-see-all"
            >
                Ver inventario
                <ChevronRight :size="14" aria-hidden="true" />
            </RouterLink>
        </div>

        <div v-if="items.length === 0" class="flex flex-col items-center gap-2 text-center py-8">
            <span
                class="w-10 h-10 rounded-full flex items-center justify-center"
                style="background: var(--surface-low); color: var(--primary)"
            >
                <PackageCheck :size="18" aria-hidden="true" />
            </span>
            <p class="text-sm text-on-surface-variant">Todo con stock suficiente.</p>
        </div>

        <ul v-else class="flex flex-col gap-3">
            <li
                v-for="item in items"
                :key="`${item.variant_id}-${item.branch_name}`"
                class="flex items-center gap-3"
                :data-testid="`low-stock-row-${item.variant_id}`"
            >
                <div class="grow min-w-0">
                    <p class="text-sm font-semibold text-on-surface truncate">
                        {{ item.product_name }}
                        <span v-if="item.variant_label" class="text-on-surface-variant font-normal">
                            · {{ item.variant_label }}
                        </span>
                    </p>
                    <p class="text-xs text-on-surface-variant mt-0.5">{{ item.branch_name }}</p>
                </div>
                <div class="flex flex-col items-end gap-1 shrink-0">
                    <span class="text-sm font-semibold text-on-surface tabular-nums">{{ item.available }}</span>
                    <AppBadge :variant="forAvailable(item.available, item.min_stock_alert).variant" size="sm">
                        {{ forAvailable(item.available, item.min_stock_alert).label }}
                    </AppBadge>
                </div>
            </li>
        </ul>
    </div>
</template>
