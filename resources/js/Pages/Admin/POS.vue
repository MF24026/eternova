<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Surrogate from '@/Components/Surrogate.vue'
import { Search, User, Minus, Plus, Wallet, CreditCard, MessageCircle } from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

interface Product {
    id: string
    name: string
    price: number
    cat: string
    tone: string
    kind: string
}

interface CartItem extends Product {
    qty: number
}

const PRODUCTS: Product[] = [
    { id: 'p1', name: 'Rosa Eterna Carmesí', price: 65.00, cat: 'rosas', tone: 'rose', kind: 'rose' },
    { id: 'p2', name: 'Bouquet Aurora', price: 89.00, cat: 'rosas', tone: 'lilac', kind: 'rose' },
    { id: 'p3', name: 'Peluche Olivia', price: 32.00, cat: 'peluches', tone: 'rose', kind: 'peluche' },
    { id: 'p4', name: 'Cartera Petalia', price: 78.00, cat: 'carteras', tone: 'rose', kind: 'bolso' },
    { id: 'p5', name: 'Llavero Camelia', price: 14.00, cat: 'llaveros', tone: 'cream', kind: 'llavero' },
    { id: 'p6', name: 'Rosa Eterna Marfil', price: 65.00, cat: 'rosas', tone: 'cream', kind: 'rose' },
    { id: 'p7', name: 'Peluche Lavanda', price: 36.00, cat: 'peluches', tone: 'lilac', kind: 'peluche' },
    { id: 'p8', name: 'Cartera Aurelia', price: 92.00, cat: 'carteras', tone: 'lilac', kind: 'bolso' },
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
    if (existing) {
        existing.qty++
    } else {
        cart.value.push({ ...p, qty: 1 })
    }
}

function updateQty(id: string, q: number) {
    if (q <= 0) {
        cart.value = cart.value.filter(i => i.id !== id)
    } else {
        const item = cart.value.find(i => i.id === id)
        if (item) item.qty = q
    }
}

const payMethods = [
    { id: 'efectivo' as const, label: 'Efectivo', icon: Wallet },
    { id: 'tarjeta' as const, label: 'Tarjeta', icon: CreditCard },
    { id: 'transfer' as const, label: 'Transferencia', icon: MessageCircle },
]
</script>

