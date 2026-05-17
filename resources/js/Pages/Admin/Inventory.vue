<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import Slideover from '@/Components/Slideover.vue'
import { Search, Filter, Minus, Plus } from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

interface InventoryItem {
    id: string
    name: string
    kind: string
    tone: string
    cat: string
    sku: string
    stock: number
    min: number
    price: number
}

interface AdjustContext {
    item: InventoryItem
    type: 'in' | 'out'
}

const items = ref<InventoryItem[]>([
    { id: 'p1', name: 'Rosa Eterna Carmesí', kind: 'rose', tone: 'rose', cat: 'Rosas', sku: 'CC-P1', stock: 3, min: 5, price: 65.00 },
    { id: 'p2', name: 'Bouquet Aurora', kind: 'rose', tone: 'lilac', cat: 'Rosas', sku: 'CC-P2', stock: 12, min: 5, price: 89.00 },
    { id: 'p3', name: 'Peluche Olivia', kind: 'peluche', tone: 'rose', cat: 'Peluches', sku: 'CC-P3', stock: 8, min: 5, price: 32.00 },
    { id: 'p4', name: 'Cartera Petalia', kind: 'bolso', tone: 'rose', cat: 'Carteras', sku: 'CC-P4', stock: 6, min: 5, price: 78.00 },
    { id: 'p5', name: 'Llavero Camelia', kind: 'llavero', tone: 'cream', cat: 'Llaveros', sku: 'CC-P5', stock: 24, min: 10, price: 14.00 },
    { id: 'p6', name: 'Rosa Eterna Marfil', kind: 'rose', tone: 'cream', cat: 'Rosas', sku: 'CC-P6', stock: 2, min: 5, price: 65.00 },
    { id: 'p7', name: 'Peluche Lavanda', kind: 'peluche', tone: 'lilac', cat: 'Peluches', sku: 'CC-P7', stock: 5, min: 5, price: 36.00 },
    { id: 'p8', name: 'Cartera Aurelia', kind: 'bolso', tone: 'lilac', cat: 'Carteras', sku: 'CC-P8', stock: 4, min: 5, price: 92.00 },
])

const searchQuery = ref('')
const sortKey = ref<'name' | 'stock' | 'price'>('name')
const adjustCtx = ref<AdjustContext | null>(null)
const adjustQty = ref(1)
const adjustNote = ref('')
const adjustReason = ref('')

