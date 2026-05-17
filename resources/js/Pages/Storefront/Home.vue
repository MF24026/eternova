<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import StorefrontLayout from '@/Layouts/StorefrontLayout.vue';
import Surrogate from '@/Components/Surrogate.vue';
import Petal from '@/Components/Petal.vue';
import CartSlideover from '@/Components/CartSlideover.vue';
import { ArrowRight } from 'lucide-vue-next';

defineOptions({ layout: StorefrontLayout });

const PRODUCTS = [
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
];

const CATEGORIES = [
    { id: 'rosas', name: 'Rosas Eternas', tagline: 'Tres años, una emoción', kind: 'rose', tone: 'rose' },
    { id: 'peluches', name: 'Peluches', tagline: 'Algodón orgánico, cosido a mano', kind: 'peluche', tone: 'rose' },
    { id: 'carteras', name: 'Carteras', tagline: 'Cuero vegano artesanal', kind: 'bolso', tone: 'lilac' },
    { id: 'llaveros', name: 'Llaveros', tagline: 'Pequeños detalles, gran memoria', kind: 'llavero', tone: 'cream' },
];

const activeTab = ref('todo');
const cartOpen = ref(false);
const cartItems = ref([]);

const tabs = [
    { id: 'todo', label: 'Todo' },
    { id: 'rosas', label: 'Rosas' },
    { id: 'peluches', label: 'Peluches' },
    { id: 'carteras', label: 'Carteras' },
];

const filteredProducts = () => {
    if (activeTab.value === 'todo') return PRODUCTS;
    return PRODUCTS.filter(p => p.cat === activeTab.value);
};

const getCategoryName = (catId) => CATEGORIES.find(c => c.id === catId)?.name ?? '';

function addToCart(product) {
    const existing = cartItems.value.find(i => i.id === product.id);
    if (existing) {
        existing.qty++;
    } else {
        cartItems.value.push({ ...product, qty: 1 });
    }
    cartOpen.value = true;
}
</script>

