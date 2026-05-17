<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import Slideover from '@/Components/Slideover.vue'
import { Search, Layout, ClipboardList, Plus, Camera, Trash } from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

interface Product {
    id: string
    name: string
    price: number
    cat: string
    tone: string
    kind: string
    desc: string
    sku: string
}

type EditingProduct = Partial<Product> & { id?: string }

const CATEGORIES = [
    { id: 'rosas', name: 'Rosas Eternas' },
    { id: 'peluches', name: 'Peluches' },
    { id: 'carteras', name: 'Carteras' },
    { id: 'llaveros', name: 'Llaveros' },
]

const products = ref<Product[]>([
    { id: 'p1', name: 'Rosa Eterna Carmesí', price: 65.00, cat: 'rosas', tone: 'rose', kind: 'rose', desc: 'Rosa natural preservada bajo cúpula de cristal soplado. Perdura tres años.', sku: 'CC-P1' },
    { id: 'p2', name: 'Bouquet Aurora', price: 89.00, cat: 'rosas', tone: 'lilac', kind: 'rose', desc: 'Ramo de rosas eternas en tonos lila y crema con follaje preservado.', sku: 'CC-P2' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, cat: 'peluches', tone: 'rose', kind: 'peluche', desc: 'Oso de peluche de algodón orgánico, cosido a mano.', sku: 'CC-P3' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, cat: 'carteras', tone: 'rose', kind: 'bolso', desc: 'Cartera de cuero vegano con asa entretejida y broche dorado.', sku: 'CC-P4' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, cat: 'llaveros', tone: 'cream', kind: 'llavero', desc: 'Llavero artesanal con dije de flor preservada y aro bañado en oro.', sku: 'CC-P5' },
    { id: 'p6', name: 'Rosa Eterna Marfil', price: 65.00, cat: 'rosas', tone: 'cream', kind: 'rose', desc: 'Rosa preservada en tono marfil dentro de cúpula transparente.', sku: 'CC-P6' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, cat: 'peluches', tone: 'lilac', kind: 'peluche', desc: 'Conejito de peluche en tonos lavanda, relleno hipoalergénico.', sku: 'CC-P7' },
    { id: 'p8', name: 'Cartera Aurelia', price: 92.00, cat: 'carteras', tone: 'lilac', kind: 'bolso', desc: 'Cartera tipo bandolera en cuero suave con herrajes de bronce.', sku: 'CC-P8' },
])

const view = ref<'grid' | 'list'>('grid')
const activeCat = ref('all')
const searchQuery = ref('')
const editing = ref<EditingProduct | null>(null)

