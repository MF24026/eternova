<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import StorefrontLayout from '@/Layouts/StorefrontLayout.vue';
import Surrogate from '@/Components/Surrogate.vue';
import CartSlideover from '@/Components/CartSlideover.vue';
import { ArrowLeft, ChevronRight, Check, ShoppingBag, Heart, Minus, Plus, Truck, Gift, Sparkles } from 'lucide-vue-next';

defineOptions({ layout: StorefrontLayout });

const props = defineProps({
    slug: { type: String, default: 'p1' },
});

const ALL_PRODUCTS = [
    { id: 'p1', name: 'Rosa Eterna Carmesí', price: 65.00, cat: 'rosas', tone: 'rose', kind: 'rose',
        desc: 'Rosa natural preservada bajo cúpula de cristal soplado. Perdura tres años sin agua ni cuidados especiales. Cada pieza es única, artesanal, sellada a mano.' },
    { id: 'p2', name: 'Bouquet Aurora', price: 89.00, cat: 'rosas', tone: 'lilac', kind: 'rose',
        desc: 'Ramo de rosas eternas en tonos lila y crema con follaje preservado. Perfecto para decoración permanente.' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, cat: 'peluches', tone: 'rose', kind: 'peluche',
        desc: 'Oso de peluche de algodón orgánico, cosido a mano con detalles bordados. Relleno hipoalergénico certificado.' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, cat: 'carteras', tone: 'rose', kind: 'bolso',
        desc: 'Cartera de cuero vegano con asa entretejida y broche dorado. Interior forrado en satín color crema.' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, cat: 'llaveros', tone: 'cream', kind: 'llavero',
        desc: 'Llavero artesanal con dije de flor preservada y aro bañado en oro de 18k.' },
    { id: 'p6', name: 'Rosa Eterna Marfil', price: 65.00, cat: 'rosas', tone: 'cream', kind: 'rose',
        desc: 'Rosa preservada en tono marfil dentro de cúpula transparente. Elegancia atemporal.' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, cat: 'peluches', tone: 'lilac', kind: 'peluche',
        desc: 'Conejito de peluche en tonos lavanda, relleno hipoalergénico. Cosido a mano en El Salvador.' },
    { id: 'p8', name: 'Cartera Aurelia', price: 92.00, cat: 'carteras', tone: 'lilac', kind: 'bolso',
        desc: 'Cartera tipo bandolera en cuero suave con herrajes de bronce. Compartimentos interiores organizados.' },
];

const CATEGORIES = [
    { id: 'rosas', name: 'Rosas Eternas' },
    { id: 'peluches', name: 'Peluches' },
    { id: 'carteras', name: 'Carteras' },
    { id: 'llaveros', name: 'Llaveros' },
];

const TONE_COLORS = {
    rose: '#f8c4cf',
    lilac: '#eddcff',
    cream: '#fff0f2',
    sage: '#d8ecdc',
};

// Find product by slug (slug = product id in this mock)
const product = computed(() => ALL_PRODUCTS.find(p => p.id === props.slug) ?? ALL_PRODUCTS[0]);

const tones = computed(() => [product.value.tone, 'lilac', 'cream']);
const galleryIndex = ref(0);
const qty = ref(1);
const added = ref(false);
const cartOpen = ref(false);
const cartItems = ref([]);

const related = computed(() =>
    ALL_PRODUCTS.filter(p => p.cat === product.value.cat && p.id !== product.value.id).slice(0, 3)
);

const categoryName = computed(() =>
    CATEGORIES.find(c => c.id === product.value.cat)?.name ?? ''
);

function addToCart() {
    const existing = cartItems.value.find(i => i.id === product.value.id);
    if (existing) {
        existing.qty += qty.value;
    } else {
        cartItems.value.push({ ...product.value, qty: qty.value });
    }
    added.value = true;
    cartOpen.value = true;
    setTimeout(() => { added.value = false; }, 1500);
}

function decreaseQty() {
    if (qty.value > 1) qty.value--;
}

function increaseQty() {
    qty.value++;
}

const cartCount = computed(() => cartItems.value.reduce((s, i) => s + i.qty, 0));
</script>