<template>
    <AdminLayout title="Punto de venta" breadcrumb="Operación">
        <div class="pos-shell">
            <!-- Products panel -->
            <div class="card" style="padding: 20px; display: flex; flex-direction: column; min-height: 0; overflow: hidden">
                <div style="position: relative; margin-bottom: 16px">
                    <input
                        v-model="searchQuery"
                        class="field"
                        placeholder="Buscar producto, SKU…"
                        style="padding-left: 44px"
                    />
                    <Search
                        :size="18"
                        style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--on-surface-variant)"
                    />
                </div>

                <div class="scroll" style="margin-bottom: 16px">
                    <div class="tabs" style="display: inline-flex">
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

                <div class="scroll" style="flex: 1; min-height: 0">
                    <div class="pos-grid">
                        <button
                            v-for="p in filtered"
                            :key="p.id"
                            class="product-tile"
                            @click="addItem(p)"
                        >
                            <div style="aspect-ratio: 1/1">
                                <Surrogate :kind="p.kind" :tone="p.tone" style="width: 100%; height: 100%; border-radius: 0"/>
                            </div>
                            <div style="padding: 12px">
                                <div style="font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                                    {{ p.name }}
                                </div>
                                <div style="font-size: 13px; color: var(--primary); font-weight: 700; margin-top: 4px">
                                    ${{ p.price.toFixed(2) }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Cart panel -->
            <div class="card" style="padding: 24px; display: flex; flex-direction: column; min-height: 0;
                background: var(--surface-low)">
                <div class="row" style="justify-content: space-between; margin-bottom: 16px">
                    <div>
                        <div class="label-gilt">Venta en curso</div>
                        <div class="serif" style="font-size: 22px">#CC-2026-0144</div>
                    </div>
                    <button class="btn-icon">
                        <User :size="18"/>
                    </button>
                </div>

                <div class="scroll" style="flex: 1; min-height: 0; margin: 0 -8px; padding: 0 8px">
                    <div v-if="cart.length === 0" style="text-align: center; color: var(--on-surface-variant); padding: 40px 0; font-size: 14px">
                        Toca un producto para empezar.
                    </div>
                    <div
                        v-for="item in cart"
                        :key="item.id"
                        style="display: flex; gap: 12px; align-items: center; padding: 10px 0"
                    >
                        <div style="width: 44px; height: 44px; flex-shrink: 0">
                            <Surrogate :kind="item.kind" :tone="item.tone" style="width: 100%; height: 100%"/>
                        </div>
                        <div class="grow" style="min-width: 0">
                            <div style="font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                                {{ item.name }}
                            </div>
                            <div style="font-size: 11px; color: var(--on-surface-variant)">${{ item.price.toFixed(2) }} c/u</div>
                        </div>
                        <div style="display: inline-flex; align-items: center; background: var(--surface-lowest); border-radius: 99px; padding: 2px">
                            <button
                                style="width: 28px; height: 28px; display: grid; place-items: center; min-height: 28px"
                                @click="updateQty(item.id, item.qty - 1)"
                            >
                                <Minus :size="12"/>
                            </button>
                            <span style="min-width: 20px; text-align: center; font-size: 12px; font-weight: 600">{{ item.qty }}</span>
                            <button
                                style="width: 28px; height: 28px; display: grid; place-items: center; min-height: 28px"
                                @click="updateQty(item.id, item.qty + 1)"
                            >
                                <Plus :size="12"/>
                            </button>
                        </div>
                        <div style="width: 60px; text-align: right; font-size: 13px; font-weight: 600; flex-shrink: 0">
                            ${{ (item.price * item.qty).toFixed(2) }}
                        </div>
                    </div>
                </div>

                <div class="stack" style="gap: 8px; font-size: 13px; padding-top: 14px; margin-top: 14px; border-top: 0">
                    <div class="row" style="justify-content: space-between; color: var(--on-surface-variant)">
                        <span>Subtotal</span><span>${{ subtotal.toFixed(2) }}</span>
                    </div>
                    <div class="row" style="justify-content: space-between; color: var(--on-surface-variant)">
                        <span>IVA 13%</span><span>${{ tax.toFixed(2) }}</span>
                    </div>
                    <div class="row" style="justify-content: space-between; font-size: 16px; font-weight: 700; padding-top: 10px">
                        <span>Total</span>
                        <span class="serif" style="color: var(--primary); font-size: 28px">${{ total.toFixed(2) }}</span>
                    </div>
                </div>

                <div class="stack" style="gap: 8px; margin-top: 16px">
                    <div class="label-gilt">Método de pago</div>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px">
                        <button
                            v-for="m in payMethods"
                            :key="m.id"
                            :style="{
                                padding: '12px 8px',
                                borderRadius: 'var(--r-lg)',
                                background: payMethod === m.id ? 'var(--primary-container)' : 'var(--surface-lowest)',
                                color: payMethod === m.id ? 'var(--primary-dim)' : 'var(--on-surface-variant)',
                                display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '6px',
                                fontSize: '11px', fontWeight: 600,
                                transition: 'all .25s ease',
                                minHeight: '64px',
                            }"
                            @click="payMethod = m.id"
                        >
                            <component :is="m.icon" :size="18"/>
                            {{ m.label }}
                        </button>
                    </div>
                </div>

                <button
                    class="btn btn-primary"
                    style="width: 100%; justify-content: center; padding: 18px; margin-top: 16px; font-size: 16px"
                    :disabled="cart.length === 0"
                >
                    Cobrar ${{ total.toFixed(2) }}
                </button>
            </div>
        </div>
    </AdminLayout>
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
    gap: 12px;
}

@media (min-width: 1024px) {
    .pos-shell {
        flex-direction: row;
    }
    .pos-shell > :first-child {
        flex: 2;
    }
    .pos-shell > :last-child {
        flex: 1;
        max-width: 380px;
    }
    .pos-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
}
</style>
