<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Search, Filter, Minus, Plus } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'

onMounted(() => { document.title = 'Inventario — Eternova' })

interface InventoryItem {
    id: string; name: string; cat: string; sku: string; stock: number; min: number; price: number
}
interface AdjustContext { item: InventoryItem; type: 'in' | 'out' }

const items = ref<InventoryItem[]>([
    { id: 'p1', name: 'Rosa Eterna Carmesi', cat: 'Rosas', sku: 'CC-P1', stock: 3, min: 5, price: 65.00 },
    { id: 'p2', name: 'Bouquet Aurora', cat: 'Rosas', sku: 'CC-P2', stock: 12, min: 5, price: 89.00 },
    { id: 'p3', name: 'Peluche Olivia', cat: 'Peluches', sku: 'CC-P3', stock: 8, min: 5, price: 32.00 },
    { id: 'p4', name: 'Cartera Petalia', cat: 'Carteras', sku: 'CC-P4', stock: 6, min: 5, price: 78.00 },
    { id: 'p5', name: 'Llavero Camelia', cat: 'Llaveros', sku: 'CC-P5', stock: 24, min: 10, price: 14.00 },
    { id: 'p6', name: 'Rosa Eterna Marfil', cat: 'Rosas', sku: 'CC-P6', stock: 2, min: 5, price: 65.00 },
    { id: 'p7', name: 'Peluche Lavanda', cat: 'Peluches', sku: 'CC-P7', stock: 5, min: 5, price: 36.00 },
    { id: 'p8', name: 'Cartera Aurelia', cat: 'Carteras', sku: 'CC-P8', stock: 4, min: 5, price: 92.00 },
])

const searchQuery = ref('')
const sortKey = ref<'name' | 'stock' | 'price'>('name')
const adjustCtx = ref<AdjustContext | null>(null)
const adjustQty = ref(1)
const adjustNote = ref('')
const adjustReason = ref('')

const sorted = computed(() => {
    const filtered = searchQuery.value
        ? items.value.filter(i => i.name.toLowerCase().includes(searchQuery.value.toLowerCase()) || i.sku.toLowerCase().includes(searchQuery.value.toLowerCase()))
        : [...items.value]
    return filtered.sort((a, b) => sortKey.value === 'stock' ? a.stock - b.stock : sortKey.value === 'price' ? b.price - a.price : a.name.localeCompare(b.name))
})

function openAdjust(item: InventoryItem, type: 'in' | 'out') {
    adjustCtx.value = { item, type }
    adjustQty.value = type === 'in' ? 10 : 1
    adjustNote.value = ''
    adjustReason.value = type === 'in' ? 'Compra a proveedor' : 'Venta sin POS'
}
function confirmAdjust() {
    if (!adjustCtx.value) return
    const { item, type } = adjustCtx.value
    const target = items.value.find(i => i.id === item.id)
    if (target) target.stock = Math.max(0, target.stock + (type === 'in' ? adjustQty.value : -adjustQty.value))
    adjustCtx.value = null
}

const inReasons = ['Compra a proveedor', 'Devolucion de cliente', 'Ajuste manual']
const outReasons = ['Venta sin POS', 'Producto danado', 'Regalo / muestra', 'Ajuste manual']
</script>

