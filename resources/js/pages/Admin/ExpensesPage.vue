<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Plus, Receipt, Upload, Sparkles } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'

onMounted(() => { document.title = 'Gastos — Eternova' })

interface Expense { id: number; vendor: string; cat: string; date: string; total: number }
type OcrPhase = 'upload' | 'scanning' | 'review'

const categories: Record<string, { name: string; variant: 'info' | 'primary' | 'warning' | 'error' }> = {
    operativos: { name: 'Operativos', variant: 'info' },
    productos: { name: 'Productos', variant: 'primary' },
    planilla: { name: 'Planilla', variant: 'warning' },
    alquiler: { name: 'Alquiler', variant: 'error' },
}

const expenses = ref<Expense[]>([
    { id: 1, vendor: 'Floristeria La Roca', cat: 'productos', date: '14 mayo', total: 124.50 },
    { id: 2, vendor: 'Disney+ Streaming', cat: 'operativos', date: '13 mayo', total: 12.99 },
    { id: 3, vendor: 'Carolina (sueldo)', cat: 'planilla', date: '10 mayo', total: 800.00 },
    { id: 4, vendor: 'Tigo internet', cat: 'operativos', date: '8 mayo', total: 45.00 },
    { id: 5, vendor: 'Alquiler atelier', cat: 'alquiler', date: '1 mayo', total: 650.00 },
    { id: 6, vendor: 'Papel kraft + cintas', cat: 'productos', date: '29 abril', total: 78.20 },
    { id: 7, vendor: 'Adobe Creative Cloud', cat: 'operativos', date: '28 abril', total: 54.99 },
    { id: 8, vendor: 'Maria Lopez (sueldo)', cat: 'planilla', date: '27 abril', total: 600.00 },
])

const filter = ref('all')
const slideoverOpen = ref(false)
const ocrPhase = ref<OcrPhase>('upload')

const shown = computed(() => filter.value === 'all' ? expenses.value : expenses.value.filter(e => e.cat === filter.value))
const totalMonth = computed(() => expenses.value.reduce((s, e) => s + e.total, 0))
const budget = 4200

const catTotals = computed(() =>
    Object.entries(categories).map(([k, v]) => {
        const sum = expenses.value.filter(e => e.cat === k).reduce((s, e) => s + e.total, 0)
        return { key: k, ...v, sum, pct: totalMonth.value ? (sum / totalMonth.value) * 100 : 0 }
    })
)

function openOCR() { slideoverOpen.value = true; ocrPhase.value = 'upload' }
function startScan() {
    ocrPhase.value = 'scanning'
    setTimeout(() => { ocrPhase.value = 'review' }, 1800)
}
function confirmExpense() {
    expenses.value.unshift({ id: Date.now(), vendor: 'Floristeria La Roca', cat: 'productos', date: 'Hoy', total: 87.50 })
    slideoverOpen.value = false
}
</script>