<template>
    <div>
        <!-- Hero -->
        <section style="position: relative; padding: 32px 24px 56px; background: var(--gradient-bloom); overflow: hidden;">
            <div style="position: absolute; top: -60px; right: -80px; width: 360px; height: 360px; opacity: 0.65; pointer-events: none;">
                <Petal tone="lilac" :size="1"/>
            </div>
            <div style="position: absolute; bottom: -100px; left: -80px; width: 280px; height: 280px; opacity: 0.45; pointer-events: none;">
                <Petal tone="rose" :size="1"/>
            </div>

            <div class="hero-grid">
                <!-- Text side -->
                <div class="fade-in">
                    <div class="label-gilt" style="margin-bottom: 16px">Colección Primavera 2026</div>
                    <h1 class="serif" style="font-size: clamp(44px, 8vw, 72px); line-height: 0.98; margin: 0 0 24px; font-weight: 400; letter-spacing: -0.03em;">
                        Cada arreglo es<br>
                        <em style="font-style: italic; color: var(--primary)">una carta</em><br>
                        que perdura.
                    </h1>
                    <p style="color: var(--on-surface-variant); font-size: clamp(15px, 2vw, 17px); line-height: 1.6; max-width: 460px; margin-bottom: 32px;">
                        Rosas eternas preservadas, peluches cosidos a mano, accesorios curados.
                        Pensados para los momentos que merecen quedarse.
                    </p>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <Link href="/catalog/rosas" class="btn btn-primary">
                            Explorar la colección
                            <ArrowRight :size="16"/>
                        </Link>
                        <button class="btn-secondary">Ver historia del atelier</button>
                    </div>
                </div>

                <!-- Image mosaic -->
                <div class="fade-in-delay-1 hero-mosaic">
                    <div style="grid-column: 1 / 5; grid-row: 1 / 5;">
                        <Surrogate kind="rose" tone="rose" style="width: 100%; height: 100%;"/>
                    </div>
                    <div style="grid-column: 5 / 7; grid-row: 2 / 5;">
                        <Surrogate kind="peluche" tone="lilac" style="width: 100%; height: 100%;"/>
                    </div>
                    <div style="grid-column: 3 / 6; grid-row: 5 / 7;">
                        <Surrogate kind="bolso" tone="cream" style="width: 100%; height: 100%;"/>
                    </div>
                    <div style="grid-column: 1 / 3; grid-row: 5 / 7;">
                        <Surrogate kind="llavero" tone="rose" style="width: 100%; height: 100%;"/>
                    </div>
                </div>
            </div>
        </section>

        <!-- Featured Categories -->
        <section style="padding: 56px 24px;" class="categories-section">
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 32px; gap: 16px;">
                <div>
                    <div class="label-gilt" style="margin-bottom: 12px">Categorías</div>
                    <h2 class="serif" style="font-size: clamp(32px, 5vw, 48px); margin: 0;">Cuatro maneras<br>de hacer memoria.</h2>
                </div>
                <Link href="/catalog" class="btn-secondary hide-mobile">Ver todo</Link>
            </div>

            <div class="categories-grid">
                <Link
                    v-for="(c, i) in CATEGORIES"
                    :key="c.id"
                    :href="`/catalog/${c.id}`"
                    class="card card-hover"
                    style="padding: 0; overflow: hidden; text-align: left; background: var(--surface-lowest); aspect-ratio: 3/4; display: flex; flex-direction: column; text-decoration: none;"
                >
                    <div style="flex: 1; position: relative;">
                        <Surrogate :kind="c.kind" :tone="c.tone" style="position: absolute; inset: 0;"/>
                    </div>
                    <div style="padding: 20px;">
                        <div class="label-gilt" style="margin-bottom: 6px;">{{ c.tagline }}</div>
                        <div class="serif" style="font-size: 22px;">{{ c.name }}</div>
                    </div>
                </Link>
            </div>
        </section>

        <!-- Curated Product Grid -->
        <section class="tier" style="padding: 56px 24px;" id="curated">
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 32px; gap: 16px; flex-wrap: wrap;">
                <div>
                    <div class="label-gilt" style="margin-bottom: 12px">Curados a mano</div>
                    <h2 class="serif" style="font-size: clamp(32px, 5vw, 48px); margin: 0;">Lo que florece esta semana.</h2>
                </div>
                <div class="tabs">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        :class="['tab', { active: activeTab === tab.id }]"
                        @click="activeTab = tab.id"
                    >
                        {{ tab.label }}
                    </button>
                </div>
            </div>

            <div class="products-grid">
                <Link
                    v-for="p in filteredProducts().slice(0, 8)"
                    :key="p.id"
                    :href="`/product/${p.id}`"
                    style="text-align: left; text-decoration: none;"
                >
                    <div style="aspect-ratio: 1/1; margin-bottom: 14px;">
                        <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%;"/>
                    </div>
                    <div class="label-gilt" style="margin-bottom: 4px;">{{ getCategoryName(p.cat) }}</div>
                    <div class="serif" style="font-size: 18px; margin-bottom: 6px;">{{ p.name }}</div>
                    <div style="font-size: 14px; font-weight: 600; color: var(--primary);">${{ p.price.toFixed(2) }}</div>
                </Link>
            </div>
        </section>

        <!-- Story Section -->
        <section style="padding: 72px 24px; position: relative; overflow: hidden;" class="story-section">
            <div style="position: absolute; right: -120px; top: -60px; width: 380px; height: 380px; opacity: 0.35; pointer-events: none;">
                <Petal tone="rose" :size="1"/>
            </div>
            <div class="story-grid">
                <!-- Atelier image placeholder -->
                <div style="aspect-ratio: 4/5; border-radius: var(--r-2xl); overflow: hidden; position: relative;">
                    <div style="position: absolute; inset: 0; background: var(--gradient-soft);"/>
                    <div style="position: absolute; inset: 0; opacity: 0.8;">
                        <Petal tone="rose" :size="1"/>
                    </div>
                    <div class="glass" style="position: absolute; bottom: 24px; left: 24px; right: 24px; padding: 20px; border-radius: var(--r-lg);">
                        <div class="label-gilt" style="margin-bottom: 6px;">Atelier desde 2019</div>
                        <div class="serif" style="font-size: 22px;">Carolina, fundadora</div>
                    </div>
                </div>
                <!-- Story text -->
                <div>
                    <div class="label-gilt" style="margin-bottom: 16px;">Nuestra historia</div>
                    <h2 class="serif" style="font-size: clamp(32px, 5vw, 56px); margin: 0 0 24px; line-height: 1.02;">
                        Hecho despacio,<br>con manos que recuerdan.
                    </h2>
                    <p style="color: var(--on-surface-variant); font-size: 16px; line-height: 1.7; margin-bottom: 24px; max-width: 540px;">
                        Empezamos en una mesa pequeña con una sola rosa preservada y la convicción de
                        que los regalos importantes merecen quedarse. Hoy somos un taller de tres mujeres
                        en San Salvador, curando cada pieza para que dure años, no semanas.
                    </p>
                    <button class="btn btn-tertiary">
                        Leer la historia completa
                        <ArrowRight :size="16"/>
                    </button>
                </div>
            </div>
        </section>

        <!-- Cart Slideover -->
        <CartSlideover
            :open="cartOpen"
            :cart-items="cartItems"
            @close="cartOpen = false"
            @update-qty="(id, qty) => { const item = cartItems.find(i => i.id === id); if (item) item.qty = qty; }"
            @remove="(id) => { cartItems.value = cartItems.value.filter(i => i.id !== id); }"
        />
    </div>
</template>

<style scoped>
.hero-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 32px;
    align-items: center;
    position: relative;
}

.hero-mosaic {
    position: relative;
    aspect-ratio: 1/1;
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    grid-template-rows: repeat(6, 1fr);
    gap: 8px;
}

.categories-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.products-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.story-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 32px;
    align-items: center;
    position: relative;
}

.hide-mobile {
    display: none;
}

@media (min-width: 768px) {
    .hero-grid {
        grid-template-columns: 1.1fr 1fr;
        gap: 64px;
        padding: 64px 80px 80px;
    }

    section {
        padding-left: 80px !important;
        padding-right: 80px !important;
    }

    .categories-section {
        padding-top: 96px !important;
        padding-bottom: 96px !important;
    }

    .categories-grid {
        grid-template-columns: 1.4fr 1fr 1fr 1.2fr;
    }

    .products-grid {
        grid-template-columns: repeat(4, 1fr);
    }

    .story-grid {
        grid-template-columns: 1fr 1.2fr;
        gap: 80px;
    }

    .hide-mobile {
        display: inline-flex;
    }
}
</style>
