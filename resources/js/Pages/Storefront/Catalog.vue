<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import StorefrontLayout from '@/Layouts/StorefrontLayout.vue';
import Surrogate from '@/Components/Surrogate.vue';
import CartSlideover from '@/Components/CartSlideover.vue';
import { ChevronRight, X, SlidersHorizontal } from 'lucide-vue-next';

defineOptions({ layout: StorefrontLayout });

const props = defineProps({
    slug: { type: String, default: null },
});

const ALL_PRODUCTS = [
    { id: 'p1', name: 'Rosa Eterna Carmesí', price: 65.00, cat: 'rosas', tone: 'rose', kind: 'rose',
        desc: 'Rosa natural preservada bajo cúpula de cristal soplado. Perdura tres años.' },
    { id: 'p2', name: 'Bouquet Aurora', price: 89.00, cat: 'rosas', tone: 'lilac', kind: 'rose',
        desc: 'Ramo de rosas eternas en tonos lila y crema con follaje preservado.' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, cat: 'peluches', tone: 'rose', kind: 'peluche',
        desc: 'Oso de peluche de algodón orgánico, cosido a mano con detalles bordados.' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, cat: 'carteras', tone: 'rose', kind: 'bolso',
        desc: 'Cartera de cuero vegano con asa entretejida y broche dorado.' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, cat: 'llaveros', tone: 'cream', kind: 'llavero',
        desc: 'Llavero artesanal con dije de flor preservada y aro bañado en oro.' },
    { id: 'p6', name: 'Rosa Eterna Marfil', price: 65.00, cat: 'rosas', tone: 'cream', kind: 'rose',
        desc: 'Rosa preservada en tono marfil dentro de cúpula transparente.' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, cat: 'peluches', tone: 'lilac', kind: 'peluche',
        desc: 'Conejito de peluche en tonos lavanda, relleno hipoalergénico.' },
    { id: 'p8', name: 'Cartera Aurelia', price: 92.00, cat: 'carteras', tone: 'lilac', kind: 'bolso',
        desc: 'Cartera tipo bandolera en cuero suave con herrajes de bronce.' },
    { id: 'p9', name: 'Rosa Eterna Coral', price: 65.00, cat: 'rosas', tone: 'rose', kind: 'rose',
        desc: 'Rosa de tono coral intenso, base de madera y cúpula dorada.' },
    { id: 'p10', name: 'Peluche Mochi', price: 29.00, cat: 'peluches', tone: 'cream', kind: 'peluche',
        desc: 'Pequeño oso mochi con expresión bordada y cinta de satín.' },
    { id: 'p11', name: 'Llavero Botánico', price: 18.00, cat: 'llaveros', tone: 'sage', kind: 'llavero',
        desc: 'Dije botánico con flor seca dentro de resina transparente.' },
    { id: 'p12', name: 'Cartera Noche', price: 110.00, cat: 'carteras', tone: 'lilac', kind: 'bolso',
        desc: 'Cartera de noche en terciopelo suave con cierre de perla.' },
];

const CATEGORIES = [
    { id: 'rosas', name: 'Rosas Eternas' },
    { id: 'peluches', name: 'Peluches' },
    { id: 'carteras', name: 'Carteras' },
    { id: 'llaveros', name: 'Llaveros' },
];

const SORT_OPTIONS = [
    { value: 'featured', label: 'Destacados' },
    { value: 'price-asc', label: 'Precio: menor a mayor' },
    { value: 'price-desc', label: 'Precio: mayor a menor' },
    { value: 'name-asc', label: 'Nombre A-Z' },
];

const activeCategory = ref(props.slug || null);
const sortBy = ref('featured');
const maxPrice = ref(120);
const activeFilters = ref([]);
const cartOpen = ref(false);
const cartItems = ref([]);

const currentCategoryName = computed(() => {
    if (!activeCategory.value) return 'Todos los productos';
    return CATEGORIES.find(c => c.id === activeCategory.value)?.name ?? 'Catálogo';
});

const filteredProducts = computed(() => {
    let list = [...ALL_PRODUCTS];
    if (activeCategory.value) {
        list = list.filter(p => p.cat === activeCategory.value);
    }
    list = list.filter(p => p.price <= maxPrice.value);
    switch (sortBy.value) {
        case 'price-asc': list.sort((a, b) => a.price - b.price); break;
        case 'price-desc': list.sort((a, b) => b.price - a.price); break;
        case 'name-asc': list.sort((a, b) => a.name.localeCompare(b.name)); break;
    }
    return list;
});

function setCategory(catId) {
    activeCategory.value = catId;
}

function removeFilter(filter) {
    const idx = activeFilters.value.indexOf(filter);
    if (idx > -1) activeFilters.value.splice(idx, 1);
}

function addToCart(product) {
    const existing = cartItems.value.find(i => i.id === product.id);
    if (existing) {
        existing.qty++;
    } else {
        cartItems.value.push({ ...product, qty: 1 });
    }
}

const cartCount = computed(() => cartItems.value.reduce((s, i) => s + i.qty, 0));
</script>