<template>
    <div class="exp-shell">
        <!-- Expenses list -->
        <div class="card" style="padding: 20px; display: flex; flex-direction: column; overflow: hidden">
            <div class="flex flex-wrap gap-3 mb-4">
                <div class="scroll">
                    <div class="tabs inline-flex">
                        <button :class="['tab', { active: filter === 'all' }]" @click="filter = 'all'">Todos</button>
                        <button v-for="[k, v] in Object.entries(categories)" :key="k" :class="['tab', { active: filter === k }]" @click="filter = k">{{ v.name }}</button>
                    </div>
                </div>
                <div class="grow" />
                <AppButton :icon="Plus" @click="openOCR">Nuevo gasto</AppButton>
            </div>

            <div class="scroll flex flex-col gap-1.5 flex-1 min-h-0">
                <div v-for="e in shown" :key="e.id" class="flex items-center gap-3 p-3.5 rounded-xl" style="background: var(--surface-low)">
                    <span class="w-9 h-9 rounded-full flex items-center justify-center shrink-0" :style="{ background: `var(--${categories[e.cat].variant === 'primary' ? 'primary' : categories[e.cat].variant}-container)`, color: `var(--${categories[e.cat].variant === 'primary' ? 'primary' : categories[e.cat].variant})` }">
                        <Receipt :size="14" />
                    </span>
                    <div class="grow min-w-0">
                        <p class="serif text-base text-on-surface">{{ e.vendor }}</p>
                        <p class="text-xs text-on-surface-variant">{{ e.date }}</p>
                    </div>
                    <AppBadge :variant="categories[e.cat].variant" size="sm" class="hidden lg:inline-flex">{{ categories[e.cat].name }}</AppBadge>
                    <span class="font-bold text-primary text-sm shrink-0">${{ e.total.toFixed(2) }}</span>
                </div>
            </div>
        </div>

        <!-- Summary panel -->
        <div class="flex flex-col gap-4">
            <div class="card p-5" style="background: var(--surface-low)">
                <p class="label-gilt mb-1.5">Total del mes</p>
                <p class="serif text-4xl text-primary leading-none">${{ totalMonth.toFixed(2) }}</p>
                <p class="text-xs text-on-surface-variant mt-1.5">{{ Math.round((totalMonth / budget) * 100) }}% del presupuesto</p>
                <div class="mt-3.5 h-1.5 rounded-full overflow-hidden" style="background: var(--surface-highest)">
                    <div :style="{ width: `${Math.min(100, (totalMonth / budget) * 100)}%`, height: '100%', background: 'var(--gradient)' }" />
                </div>
            </div>
            <div class="card p-5 flex-1" style="background: var(--surface-low)">
                <p class="label-gilt mb-3">Por categoria</p>
                <div class="flex flex-col gap-3">
                    <div v-for="cat in catTotals" :key="cat.key">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-on-surface">{{ cat.name }}</span>
                            <span class="font-semibold">${{ cat.sum.toFixed(2) }}</span>
                        </div>
                        <div class="h-1 rounded-full overflow-hidden" style="background: var(--surface-highest)">
                            <div :style="{ width: `${cat.pct}%`, height: '100%', background: `var(--${cat.variant === 'primary' ? 'primary' : cat.variant})` }" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- OCR Slideover -->
    <AppSlideover
        :model-value="slideoverOpen"
        title="Nuevo gasto"
        subtitle="Captura inteligente"
        @update:model-value="slideoverOpen = false"
    >
        <!-- Upload phase -->
        <div v-if="ocrPhase === 'upload'" class="flex flex-col gap-4 pt-4">
            <button class="p-10 rounded-2xl flex flex-col items-center gap-4 text-on-surface-variant w-full transition-colors hover:opacity-90" style="background: var(--surface-low)" @click="startScan">
                <div class="w-14 h-14 rounded-full flex items-center justify-center" style="background: var(--primary-container); color: var(--primary-dim)"><Upload :size="24" /></div>
                <div class="text-center">
                    <p class="serif text-lg text-on-surface mb-1">Subir factura</p>
                    <p class="text-sm">Toma una foto o arrastra una imagen</p>
                </div>
            </button>
            <AppButton variant="secondary" class="w-full justify-center">Capturar manualmente</AppButton>
        </div>

        <!-- Scanning phase -->
        <div v-else-if="ocrPhase === 'scanning'" class="text-center py-16">
            <div class="w-20 h-20 rounded-full mx-auto mb-6 flex items-center justify-center" style="background: var(--primary-container); color: var(--primary-dim)"><Sparkles :size="32" /></div>
            <p class="serif text-2xl mb-2">Leyendo factura...</p>
            <p class="text-sm text-on-surface-variant">Identificando proveedor, monto y categoria</p>
            <div class="w-48 h-1 mx-auto mt-8 rounded-full overflow-hidden" style="background: var(--surface-mid)">
                <div class="scan-bar" />
            </div>
        </div>

        <!-- Review phase -->
        <div v-else class="flex flex-col gap-4">
            <div class="flex items-center gap-2.5 p-3.5 rounded-xl text-sm font-medium" style="background: var(--primary-container); color: var(--primary-dim)">
                <Sparkles :size="16" /> Datos extraidos. Revisa y ajusta antes de guardar.
            </div>
            <div>
                <label class="field-label">Proveedor</label>
                <input class="field mt-1.5" value="Floristeria La Roca" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="field-label">Monto</label><input class="field mt-1.5" value="$87.50" /></div>
                <div><label class="field-label">Fecha</label><input class="field mt-1.5" value="15/05/2026" /></div>
            </div>
            <div>
                <label class="field-label">Categoria</label>
                <select class="field mt-1.5">
                    <option v-for="[k, v] in Object.entries(categories)" :key="k" :value="k">{{ v.name }}</option>
                </select>
            </div>
            <div>
                <label class="field-label">Nota</label>
                <textarea class="field mt-1.5" rows="3">Rosas frescas para preservacion</textarea>
            </div>
        </div>

        <template #footer>
            <div v-if="ocrPhase === 'review'" class="flex gap-2.5">
                <AppButton variant="secondary" class="flex-1 justify-center" @click="slideoverOpen = false">Cancelar</AppButton>
                <AppButton class="flex-1 justify-center" @click="confirmExpense">Guardar gasto</AppButton>
            </div>
        </template>
    </AppSlideover>
</template>

<style scoped>
.exp-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}
.scan-bar {
    width: 70%;
    height: 100%;
    background: var(--gradient);
    animation: scan-anim 1.5s ease-in-out infinite alternate;
}
@keyframes scan-anim {
    from { width: 30%; margin-left: 0 }
    to { width: 70%; margin-left: 30% }
}
@media (min-width: 1024px) {
    .exp-shell { grid-template-columns: 1fr 280px; }
}
</style>