const sorted = computed(() => {
    const filtered = searchQuery.value
        ? items.value.filter(i =>
            i.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
            i.sku.toLowerCase().includes(searchQuery.value.toLowerCase())
          )
        : [...items.value]

    return filtered.sort((a, b) => {
        if (sortKey.value === 'stock') return a.stock - b.stock
        if (sortKey.value === 'price') return b.price - a.price
        return a.name.localeCompare(b.name)
    })
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
    if (target) {
        const delta = type === 'in' ? adjustQty.value : -adjustQty.value
        target.stock = Math.max(0, target.stock + delta)
    }
    adjustCtx.value = null
}

const inReasons = ['Compra a proveedor', 'Devolución de cliente', 'Ajuste manual']
const outReasons = ['Venta sin POS', 'Producto dañado', 'Regalo / muestra', 'Ajuste manual']
</script>

<template>
    <AdminLayout title="Inventario" breadcrumb="Stock">
        <div style="height: calc(100vh - 120px); overflow: hidden">
            <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
                <!-- Toolbar -->
                <div class="row" style="margin-bottom: 16px; gap: 12px; flex-wrap: wrap">
                    <div style="position: relative; flex: 1; min-width: 200px">
                        <input
                            v-model="searchQuery"
                            class="field"
                            placeholder="Buscar producto o SKU…"
                            style="padding-left: 44px"
                        />
                        <Search :size="18" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)"/>
                    </div>
                    <div class="tabs">
                        <button :class="['tab', { active: sortKey === 'name' }]" @click="sortKey = 'name'">Nombre</button>
                        <button :class="['tab', { active: sortKey === 'stock' }]" @click="sortKey = 'stock'">Stock</button>
                        <button :class="['tab', { active: sortKey === 'price' }]" @click="sortKey = 'price'">Precio</button>
                    </div>
                    <button class="btn btn-tertiary">
                        <Filter :size="14" style="margin-right: 6px"/> Filtros
                    </button>
                </div>

                <!-- Table header (desktop) -->
                <div class="inv-header">
                    <div v-for="h in ['Producto', 'SKU', 'Stock', 'Mínimo', 'Precio', 'Acciones']" :key="h" class="label" style="font-size: 10px">
                        {{ h }}
                    </div>
                </div>

                <div class="scroll" style="flex: 1; min-height: 0">
                    <div class="stack" style="gap: 6px">
                        <div
                            v-for="it in sorted"
                            :key="it.id"
                            class="inv-row"
                            :style="{ background: 'var(--surface-low)', borderRadius: 'var(--r-lg)', padding: '12px 14px' }"
                        >
                            <!-- Product info -->
                            <div class="row" style="gap: 12px">
                                <div style="width: 44px; height: 44px; flex-shrink: 0">
                                    <Surrogate :kind="it.kind" :tone="it.tone" style="width: 100%; height: 100%"/>
                                </div>
                                <div class="grow" style="min-width: 0">
                                    <div class="serif" style="font-size: 15px">{{ it.name }}</div>
                                    <div style="font-size: 11px; color: var(--on-surface-variant)">{{ it.cat }}</div>
                                </div>
                            </div>

                            <!-- SKU (desktop) -->
                            <div class="inv-desktop" style="font-size: 12px; color: var(--on-surface-variant); font-family: monospace">
                                {{ it.sku }}
                            </div>

                            <!-- Stock -->
                            <div style="display: flex; align-items: center; gap: 8px">
                                <span :style="{
                                    fontSize: '18px', fontWeight: 700,
                                    color: it.stock <= it.min ? 'var(--error)' : 'var(--on-surface)',
                                }">
                                    {{ it.stock }}
                                </span>
                                <span v-if="it.stock <= it.min" class="bloom bloom-error" style="font-size: 10px">Bajo</span>
                            </div>

                            <!-- Min (desktop) -->
                            <div class="inv-desktop" style="font-size: 13px; color: var(--on-surface-variant)">{{ it.min }}</div>

                            <!-- Price (desktop) -->
                            <div class="inv-desktop" style="font-size: 14px; color: var(--primary); font-weight: 600">
                                ${{ it.price.toFixed(2) }}
                            </div>

                            <!-- Actions -->
                            <div class="row" style="gap: 6px">
                                <button
                                    class="btn-icon"
                                    style="width: 36px; height: 36px; min-height: 36px"
                                    @click="openAdjust(it, 'out')"
                                >
                                    <Minus :size="14"/>
                                </button>
                                <button
                                    class="btn-icon"
                                    style="width: 36px; height: 36px; min-height: 36px; background: var(--primary-container); color: var(--primary-dim)"
                                    @click="openAdjust(it, 'in')"
                                >
                                    <Plus :size="14"/>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Adjust stock slideover -->
        <Slideover
            :open="!!adjustCtx"
            :title="adjustCtx?.item.name ?? ''"
            :subtitle="adjustCtx?.type === 'in' ? 'Entrada de inventario' : 'Salida de inventario'"
            @close="adjustCtx = null"
        >
            <template v-if="adjustCtx">
                <div class="stack" style="gap: 18px">
                    <div class="card" style="background: var(--surface-low); padding: 16px; display: flex; gap: 14px; align-items: center">
                        <div style="width: 56px; height: 56px">
                            <Surrogate :kind="adjustCtx.item.kind" :tone="adjustCtx.item.tone" style="width: 100%; height: 100%"/>
                        </div>
                        <div class="grow">
                            <div style="font-size: 11px; color: var(--on-surface-variant)">Stock actual</div>
                            <div class="serif" style="font-size: 30px; color: var(--primary)">{{ adjustCtx.item.stock }}</div>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Cantidad</label>
                        <input v-model.number="adjustQty" class="field" type="number" min="1"/>
                    </div>
                    <div>
                        <label class="field-label">Motivo</label>
                        <select v-model="adjustReason" class="field">
                            <option v-for="r in (adjustCtx.type === 'in' ? inReasons : outReasons)" :key="r">{{ r }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label">Nota</label>
                        <textarea v-model="adjustNote" class="field" rows="3" placeholder="Opcional…"/>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="row" style="gap: 10px">
                    <button class="btn btn-tertiary" style="flex: 1; justify-content: center" @click="adjustCtx = null">Cancelar</button>
                    <button class="btn btn-primary" style="flex: 1; justify-content: center" @click="confirmAdjust">Confirmar</button>
                </div>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.inv-header {
    display: none;
    grid-template-columns: 1.8fr 100px 90px 90px 110px 140px;
    gap: 16px;
    padding: 8px 14px;
    color: var(--on-surface-variant);
    margin-bottom: 4px;
}

.inv-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.inv-row > :first-child {
    flex: 1;
    min-width: 180px;
}

.inv-desktop {
    display: none;
}

@media (min-width: 1024px) {
    .inv-header {
        display: grid;
    }
    .inv-row {
        display: grid;
        grid-template-columns: 1.8fr 100px 90px 90px 110px 140px;
        gap: 16px;
        flex-wrap: nowrap;
    }
    .inv-row > :first-child {
        flex: unset;
        min-width: unset;
    }
    .inv-desktop {
        display: flex;
        align-items: center;
    }
}
</style>
