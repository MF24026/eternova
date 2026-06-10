<script setup lang="ts">
import AppSpinner from '@/components/base/AppSpinner.vue'
import AppEmptyState from '@/components/base/AppEmptyState.vue'
import PosProductTile from './PosProductTile.vue'
import PosCategoryTabs from './PosCategoryTabs.vue'
import AppInput from '@/components/base/AppInput.vue'
import { Search, ShoppingBag } from 'lucide-vue-next'
import type { PosProduct, PosProductCategory } from '@/types/domain/POS'

interface Props {
    products: PosProduct[]
    categories: PosProductCategory[]
    activeCategory: string
    search: string
    isLoading: boolean
    formatCents: (cents: number) => string
}

defineProps<Props>()
const emit = defineEmits<{
    'update:search': [value: string]
    'update:activeCategory': [slug: string]
    'select-product': [product: PosProduct]
}>()
</script>

<template>
    <div class="card" style="padding: 20px; display: flex; flex-direction: column; min-height: 0; overflow: hidden; flex: 2">
        <!-- Search -->
        <div class="mb-4">
            <AppInput
                :model-value="search"
                placeholder="Buscar producto, SKU..."
                aria-label="Buscar producto"
                @update:model-value="emit('update:search', $event)"
            >
                <template #icon>
                    <Search :size="16" />
                </template>
            </AppInput>
        </div>

        <!-- Category tabs -->
        <div class="mb-4">
            <PosCategoryTabs
                :categories="categories"
                :model-value="activeCategory"
                @update:model-value="emit('update:activeCategory', $event)"
            />
        </div>

        <!-- Loading state -->
        <div v-if="isLoading" class="flex-1 flex items-center justify-center">
            <AppSpinner />
        </div>

        <!-- Empty state -->
        <AppEmptyState
            v-else-if="products.length === 0"
            title="Sin productos"
            description="No se encontraron productos para los filtros seleccionados."
        >
            <template #illustration>
                <ShoppingBag :size="40" class="text-on-surface-variant opacity-40" />
            </template>
        </AppEmptyState>

        <!-- Grid -->
        <div v-else class="scroll flex-1 min-h-0">
            <div class="pos-product-grid">
                <PosProductTile
                    v-for="product in products"
                    :key="product.id"
                    :product="product"
                    :format-cents="formatCents"
                    @select="emit('select-product', $event)"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.pos-product-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

@media (min-width: 640px) {
    .pos-product-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 1024px) {
    .pos-product-grid {
        grid-template-columns: repeat(auto-fill, minmax(148px, 1fr));
    }
}
</style>
