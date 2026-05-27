<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import { Plus, ChevronDown, Pencil } from 'lucide-vue-next'


interface SubCategory {
    id: string
    name: string
    count: number
}

interface Category {
    id: string
    name: string
    count: number
    kind: string
    tone: string
    desc: string
    slug: string
    children: SubCategory[]
}

const tree = ref<Category[]>([
    {
        id: 'rosas', name: 'Rosas Eternas', count: 18, kind: 'rose', tone: 'rose',
        desc: 'Tres años, una emoción. Rosas naturales preservadas bajo cúpula.',
        slug: '/rosas-eternas',
        children: [
            { id: 'cupula', name: 'Bajo cúpula', count: 8 },
            { id: 'bouquet', name: 'Bouquets eternos', count: 6 },
            { id: 'mini', name: 'Mini arreglos', count: 4 },
        ],
    },
    {
        id: 'peluches', name: 'Peluches', count: 12, kind: 'peluche', tone: 'rose',
        desc: 'Algodón orgánico, cosido a mano en el atelier.',
        slug: '/peluches',
        children: [
            { id: 'osos', name: 'Osos', count: 5 },
            { id: 'conejos', name: 'Conejos', count: 4 },
            { id: 'otros', name: 'Otros animales', count: 3 },
        ],
    },
    {
        id: 'carteras', name: 'Carteras', count: 9, kind: 'bolso', tone: 'lilac',
        desc: 'Cuero vegano artesanal con herrajes metálicos.',
        slug: '/carteras',
        children: [
            { id: 'bandolera', name: 'Bandolera', count: 4 },
            { id: 'tote', name: 'Tote', count: 3 },
            { id: 'clutch', name: 'Clutch', count: 2 },
        ],
    },
    {
        id: 'llaveros', name: 'Llaveros', count: 14, kind: 'llavero', tone: 'cream',
        desc: 'Pequeños detalles, gran memoria. Flores preservadas y aros dorados.',
        slug: '/llaveros',
        children: [],
    },
])

const expanded = ref<Record<string, boolean>>({ rosas: true })
const selected = ref<Category>(tree.value[0])

function toggleExpand(id: string) {
    expanded.value[id] = !expanded.value[id]
}

// Grip SVG dots (drag handle visual)
const gripPath = `M9 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM15 6a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM9 12a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM15 12a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM9 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM15 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z`
</script>

<template>
    <AdminLayout title="Categorías" breadcrumb="Catálogo">
        <div class="cat-shell">
            <!-- Tree panel -->
            <div class="card" style="padding: 20px; display: flex; flex-direction: column; overflow: hidden">
                <div class="row" style="justify-content: space-between; margin-bottom: 16px">
                    <div>
                        <div class="label-gilt">Estructura</div>
                        <div class="serif" style="font-size: 22px">4 categorías · 12 subcategorías</div>
                    </div>
                    <button class="btn btn-primary">
                        <Plus :size="14" style="margin-right: 4px"/> Nueva
                    </button>
                </div>

                <div class="scroll stack" style="gap: 8px; flex: 1; min-height: 0">
                    <div v-for="node in tree" :key="node.id">
                        <!-- Category row -->
                        <div
                            class="row"
                            style="gap: 12px; padding: 12px; background: var(--surface-low); border-radius: var(--r-lg); cursor: pointer"
                            @click="() => { toggleExpand(node.id); selected = node }"
                        >
                            <!-- Drag handle -->
                            <button style="color: var(--on-surface-variant); cursor: grab; flex-shrink: 0; min-height: 28px; display: flex; align-items: center">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                    <path :d="gripPath"/>
                                </svg>
                            </button>

                            <div style="width: 40px; height: 40px; flex-shrink: 0">
                                <Surrogate :kind="node.kind" :tone="node.tone" style="width: 100%; height: 100%"/>
                            </div>

                            <div class="grow">
                                <div class="serif" style="font-size: 16px">{{ node.name }}</div>
                                <div style="font-size: 11px; color: var(--on-surface-variant)">{{ node.count }} productos</div>
                            </div>

                            <button
                                v-if="node.children.length > 0"
                                class="btn-icon"
                                style="width: 28px; height: 28px; flex-shrink: 0"
                            >
                                <ChevronDown
                                    :size="14"
                                    :style="{
                                        transform: expanded[node.id] ? 'rotate(0)' : 'rotate(-90deg)',
                                        transition: 'transform .2s',
                                    }"
                                />
                            </button>
                        </div>

                        <!-- Subcategories -->
                        <Transition name="expand">
                            <div
                                v-if="expanded[node.id] && node.children.length > 0"
                                class="stack"
                                style="gap: 4px; padding: 8px 0 8px 36px"
                            >
                                <div
                                    v-for="child in node.children"
                                    :key="child.id"
                                    class="row"
                                    style="gap: 12px; padding: 8px 12px; background: var(--surface-lowest); border-radius: var(--r-md)"
                                >
                                    <button style="color: var(--on-surface-variant); cursor: grab; min-height: 24px; display: flex; align-items: center">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                            <path :d="gripPath"/>
                                        </svg>
                                    </button>
                                    <div class="grow" style="font-size: 14px">{{ child.name }}</div>
                                    <span style="font-size: 11px; color: var(--on-surface-variant)">{{ child.count }}</span>
                                    <button class="btn-icon" style="width: 24px; height: 24px">
                                        <Pencil :size="12"/>
                                    </button>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>

            <!-- Preview panel -->
            <div class="card" style="background: var(--surface-low); padding: 24px; display: flex; flex-direction: column">
                <div class="label-gilt" style="margin-bottom: 12px">Vista previa</div>
                <div style="aspect-ratio: 4/3; margin-bottom: 16px; border-radius: var(--r-lg); overflow: hidden">
                    <Surrogate :kind="selected.kind" :tone="selected.tone" style="width: 100%; height: 100%"/>
                </div>
                <div class="serif" style="font-size: 24px; margin-bottom: 6px">{{ selected.name }}</div>
                <p style="font-size: 13px; color: var(--on-surface-variant); margin-bottom: 16px; line-height: 1.6">
                    {{ selected.desc }}
                </p>
                <div class="stack" style="gap: 8px; font-size: 13px">
                    <div class="row" style="justify-content: space-between">
                        <span>Slug</span>
                        <code style="color: var(--on-surface-variant); font-size: 12px">{{ selected.slug }}</code>
                    </div>
                    <div class="row" style="justify-content: space-between">
                        <span>Productos</span>
                        <span>{{ selected.count }}</span>
                    </div>
                    <div class="row" style="justify-content: space-between">
                        <span>Subcategorías</span>
                        <span>{{ selected.children.length }}</span>
                    </div>
                </div>
                <div style="flex: 1"/>
                <button class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 20px">
                    <Pencil :size="14" style="margin-right: 6px"/> Editar categoría
                </button>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.cat-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}

.expand-enter-active,
.expand-leave-active {
    transition: opacity 0.2s ease, max-height 0.2s ease;
    max-height: 400px;
    overflow: hidden;
}
.expand-enter-from,
.expand-leave-to {
    opacity: 0;
    max-height: 0;
}

@media (min-width: 1024px) {
    .cat-shell {
        grid-template-columns: 1.4fr 1fr;
    }
}
</style>