const filtered = computed(() => {
    let result = products.value
    if (searchQuery.value) {
        result = result.filter(p => p.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
    }
    if (activeCat.value !== 'all') {
        result = result.filter(p => p.cat === activeCat.value)
    }
    return result
})

function openNew() {
    editing.value = { name: '', price: 0, cat: 'rosas', tone: 'rose', kind: 'rose', desc: '', sku: '' }
}
</script>

<template>
    <AdminLayout title="Productos" breadcrumb="Catálogo">
        <div style="height: calc(100vh - 120px); overflow: hidden">
            <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
                <!-- Toolbar -->
                <div class="row" style="margin-bottom: 16px; gap: 12px; flex-wrap: wrap">
                    <div style="position: relative; flex: 1; min-width: 200px">
                        <input
                            v-model="searchQuery"
                            class="field"
                            placeholder="Buscar productos…"
                            style="padding-left: 44px"
                        />
                        <Search :size="18" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)"/>
                    </div>
                    <div class="scroll" style="max-width: 100%">
                        <div class="tabs" style="display: inline-flex">
                            <button :class="['tab', { active: activeCat === 'all' }]" @click="activeCat = 'all'">Todo</button>
                            <button
                                v-for="c in CATEGORIES"
                                :key="c.id"
                                :class="['tab', { active: activeCat === c.id }]"
                                @click="activeCat = c.id"
                            >
                                {{ c.name }}
                            </button>
                        </div>
                    </div>
                    <div class="tabs">
                        <button :class="['tab', { active: view === 'grid' }]" @click="view = 'grid'">
                            <Layout :size="14"/>
                        </button>
                        <button :class="['tab', { active: view === 'list' }]" @click="view = 'list'">
                            <ClipboardList :size="14"/>
                        </button>
                    </div>
                    <button class="btn btn-primary" @click="openNew">
                        <Plus :size="14" style="margin-right: 4px"/> Nuevo
                    </button>
                </div>

                <div class="scroll" style="flex: 1; min-height: 0">
                    <!-- Grid view -->
                    <div v-if="view === 'grid'" class="products-grid">
                        <button
                            v-for="p in filtered"
                            :key="p.id"
                            class="card-hover"
                            style="background: var(--surface-low); border-radius: var(--r-lg); overflow: hidden; text-align: left; display: block"
                            @click="editing = { ...p }"
                        >
                            <div style="aspect-ratio: 1/1">
                                <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%; border-radius: 0"/>
                            </div>
                            <div style="padding: 14px">
                                <div class="serif" style="font-size: 16px; margin-bottom: 4px">{{ p.name }}</div>
                                <div class="row" style="justify-content: space-between">
                                    <span style="font-size: 13px; color: var(--primary); font-weight: 700">${{ p.price.toFixed(2) }}</span>
                                    <span style="font-size: 11px; color: var(--on-surface-variant)">{{ p.sku }}</span>
                                </div>
                            </div>
                        </button>
                    </div>

                    <!-- List view -->
                    <div v-else class="stack" style="gap: 6px">
                        <button
                            v-for="p in filtered"
                            :key="p.id"
                            style="background: var(--surface-low); border-radius: var(--r-lg); padding: 14px;
                                display: flex; align-items: center; gap: 14px; text-align: left; width: 100%"
                            @click="editing = { ...p }"
                        >
                            <div style="width: 44px; height: 44px; flex-shrink: 0">
                                <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%"/>
                            </div>
                            <div class="grow">
                                <div class="serif" style="font-size: 15px">{{ p.name }}</div>
                                <div style="font-size: 11px; color: var(--on-surface-variant)">
                                    {{ p.sku }} · {{ CATEGORIES.find(c => c.id === p.cat)?.name }}
                                </div>
                            </div>
                            <div style="font-size: 14px; color: var(--primary); font-weight: 700">${{ p.price.toFixed(2) }}</div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Product edit slideover -->
        <Slideover
            :open="!!editing"
            :title="editing?.name || 'Sin nombre'"
            :subtitle="editing?.id ? 'Editar producto' : 'Nuevo producto'"
            @close="editing = null"
        >
            <template v-if="editing">
                <div class="stack" style="gap: 18px">
                    <!-- Gallery -->
                    <div>
                        <label class="field-label">Galería</label>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px">
                            <div v-if="editing.kind" style="aspect-ratio: 1/1; border-radius: var(--r-md); overflow: hidden">
                                <Surrogate :kind="editing.kind" :tone="editing.tone" style="width: 100%; height: 100%"/>
                            </div>
                            <button
                                v-for="i in 3"
                                :key="i"
                                :style="{
                                    aspectRatio: '1/1',
                                    borderRadius: 'var(--r-md)',
                                    background: 'var(--surface-low)',
                                    display: 'grid',
                                    placeItems: 'center',
                                    color: 'var(--on-surface-variant)',
                                }"
                            >
                                <Camera :size="20"/>
                            </button>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 12px">
                        <div>
                            <label class="field-label">Nombre</label>
                            <input v-model="editing.name" class="field"/>
                        </div>
                        <div>
                            <label class="field-label">SKU</label>
                            <input v-model="editing.sku" class="field"/>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div>
                            <label class="field-label">Precio</label>
                            <input :value="editing.price ? `$${Number(editing.price).toFixed(2)}` : ''" class="field" placeholder="$0.00"/>
                        </div>
                        <div>
                            <label class="field-label">Costo</label>
                            <input :value="editing.price ? `$${(Number(editing.price) * 0.4).toFixed(2)}` : ''" class="field" placeholder="$0.00"/>
                        </div>
                    </div>

                    <div>
                        <label class="field-label">Categoría</label>
                        <select v-model="editing.cat" class="field">
                            <option v-for="c in CATEGORIES" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="field-label">Descripción</label>
                        <textarea v-model="editing.desc" class="field" rows="4" placeholder="Descripción del producto…"/>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                        <div>
                            <label class="field-label">Stock inicial</label>
                            <input class="field" value="12"/>
                        </div>
                        <div>
                            <label class="field-label">Alerta de stock</label>
                            <input class="field" value="5"/>
                        </div>
                    </div>
                </div>
            </template>

            <template #footer>
                <div class="row" style="gap: 10px">
                    <button v-if="editing?.id" class="btn-icon" style="color: var(--error)">
                        <Trash :size="16"/>
                    </button>
                    <div class="grow"/>
                    <button class="btn btn-tertiary" @click="editing = null">Cancelar</button>
                    <button class="btn btn-primary" @click="editing = null">Guardar</button>
                </div>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.products-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

@media (min-width: 1024px) {
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }
}
</style>