<template>
    <div>
        <!-- Hero banner -->
        <section style="padding: 32px 24px 40px; background: var(--gradient-bloom);">
            <!-- Breadcrumbs -->
            <nav style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--on-surface-variant); margin-bottom: 20px; flex-wrap: wrap;">
                <Link href="/" style="color: var(--on-surface-variant); text-decoration: none;">Casa</Link>
                <ChevronRight :size="12"/>
                <Link href="/catalog" style="color: var(--on-surface-variant); text-decoration: none;">Catálogo</Link>
                <template v-if="activeCategory">
                    <ChevronRight :size="12"/>
                    <span style="color: var(--on-surface);">{{ currentCategoryName }}</span>
                </template>
            </nav>

            <div class="label-gilt" style="margin-bottom: 12px;">Catálogo</div>
            <h1 class="serif" style="font-size: clamp(36px, 6vw, 56px); margin: 0 0 12px; line-height: 1.02;">
                {{ currentCategoryName }}
            </h1>
            <p style="color: var(--on-surface-variant); font-size: 15px; max-width: 520px;">
                Piezas curadas a mano, pensadas para momentos que merecen quedarse.
            </p>
        </section>

        <!-- Filters bar -->
        <section style="padding: 20px 24px; background: var(--surface-low); position: sticky; top: 60px; z-index: 30;">
            <div class="filters-row">
                <!-- Category chips -->
                <div style="display: flex; gap: 8px; flex-wrap: wrap; flex: 1;">
                    <button
                        :class="['bloom', !activeCategory ? 'bloom-primary' : 'bloom-soft']"
                        @click="setCategory(null)"
                    >
                        Todo
                    </button>
                    <button
                        v-for="cat in CATEGORIES"
                        :key="cat.id"
                        :class="['bloom', activeCategory === cat.id ? 'bloom-primary' : 'bloom-soft']"
                        @click="setCategory(cat.id)"
                    >
                        {{ cat.name }}
                    </button>
                </div>

                <!-- Sort -->
                <div style="display: flex; align-items: center; gap: 12px;">
                    <select
                        v-model="sortBy"
                        class="field"
                        style="width: auto; padding: 8px 12px; font-size: 13px;"
                        aria-label="Ordenar por"
                    >
                        <option v-for="opt in SORT_OPTIONS" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                    <button class="btn btn-tertiary" style="display: flex; align-items: center; gap: 6px; padding: 8px 14px; font-size: 13px;">
                        <SlidersHorizontal :size="14"/>
                        Filtros
                    </button>
                </div>
            </div>

            <!-- Active filter chips -->
            <div v-if="activeFilters.length > 0" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
                <span
                    v-for="filter in activeFilters"
                    :key="filter"
                    class="bloom bloom-primary"
                    style="cursor: pointer;"
                    @click="removeFilter(filter)"
                >
                    {{ filter }}
                    <X :size="12"/>
                </span>
            </div>
        </section>

        <!-- Product Grid -->
        <section style="padding: 32px 24px 80px;" class="catalog-section">
            <!-- Result count -->
            <p style="font-size: 13px; color: var(--on-surface-variant); margin-bottom: 24px;">
                {{ filteredProducts.length }} {{ filteredProducts.length === 1 ? 'pieza' : 'piezas' }}
            </p>

            <div v-if="filteredProducts.length > 0" class="catalog-grid">
                <Link
                    v-for="p in filteredProducts"
                    :key="p.id"
                    :href="`/product/${p.id}`"
                    style="text-align: left; text-decoration: none; display: block;"
                >
                    <div style="aspect-ratio: 1/1; margin-bottom: 14px; position: relative;">
                        <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%;"/>
                        <div class="glass" style="position: absolute; bottom: 10px; right: 10px; padding: 5px 10px; border-radius: var(--r-full);">
                            <span class="label-gilt">{{ CATEGORIES.find(c => c.id === p.cat)?.name }}</span>
                        </div>
                    </div>
                    <div class="serif" style="font-size: 18px; margin-bottom: 6px;">{{ p.name }}</div>
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div style="font-size: 15px; font-weight: 600; color: var(--primary);">${{ p.price.toFixed(2) }}</div>
                        <button
                            class="btn btn-tertiary"
                            style="padding: 6px 14px; font-size: 12px;"
                            @click.prevent="addToCart(p)"
                        >
                            Agregar
                        </button>
                    </div>
                </Link>
            </div>

            <!-- Empty state -->
            <div v-else style="padding: 80px 0; text-align: center; color: var(--on-surface-variant);">
                <p style="font-size: 16px; margin-bottom: 8px;">No se encontraron productos con estos filtros.</p>
                <button class="btn-secondary" @click="activeCategory = null; maxPrice = 120;">Limpiar filtros</button>
            </div>
        </section>

        <!-- Cart Slideover -->
        <CartSlideover
            :open="cartOpen"
            :cart-items="cartItems"
            @close="cartOpen = false"
            @update-qty="(id, qty) => { const item = cartItems.find(i => i.id === id); if (item) item.qty = qty; }"
            @remove="(id) => { const idx = cartItems.findIndex(i => i.id === id); if (idx > -1) cartItems.splice(idx, 1); }"
        />
    </div>
</template>

<style scoped>
.filters-row {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.catalog-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .filters-row {
        flex-direction: row;
        align-items: center;
    }

    section {
        padding-left: 80px !important;
        padding-right: 80px !important;
    }

    .catalog-section {
        padding-top: 40px !important;
        padding-bottom: 96px !important;
    }

    .catalog-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 1024px) {
    .catalog-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}
</style>
