<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Search, LayoutGrid, List, Plus, Trash, Camera } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'

onMounted(() => { document.title = 'Productos — Eternova' })

interface Product {
    id: string; name: string; price: number; cat: string; desc: string; sku: string
}
type EditingProduct = Partial<Product> & { id?: string }

const CATEGORIES = [
    { id: 'rosas', name: 'Rosas Eternas' },
    { id: 'peluches', name: 'Peluches' },
    { id: 'carteras', name: 'Carteras' },
    { id: 'llaveros', name: 'Llaveros' },
]

const products = ref<Product[]>([
    { id: 'p1', name: 'Rosa Eterna Carmesi', price: 65.00, cat: 'rosas', desc: 'Rosa natural preservada bajo cupula de cristal.', sku: 'CC-P1' },
    { id: 'p2', name: 'Bouquet Aurora', price: 89.00, cat: 'rosas', desc: 'Ramo de rosas eternas en tonos lila y crema.', sku: 'CC-P2' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, cat: 'peluches', desc: 'Oso de peluche de algodon organico, cosido a mano.', sku: 'CC-P3' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, cat: 'carteras', desc: 'Cartera de cuero vegano con asa entretejida.', sku: 'CC-P4' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, cat: 'llaveros', desc: 'Llavero artesanal con dije de flor preservada.', sku: 'CC-P5' },
    { id: 'p6', name: 'Rosa Eterna Marfil', price: 65.00, cat: 'rosas', desc: 'Rosa preservada en tono marfil dentro de cupula.', sku: 'CC-P6' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, cat: 'peluches', desc: 'Conejito de peluche en tonos lavanda.', sku: 'CC-P7' },
    { id: 'p8', name: 'Cartera Aurelia', price: 92.00, cat: 'carteras', desc: 'Cartera tipo bandolera en cuero suave.', sku: 'CC-P8' },
])

const view = ref<'grid' | 'list'>('grid')
const activeCat = ref('all')
const searchQuery = ref('')
const editing = ref<EditingProduct | null>(null)
const sliderOpen = computed(() => !!editing.value)