<template>
    <div>
        <!-- Breadcrumb + Back -->
        <div class="breadcrumb-bar">
            <Link href="/catalog" class="btn btn-ghost" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px;">
                <ArrowLeft :size="14"/>
                Atrás
            </Link>
            <span style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--on-surface-variant); flex-wrap: wrap;">
                <Link href="/" style="color: var(--on-surface-variant); text-decoration: none;">Casa</Link>
                <ChevronRight :size="12"/>
                <Link :href="`/catalog/${product.cat}`" style="color: var(--on-surface-variant); text-decoration: none;">{{ categoryName }}</Link>
                <ChevronRight :size="12"/>
                <span style="color: var(--on-surface);">{{ product.name }}</span>
            </span>
        </div>

        <!-- Main product section -->
        <section class="product-section">
            <!-- Gallery -->
            <div class="fade-in">
                <!-- Main image -->
                <div style="aspect-ratio: 4/5; margin-bottom: 16px; position: relative;">
                    <Surrogate
                        :kind="product.kind"
                        :tone="tones[galleryIndex]"
                        style="position: absolute; inset: 0;"
                    />
                    <div class="glass" style="position: absolute; top: 20px; right: 20px; padding: 6px 12px; border-radius: var(--r-full);">
                        <span class="label-gilt">Edición limitada</span>
                    </div>
                </div>
                <!-- Thumbnails -->
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                    <button
                        v-for="(tone, i) in tones"
                        :key="i"
                        :style="{
                            aspectRatio: '1/1',
                            borderRadius: 'var(--r-lg)',
                            overflow: 'hidden',
                            boxShadow: galleryIndex === i ? '0 0 0 2px var(--primary)' : 'none',
                            transition: 'box-shadow .25s ease',
                        }"
                        @click="galleryIndex = i"
                    >
                        <Surrogate :kind="product.kind" :tone="tone" style="width: 100%; height: 100%;"/>
                    </button>
                </div>
            </div>

            <!-- Product info -->
            <div class="fade-in-delay-1" style="display: flex; flex-direction: column; gap: 20px;">
                <!-- Header -->
                <div>
                    <div class="label-gilt" style="margin-bottom: 10px;">
                        {{ categoryName }} · SKU CC-{{ product.id.toUpperCase() }}
                    </div>
                    <h1 class="serif" style="font-size: clamp(36px, 5vw, 52px); margin: 0 0 12px; line-height: 1.02;">
                        {{ product.name }}
                    </h1>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span class="serif" style="font-size: 32px; color: var(--primary);">${{ product.price.toFixed(2) }}</span>
                        <span class="bloom bloom-success">
                            <Check :size="12"/>
                            En existencia
                        </span>
                    </div>
                </div>

                <!-- Description -->
                <p style="color: var(--on-surface-variant); font-size: 15px; line-height: 1.7;">
                    {{ product.desc }}
                </p>

                <!-- Tone selector -->
                <div>
                    <div class="label-gilt" style="margin-bottom: 10px;">Tono</div>
                    <div style="display: flex; gap: 10px;">
                        <button
                            v-for="(tone, i) in tones"
                            :key="i"
                            :style="{
                                width: '36px',
                                height: '36px',
                                borderRadius: '50%',
                                background: TONE_COLORS[tone] ?? '#f8c4cf',
                                boxShadow: galleryIndex === i ? '0 0 0 2px var(--primary)' : 'none',
                                transition: 'box-shadow .25s ease',
                            }"
                            :aria-label="`Tono ${tone}`"
                            @click="galleryIndex = i"
                        />
                    </div>
                </div>

                <!-- Quantity + Add to cart -->
                <div style="display: flex; gap: 12px; align-items: center; margin-top: 8px; flex-wrap: wrap;">
                    <div style="display: inline-flex; align-items: center; background: var(--surface-low); border-radius: 99px; padding: 4px; gap: 4px;">
                        <button class="btn-icon" style="width: 36px; height: 36px;" aria-label="Reducir cantidad" @click="decreaseQty">
                            <Minus :size="16"/>
                        </button>
                        <span style="min-width: 32px; text-align: center; font-weight: 600;">{{ qty }}</span>
                        <button class="btn-icon" style="width: 36px; height: 36px;" aria-label="Aumentar cantidad" @click="increaseQty">
                            <Plus :size="16"/>
                        </button>
                    </div>
                    <button
                        class="btn btn-primary"
                        style="flex: 1; justify-content: center; min-width: 180px;"
                        @click="addToCart"
                    >
                        <component :is="added ? Check : ShoppingBag" :size="16"/>
                        {{ added ? 'Añadido' : 'Añadir al carrito' }}
                    </button>
                    <button class="btn-icon" aria-label="Guardar en favoritos">
                        <Heart :size="20"/>
                    </button>
                </div>

                <!-- Shipping details -->
                <div style="background: var(--surface-low); border-radius: var(--r-xl); padding: 20px; margin-top: 16px;">
                    <div class="label-gilt" style="margin-bottom: 12px;">El detalle</div>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; font-size: 14px; color: var(--on-surface-variant);">
                        <li style="display: flex; align-items: center; gap: 10px;">
                            <Truck :size="16"/>
                            Envío en 24h dentro de San Salvador
                        </li>
                        <li style="display: flex; align-items: center; gap: 10px;">
                            <Gift :size="16"/>
                            Empaque firma incluido sin costo
                        </li>
                        <li style="display: flex; align-items: center; gap: 10px;">
                            <Sparkles :size="16"/>
                            Hecho a mano · pieza única
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Related products -->
        <section class="related-section">
            <div class="label-gilt" style="margin-bottom: 12px;">También te puede gustar</div>
            <h3 class="serif" style="font-size: clamp(28px, 4vw, 36px); margin: 0 0 24px;">Del mismo jardín.</h3>
            <div class="related-grid">
                <Link
                    v-for="p in related"
                    :key="p.id"
                    :href="`/product/${p.id}`"
                    style="text-align: left; text-decoration: none; display: block;"
                >
                    <div style="aspect-ratio: 1/1; margin-bottom: 12px;">
                        <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%;"/>
                    </div>
                    <div class="serif" style="font-size: 18px; margin-bottom: 4px;">{{ p.name }}</div>
                    <div style="font-size: 14px; color: var(--primary); font-weight: 600;">${{ p.price.toFixed(2) }}</div>
                </Link>
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
.breadcrumb-bar {
    padding: 20px 24px 0;
    display: flex;
    align-items: center;
    gap: 16px;
}

.product-section {
    padding: 24px 24px 56px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 32px;
}

.related-section {
    padding: 0 24px 56px;
}

.related-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .breadcrumb-bar {
        padding: 24px 80px 0;
    }

    .product-section {
        padding: 32px 80px 96px;
        grid-template-columns: 1.2fr 1fr;
        gap: 64px;
    }

    .related-section {
        padding: 0 80px 96px;
    }

    .related-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
</style>
