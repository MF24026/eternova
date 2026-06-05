<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Search, User, Minus, Plus, Wallet, CreditCard, MessageCircle } from 'lucide-vue-next'

onMounted(() => { document.title = 'Punto de venta — Eternova' })

interface Product { id: string; name: string; price: number; cat: string }
interface CartItem extends Product { qty: number }

const PRODUCTS: Product[] = [
    { id: 'p1', name: 'Rosa Eterna Carmesi', price: 65.00, cat: 'rosas' },
    { id: 'p2', name: 'Bouquet Aurora', price: 89.00, cat: 'rosas' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, cat: 'peluches' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, cat: 'carteras' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, cat: 'llaveros' },
    { id: 'p6', name: 'Rosa Eterna Marfil', price: 65.00, cat: 'rosas' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, cat: 'peluches' },
    { id: 'p8', name: 'Cartera Aurelia', price: 92.00, cat: 'carteras' },
]
const CATEGORIES = [
    { id: 'all', name: 'Todo' },
    { id: 'rosas', name: 'Rosas' },
    { id: 'peluches', name: 'Peluches' },
    { id: 'carteras', name: 'Carteras' },
    { id: 'llaveros', name: 'Llaveros' },
]

const searchQuery = ref('')
const activeCat = ref('all')
const payMethod = ref<'efectivo' | 'tarjeta' | 'transfer'>('efectivo')
const cart = ref<CartItem[]>([
    { ...PRODUCTS[0], qty: 1 },
    { ...PRODUCTS[4], qty: 2 },
])

const filtered = computed(() => {
    const bySearch = searchQuery.value
        ? PRODUCTS.filter(p => p.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
        : PRODUCTS
    return activeCat.value === 'all' ? bySearch : bySearch.filter(p => p.cat === activeCat.value)
})

const subtotal = computed(() => cart.value.reduce((s, i) => s + i.price * i.qty, 0))
const tax = computed(() => subtotal.value * 0.13)
const total = computed(() => subtotal.value + tax.value)

function addItem(p: Product) {
    const existing = cart.value.find(i => i.id === p.id)
    if (existing) existing.qty++
    else cart.value.push({ ...p, qty: 1 })
}
function updateQty(id: string, q: number) {
    if (q <= 0) cart.value = cart.value.filter(i => i.id !== id)
    else { const item = cart.value.find(i => i.id === id); if (item) item.qty = q }
}

const payMethods = [
    { id: 'efectivo' as const, label: 'Efectivo', icon: Wallet },
    { id: 'tarjeta' as const, label: 'Tarjeta', icon: CreditCard },
    { id: 'transfer' as const, label: 'Transferencia', icon: MessageCircle },
]
</script>

<template>
    <div class="pos-shell">
        <!-- Products panel -->
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; min-height: 0; overflow: hidden">
            <div class="relative mb-4">
                <input v-model="searchQuery" class="field" placeholder="Buscar producto, SKU..." style="padding-left: 44px" aria-label="Buscar producto" />
                <Search :size="18" style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)" aria-hidden="true" />
            </div>
            <div class="scroll mb-4">
                <div class="tabs inline-flex">
                    <button v-for="c in CATEGORIES" :key="c.id" :class="['tab', { active: activeCat === c.id }]" @click="activeCat = c.id">{{ c.name }}</button>
                </div>
            </div>
            <div class="scroll flex-1 min-h-0">
                <div class="pos-grid">
                    <button v-for="p in filtered" :key="p.id" class="product-tile" @click="addItem(p)">
                        <div class="aspect-square" style="background: var(--gradient-soft)" />
                        <div style="padding: 12px">
                            <p class="text-sm font-semibold truncate text-on-surface">{{ p.name }}</p>
                            <p class="text-sm font-bold text-primary mt-1">${{ p.price.toFixed(2) }}</p>
                        </div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Cart panel -->
        <div class="card" style="padding: 24px; display: flex; flex-direction: column; min-height: 0; background: var(--surface-low)">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <p class="label-gilt">Venta en curso</p>
                    <p class="serif text-xl text-on-surface">#CC-2026-0144</p>
                </div>
                <button class="btn-icon" aria-label="Seleccionar cliente"><User :size="18" /></button>
            </div>

            <div class="scroll flex-1 min-h-0 -mx-2 px-2">
                <div v-if="cart.length === 0" class="py-10 text-center text-sm text-on-surface-variant">
                    Toca un producto para empezar.
                </div>
                <div v-for="item in cart" :key="item.id" class="flex items-center gap-3 py-2.5">
                    <div class="w-11 h-11 rounded-lg shrink-0" style="background: var(--gradient-soft)" />
                    <div class="grow min-w-0">
                        <p class="text-sm font-semibold truncate text-on-surface">{{ item.name }}</p>
                        <p class="text-xs text-on-surface-variant">${{ item.price.toFixed(2) }} c/u</p>
                    </div>
                    <div class="inline-flex items-center rounded-full p-0.5" style="background: var(--surface-lowest)">
                        <button class="w-7 h-7 flex items-center justify-center" @click="updateQty(item.id, item.qty - 1)" aria-label="Reducir cantidad"><Minus :size="12" /></button>
                        <span class="w-5 text-center text-xs font-semibold">{{ item.qty }}</span>
                        <button class="w-7 h-7 flex items-center justify-center" @click="updateQty(item.id, item.qty + 1)" aria-label="Aumentar cantidad"><Plus :size="12" /></button>
                    </div>
                    <span class="w-14 text-right text-sm font-semibold shrink-0">${{ (item.price * item.qty).toFixed(2) }}</span>
                </div>
            </div>

            <!-- Totals -->
            <div class="flex flex-col gap-2 pt-4 mt-4 text-sm">
                <div class="flex justify-between text-on-surface-variant"><span>Subtotal</span><span>${{ subtotal.toFixed(2) }}</span></div>
                <div class="flex justify-between text-on-surface-variant"><span>IVA 13%</span><span>${{ tax.toFixed(2) }}</span></div>
                <div class="flex justify-between items-center pt-2">
                    <span class="font-semibold text-base text-on-surface">Total</span>
                    <span class="serif text-3xl text-primary">${{ total.toFixed(2) }}</span>
                </div>
            </div>

            <!-- Payment method -->
            <div class="mt-4">
                <p class="label-gilt mb-2">Metodo de pago</p>
                <div class="grid grid-cols-3 gap-2">
                    <button
                        v-for="m in payMethods"
                        :key="m.id"
                        class="py-3 rounded-xl flex flex-col items-center gap-1.5 text-xs font-semibold transition-all duration-200"
                        :style="{
                            background: payMethod === m.id ? 'var(--primary-container)' : 'var(--surface-lowest)',
                            color: payMethod === m.id ? 'var(--primary-dim)' : 'var(--on-surface-variant)',
                        }"
                        @click="payMethod = m.id"
                    >
                        <component :is="m.icon" :size="18" />
                        {{ m.label }}
                    </button>
                </div>
            </div>

            <button
                class="btn btn-primary mt-4 w-full justify-center py-4 text-base"
                :disabled="cart.length === 0"
            >
                Cobrar ${{ total.toFixed(2) }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.pos-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
    height: calc(100vh - 120px);
}
.pos-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}
@media (min-width: 1024px) {
    .pos-shell { flex-direction: row; }
    .pos-shell > :first-child { flex: 2; }
    .pos-shell > :last-child { flex: 1; max-width: 380px; }
    .pos-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
}
</style>