const filtered = computed(() => {
    let result = products.value
    if (searchQuery.value) result = result.filter(p => p.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
    if (activeCat.value !== 'all') result = result.filter(p => p.cat === activeCat.value)
    return result
})

function openNew() {
    editing.value = { name: '', price: 0, cat: 'rosas', desc: '', sku: '' }
}
function closeSlider() { editing.value = null }
</script>

<template>
    <div class="h-[calc(100vh-120px)] overflow-hidden">
        <div class="card" style="padding: 20px; height: 100%; display: flex; flex-direction: column">
            <!-- Toolbar -->
            <div class="flex flex-wrap gap-3 mb-4">
                <div class="relative flex-1 min-w-[200px]">
                    <AppInput v-model="searchQuery" placeholder="Buscar productos...">
                        <template #icon>
                            <Search :size="16" />
                        </template>
                    </AppInput>
                </div>
                <div class="scroll">
                    <div class="tabs inline-flex">
                        <button :class="['tab', { active: activeCat === 'all' }]" @click="activeCat = 'all'">Todo</button>
                        <button v-for="c in CATEGORIES" :key="c.id" :class="['tab', { active: activeCat === c.id }]" @click="activeCat = c.id">{{ c.name }}</button>
                    </div>
                </div>
                <div class="tabs">
                    <button :class="['tab', { active: view === 'grid' }]" @click="view = 'grid'" aria-label="Vista en cuadricula"><LayoutGrid :size="14" /></button>
                    <button :class="['tab', { active: view === 'list' }]" @click="view = 'list'" aria-label="Vista en lista"><List :size="14" /></button>
                </div>
                <AppButton :icon="Plus" @click="openNew">Nuevo</AppButton>
            </div>

            <!-- Products grid -->
            <div class="scroll flex-1 min-h-0">
                <div v-if="view === 'grid'" class="products-grid">
                    <button
                        v-for="p in filtered"
                        :key="p.id"
                        class="card-hover text-left block rounded-xl overflow-hidden"
                        style="background: var(--surface-low)"
                        @click="editing = { ...p }"
                    >
                        <div class="aspect-square" style="background: var(--gradient-soft)" />
                        <div class="p-3.5">
                            <p class="serif text-base text-on-surface mb-1">{{ p.name }}</p>
                            <div class="flex justify-between">
                                <span class="text-sm font-bold text-primary">${{ p.price.toFixed(2) }}</span>
                                <span class="text-xs text-on-surface-variant">{{ p.sku }}</span>
                            </div>
                        </div>
                    </button>
                </div>

                <div v-else class="flex flex-col gap-1.5">
                    <button
                        v-for="p in filtered"
                        :key="p.id"
                        class="flex items-center gap-3.5 p-3.5 rounded-xl text-left w-full transition-colors hover:opacity-90"
                        style="background: var(--surface-low)"
                        @click="editing = { ...p }"
                    >
                        <div class="w-11 h-11 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                        <div class="grow">
                            <p class="serif text-base text-on-surface">{{ p.name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ p.sku }} · {{ CATEGORIES.find(c => c.id === p.cat)?.name }}</p>
                        </div>
                        <span class="text-sm font-bold text-primary">${{ p.price.toFixed(2) }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit slideover -->
    <AppSlideover
        :model-value="sliderOpen"
        :title="editing?.name || 'Sin nombre'"
        :subtitle="editing?.id ? 'Editar producto' : 'Nuevo producto'"
        @update:model-value="closeSlider"
    >
        <template v-if="editing">
            <div class="flex flex-col gap-5">
                <!-- Gallery placeholder -->
                <div>
                    <p class="label mb-2">Galeria</p>
                    <div class="grid grid-cols-4 gap-2.5">
                        <div class="aspect-square rounded-xl overflow-hidden" style="background: var(--gradient-soft)" />
                        <button v-for="i in 3" :key="i" class="aspect-square rounded-xl flex items-center justify-center" style="background: var(--surface-low); color: var(--on-surface-variant)">
                            <Camera :size="18" />
                        </button>
                    </div>
                </div>
                <div class="grid grid-cols-[2fr_1fr] gap-3">
                    <AppInput v-model="(editing.name as string)" label="Nombre" />
                    <AppInput v-model="(editing.sku as string)" label="SKU" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <AppInput :model-value="editing.price ? `$${Number(editing.price).toFixed(2)}` : ''" label="Precio" placeholder="$0.00" @update:model-value="() => {}" />
                    <AppInput :model-value="editing.price ? `$${(Number(editing.price) * 0.4).toFixed(2)}` : ''" label="Costo" placeholder="$0.00" @update:model-value="() => {}" />
                </div>
                <div>
                    <label class="field-label">Categoría</label>
                    <select v-model="editing.cat" class="field mt-1.5">
                        <option v-for="c in CATEGORIES" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">Descripción</label>
                    <textarea v-model="editing.desc" class="field mt-1.5" rows="3" placeholder="Descripción del producto..." />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <AppInput model-value="12" label="Stock inicial" @update:model-value="() => {}" />
                    <AppInput model-value="5" label="Alerta de stock" @update:model-value="() => {}" />
                </div>
            </div>
        </template>

        <template #footer>
            <div class="flex items-center gap-2">
                <button v-if="editing?.id" class="btn-icon text-error" aria-label="Eliminar producto"><Trash :size="16" /></button>
                <div class="grow" />
                <AppButton variant="secondary" @click="closeSlider">Cancelar</AppButton>
                <AppButton @click="closeSlider">Guardar</AppButton>
            </div>
        </template>
    </AppSlideover>
</template>

<style scoped>
.products-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}
@media (min-width: 1024px) {
    .products-grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
}
</style>
