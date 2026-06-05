<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Plus, ChevronDown, Pencil } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'

onMounted(() => { document.title = 'Categorias — Eternova' })

interface SubCategory { id: string; name: string; count: number }
interface Category { id: string; name: string; count: number; desc: string; slug: string; children: SubCategory[] }

const tree = ref<Category[]>([
    {
        id: 'rosas', name: 'Rosas Eternas', count: 18, desc: 'Tres anos, una emocion. Rosas naturales preservadas bajo cupula.',
        slug: '/rosas-eternas',
        children: [
            { id: 'cupula', name: 'Bajo cupula', count: 8 },
            { id: 'bouquet', name: 'Bouquets eternos', count: 6 },
            { id: 'mini', name: 'Mini arreglos', count: 4 },
        ],
    },
    {
        id: 'peluches', name: 'Peluches', count: 12, desc: 'Algodon organico, cosido a mano en el atelier.',
        slug: '/peluches',
        children: [
            { id: 'osos', name: 'Osos', count: 5 },
            { id: 'conejos', name: 'Conejos', count: 4 },
            { id: 'otros', name: 'Otros animales', count: 3 },
        ],
    },
    {
        id: 'carteras', name: 'Carteras', count: 9, desc: 'Cuero vegano artesanal con herrajes metalicos.',
        slug: '/carteras',
        children: [
            { id: 'bandolera', name: 'Bandolera', count: 4 },
            { id: 'tote', name: 'Tote', count: 3 },
            { id: 'clutch', name: 'Clutch', count: 2 },
        ],
    },
    {
        id: 'llaveros', name: 'Llaveros', count: 14, desc: 'Pequenos detalles, gran memoria.',
        slug: '/llaveros',
        children: [],
    },
])

const expanded = ref<Record<string, boolean>>({ rosas: true })
const selected = ref<Category>(tree.value[0])

function toggleExpand(id: string) { expanded.value[id] = !expanded.value[id] }
</script>

<template>
    <div class="cat-shell">
        <!-- Tree panel -->
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; overflow: hidden">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <p class="label-gilt">Estructura</p>
                    <p class="serif text-2xl text-on-surface">4 categorias · 12 subcategorias</p>
                </div>
                <AppButton :icon="Plus" size="sm">Nueva</AppButton>
            </div>

            <div class="scroll flex flex-col gap-2 flex-1 min-h-0">
                <div v-for="node in tree" :key="node.id">
                    <!-- Category row -->
                    <div
                        class="flex items-center gap-3 p-3 rounded-xl cursor-pointer transition-colors hover:opacity-90"
                        style="background: var(--surface-low)"
                        @click="() => { toggleExpand(node.id); selected = node }"
                    >
                        <div class="w-10 h-10 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                        <div class="grow">
                            <p class="serif text-base text-on-surface">{{ node.name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ node.count }} productos</p>
                        </div>
                        <button v-if="node.children.length > 0" class="btn-icon w-7 h-7" aria-label="Expandir">
                            <ChevronDown :size="14" :class="['transition-transform duration-200', expanded[node.id] ? 'rotate-0' : '-rotate-90']" />
                        </button>
                    </div>

                    <!-- Subcategories -->
                    <Transition name="expand">
                        <div v-if="expanded[node.id] && node.children.length > 0" class="flex flex-col gap-1 py-2 pl-9">
                            <div
                                v-for="child in node.children"
                                :key="child.id"
                                class="flex items-center gap-3 px-3 py-2 rounded-xl"
                                style="background: var(--surface-lowest)"
                            >
                                <div class="grow text-sm text-on-surface">{{ child.name }}</div>
                                <span class="text-xs text-on-surface-variant">{{ child.count }}</span>
                                <button class="btn-icon w-6 h-6" aria-label="Editar subcategoria"><Pencil :size="12" /></button>
                            </div>
                        </div>
                    </Transition>
                </div>
            </div>
        </div>

        <!-- Preview panel -->
        <div class="card" style="padding: 24px; background: var(--surface-low); display: flex; flex-direction: column">
            <p class="label-gilt mb-3">Vista previa</p>
            <div class="aspect-[4/3] rounded-xl overflow-hidden mb-4" style="background: var(--gradient-soft)" />
            <p class="serif text-2xl text-on-surface mb-1.5">{{ selected.name }}</p>
            <p class="text-sm text-on-surface-variant mb-4 leading-relaxed">{{ selected.desc }}</p>
            <div class="flex flex-col gap-2 text-sm mb-auto">
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Slug</span>
                    <code class="text-xs text-on-surface-variant">{{ selected.slug }}</code>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Productos</span>
                    <span class="font-semibold">{{ selected.count }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-on-surface-variant">Subcategorias</span>
                    <span class="font-semibold">{{ selected.children.length }}</span>
                </div>
            </div>
            <AppButton :icon="Pencil" class="w-full justify-center mt-5">Editar categoria</AppButton>
        </div>
    </div>
</template>

<style scoped>
.cat-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}
.expand-enter-active, .expand-leave-active {
    transition: opacity 0.2s ease, max-height 0.2s ease;
    max-height: 400px;
    overflow: hidden;
}
.expand-enter-from, .expand-leave-to { opacity: 0; max-height: 0; }
@media (min-width: 1024px) {
    .cat-shell { grid-template-columns: 1.4fr 1fr; }
}
</style>