<template>
    <div class="h-[calc(100vh-120px)] overflow-hidden">
        <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
            <!-- Toolbar -->
            <div class="flex flex-wrap gap-3 mb-4">
                <div class="relative flex-1 min-w-[200px]">
                    <AppInput v-model="searchQuery" placeholder="Buscar producto o SKU...">
                        <template #icon><Search :size="16" /></template>
                    </AppInput>
                </div>
                <div class="tabs">
                    <button :class="['tab', { active: sortKey === 'name' }]" @click="sortKey = 'name'">Nombre</button>
                    <button :class="['tab', { active: sortKey === 'stock' }]" @click="sortKey = 'stock'">Stock</button>
                    <button :class="['tab', { active: sortKey === 'price' }]" @click="sortKey = 'price'">Precio</button>
                </div>
                <button class="btn btn-tertiary">
                    <Filter :size="14" class="mr-1.5" /> Filtros
                </button>
            </div>

            <!-- Desktop header -->
            <div class="inv-header">
                <div v-for="h in ['Producto', 'SKU', 'Stock', 'Min', 'Precio', 'Acciones']" :key="h" class="label" style="font-size: 10px">{{ h }}</div>
            </div>

            <div class="scroll flex-1 min-h-0">
                <div class="flex flex-col gap-1.5">
                    <div
                        v-for="it in sorted"
                        :key="it.id"
                        class="inv-row rounded-xl p-3"
                        style="background: var(--surface-low)"
                    >
                        <!-- Product info -->
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                            <div class="grow min-w-0">
                                <p class="serif text-base text-on-surface">{{ it.name }}</p>
                                <p class="text-xs text-on-surface-variant">{{ it.cat }}</p>
                            </div>
                        </div>
                        <!-- SKU (desktop) -->
                        <div class="inv-desktop text-xs text-on-surface-variant font-mono">{{ it.sku }}</div>
                        <!-- Stock -->
                        <div class="flex items-center gap-2">
                            <span class="text-lg font-bold" :class="it.stock <= it.min ? 'text-error' : 'text-on-surface'">{{ it.stock }}</span>
                            <AppBadge v-if="it.stock <= it.min" variant="error" size="sm">Bajo</AppBadge>
                        </div>
                        <!-- Min (desktop) -->
                        <div class="inv-desktop text-sm text-on-surface-variant">{{ it.min }}</div>
                        <!-- Price (desktop) -->
                        <div class="inv-desktop text-sm font-semibold text-primary">${{ it.price.toFixed(2) }}</div>
                        <!-- Actions -->
                        <div class="flex items-center gap-1.5">
                            <button class="btn-icon w-9 h-9 min-h-[36px]" aria-label="Salida de stock" @click="openAdjust(it, 'out')"><Minus :size="14" /></button>
                            <button class="btn-icon w-9 h-9 min-h-[36px]" aria-label="Entrada de stock" style="background: var(--primary-container); color: var(--primary-dim)" @click="openAdjust(it, 'in')"><Plus :size="14" /></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Adjust stock slideover -->
    <AppSlideover
        :model-value="!!adjustCtx"
        :title="adjustCtx?.item.name ?? ''"
        :subtitle="adjustCtx?.type === 'in' ? 'Entrada de inventario' : 'Salida de inventario'"
        @update:model-value="adjustCtx = null"
    >
        <template v-if="adjustCtx">
            <div class="flex flex-col gap-5">
                <div class="rounded-xl p-4 flex gap-4 items-center" style="background: var(--surface-low)">
                    <div class="w-14 h-14 rounded-xl" style="background: var(--gradient-soft)" />
                    <div>
                        <p class="text-xs text-on-surface-variant">Stock actual</p>
                        <p class="serif text-4xl text-primary">{{ adjustCtx.item.stock }}</p>
                    </div>
                </div>
                <div>
                    <label class="field-label">Cantidad</label>
                    <input v-model.number="adjustQty" class="field mt-1.5" type="number" min="1" />
                </div>
                <div>
                    <label class="field-label">Motivo</label>
                    <select v-model="adjustReason" class="field mt-1.5">
                        <option v-for="r in (adjustCtx.type === 'in' ? inReasons : outReasons)" :key="r">{{ r }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">Nota</label>
                    <textarea v-model="adjustNote" class="field mt-1.5" rows="3" placeholder="Opcional..." />
                </div>
            </div>
        </template>
        <template #footer>
            <div class="flex gap-2.5">
                <AppButton variant="secondary" class="flex-1 justify-center" @click="adjustCtx = null">Cancelar</AppButton>
                <AppButton class="flex-1 justify-center" @click="confirmAdjust">Confirmar</AppButton>
            </div>
        </template>
    </AppSlideover>
</template>

<style scoped>
.inv-header {
    display: none;
    grid-template-columns: 1.8fr 100px 90px 70px 100px 120px;
    gap: 16px;
    padding: 8px 12px;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}
.inv-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.inv-row > :first-child { flex: 1; min-width: 180px; }
.inv-desktop { display: none; }
@media (min-width: 1024px) {
    .inv-header { display: grid; }
    .inv-row { display: grid; grid-template-columns: 1.8fr 100px 90px 70px 100px 120px; flex-wrap: nowrap; }
    .inv-row > :first-child { flex: unset; min-width: unset; }
    .inv-desktop { display: flex; align-items: center; }
}
</style>
