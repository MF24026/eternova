<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Slideover from '@/Components/Slideover.vue'
import { Plus, Receipt, Upload, Sparkles } from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

interface Expense {
    id: number
    vendor: string
    cat: string
    date: string
    total: number
    status: string
}

type OcrPhase = 'upload' | 'scanning' | 'review'

const categories: Record<string, { name: string; tone: string }> = {
    operativos: { name: 'Operativos', tone: 'info' },
    productos: { name: 'Productos', tone: 'primary' },
    planilla: { name: 'Planilla', tone: 'warning' },
    alquiler: { name: 'Alquiler', tone: 'error' },
}

const expenses = ref<Expense[]>([
    { id: 1, vendor: 'Floristería La Roca', cat: 'productos', date: '14 mayo', total: 124.50, status: 'approved' },
    { id: 2, vendor: 'Disney+ Streaming', cat: 'operativos', date: '13 mayo', total: 12.99, status: 'approved' },
    { id: 3, vendor: 'Carolina (sueldo)', cat: 'planilla', date: '10 mayo', total: 800.00, status: 'approved' },
    { id: 4, vendor: 'Tigo internet', cat: 'operativos', date: '8 mayo', total: 45.00, status: 'approved' },
    { id: 5, vendor: 'Alquiler atelier', cat: 'alquiler', date: '1 mayo', total: 650.00, status: 'approved' },
    { id: 6, vendor: 'Papel kraft + cintas', cat: 'productos', date: '29 abril', total: 78.20, status: 'approved' },
    { id: 7, vendor: 'Adobe Creative Cloud', cat: 'operativos', date: '28 abril', total: 54.99, status: 'approved' },
    { id: 8, vendor: 'María López (sueldo)', cat: 'planilla', date: '27 abril', total: 600.00, status: 'approved' },
])

const filter = ref('all')
const slideoverOpen = ref(false)
const ocrPhase = ref<OcrPhase>('upload')

const shown = computed(() =>
    filter.value === 'all' ? expenses.value : expenses.value.filter(e => e.cat === filter.value)
)

const totalMonth = computed(() => expenses.value.reduce((s, e) => s + e.total, 0))
const budget = 4200

const catTotals = computed(() =>
    Object.entries(categories).map(([k, v]) => {
        const sum = expenses.value.filter(e => e.cat === k).reduce((s, e) => s + e.total, 0)
        return { key: k, ...v, sum, pct: totalMonth.value ? (sum / totalMonth.value) * 100 : 0 }
    })
)

function openOCR() {
    slideoverOpen.value = true
    ocrPhase.value = 'upload'
}

function startScan() {
    ocrPhase.value = 'scanning'
    setTimeout(() => { ocrPhase.value = 'review' }, 1800)
}

function confirmExpense() {
    expenses.value.unshift({
        id: Date.now(),
        vendor: 'Floristería La Roca',
        cat: 'productos',
        date: 'Hoy',
        total: 87.50,
        status: 'approved',
    })
    slideoverOpen.value = false
}

function toneVar(tone: string): string {
    return tone === 'primary' ? 'var(--primary)' : `var(--${tone})`
}

function toneBgVar(tone: string): string {
    return tone === 'primary' ? 'var(--primary-container)' : `var(--${tone}-container)`
}
</script>

<template>
    <AdminLayout title="Gastos" breadcrumb="Finanzas">
        <div class="exp-shell">
            <!-- Expenses list -->
            <div class="card" style="padding: 20px; display: flex; flex-direction: column; overflow: hidden">
                <div class="row" style="margin-bottom: 16px; gap: 12px; flex-wrap: wrap">
                    <div class="scroll" style="max-width: 100%">
                        <div class="tabs" style="display: inline-flex">
                            <button :class="['tab', { active: filter === 'all' }]" @click="filter = 'all'">Todos</button>
                            <button
                                v-for="[k, v] in Object.entries(categories)"
                                :key="k"
                                :class="['tab', { active: filter === k }]"
                                @click="filter = k"
                            >
                                {{ v.name }}
                            </button>
                        </div>
                    </div>
                    <div class="grow"/>
                    <button class="btn btn-primary" @click="openOCR">
                        <Plus :size="14" style="margin-right: 4px"/> Nuevo gasto
                    </button>
                </div>

                <div class="scroll stack" style="gap: 6px; flex: 1; min-height: 0">
                    <div
                        v-for="e in shown"
                        :key="e.id"
                        class="exp-row"
                    >
                        <span class="exp-icon" :style="{
                            background: toneBgVar(categories[e.cat].tone),
                            color: toneVar(categories[e.cat].tone),
                        }">
                            <Receipt :size="16"/>
                        </span>
                        <div style="min-width: 0; flex: 1">
                            <div class="serif" style="font-size: 15px">{{ e.vendor }}</div>
                            <div style="font-size: 11px; color: var(--on-surface-variant)">{{ e.date }}</div>
                        </div>
                        <span
                            :class="`bloom bloom-${categories[e.cat].tone === 'primary' ? 'primary' : categories[e.cat].tone}`"
                            class="exp-cat"
                            style="font-size: 11px"
                        >
                            {{ categories[e.cat].name }}
                        </span>
                        <div style="text-align: right; color: var(--primary); font-weight: 700; font-size: 15px; flex-shrink: 0">
                            ${{ e.total.toFixed(2) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary panel -->
            <div class="stack" style="gap: 16px">
                <div class="card" style="background: var(--surface-low); padding: 20px">
                    <div class="label-gilt" style="margin-bottom: 6px">Total del mes</div>
                    <div class="serif" style="font-size: 36px; color: var(--primary); line-height: 1">${{ totalMonth.toFixed(2) }}</div>
                    <div style="font-size: 12px; color: var(--on-surface-variant); margin-top: 6px">
                        {{ Math.round((totalMonth / budget) * 100) }}% del presupuesto · ${{ budget.toFixed(2) }}
                    </div>
                    <div style="margin-top: 14px; height: 6px; border-radius: 99px; background: var(--surface-highest); overflow: hidden">
                        <div :style="{ width: `${Math.min(100, (totalMonth / budget) * 100)}%`, height: '100%', background: 'var(--gradient)' }"/>
                    </div>
                </div>
                <div class="card" style="background: var(--surface-low); padding: 20px; flex: 1">
                    <div class="label-gilt" style="margin-bottom: 12px">Por categoría</div>
                    <div class="stack" style="gap: 12px">
                        <div v-for="cat in catTotals" :key="cat.key">
                            <div class="row" style="justify-content: space-between; font-size: 13px; margin-bottom: 4px">
                                <span>{{ cat.name }}</span>
                                <span style="font-weight: 600">${{ cat.sum.toFixed(2) }}</span>
                            </div>
                            <div style="height: 4px; border-radius: 99px; background: var(--surface-highest); overflow: hidden">
                                <div :style="{ width: `${cat.pct}%`, height: '100%', background: toneVar(cat.tone) }"/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- OCR Slideover -->
        <Slideover
            :open="slideoverOpen"
            title="Nuevo gasto"
            subtitle="Captura inteligente"
            @close="slideoverOpen = false"
        >
            <!-- Upload phase -->
            <div v-if="ocrPhase === 'upload'" class="stack" style="gap: 16px; padding-top: 16px">
                <button
                    style="padding: 40px; border-radius: var(--r-xl); background: var(--surface-low);
                        display: flex; flex-direction: column; align-items: center; gap: 14px;
                        color: var(--on-surface-variant); width: 100%"
                    @click="startScan"
                >
                    <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--primary-container);
                        color: var(--primary-dim); display: grid; place-items: center">
                        <Upload :size="24"/>
                    </div>
                    <div>
                        <div class="serif" style="font-size: 18px; color: var(--on-surface); margin-bottom: 4px">
                            Subir factura
                        </div>
                        <div style="font-size: 13px">
                            Toma una foto o arrastra una imagen · leemos los datos automáticamente
                        </div>
                    </div>
                </button>
                <button class="btn btn-tertiary" style="width: 100%; justify-content: center">
                    Capturar manualmente
                </button>
            </div>

            <!-- Scanning phase -->
            <div v-else-if="ocrPhase === 'scanning'" style="text-align: center; padding: 60px 0">
                <div style="width: 80px; height: 80px; margin: 0 auto 24px; border-radius: 50%;
                    background: var(--primary-container); display: grid; place-items: center; color: var(--primary-dim)">
                    <Sparkles :size="32"/>
                </div>
                <div class="serif" style="font-size: 22px; margin-bottom: 8px">Leyendo factura…</div>
                <div style="font-size: 13px; color: var(--on-surface-variant)">Identificando proveedor, monto y categoría</div>
                <div style="width: 200px; height: 4px; margin: 32px auto 0; border-radius: 99px;
                    background: var(--surface-mid); overflow: hidden">
                    <div class="scan-bar"/>
                </div>
            </div>

            <!-- Review phase -->
            <div v-else class="stack" style="gap: 16px">
                <div class="card" style="background: var(--primary-container); color: var(--primary-dim); padding: 14px; display: flex; gap: 12px; align-items: center">
                    <Sparkles :size="20"/>
                    <div style="font-size: 13px; font-weight: 500">Datos extraídos. Revisa y ajusta antes de guardar.</div>
                </div>
                <div class="card" style="background: var(--surface-low); padding: 14px; display: flex; gap: 12px; align-items: center">
                    <div style="width: 56px; height: 70px; background: var(--surface-highest); border-radius: var(--r-md); display: grid; place-items: center; color: var(--on-surface-variant); flex-shrink: 0">
                        <Receipt :size="20"/>
                    </div>
                    <div class="grow">
                        <div style="font-size: 12px; color: var(--on-surface-variant)">Factura adjunta</div>
                        <div style="font-size: 13px; font-weight: 600">factura-larocas-2026-05-15.jpg</div>
                    </div>
                </div>
                <div>
                    <label class="field-label">Proveedor</label>
                    <input class="field" value="Floristería La Roca"/>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label class="field-label">Monto</label>
                        <input class="field" value="$87.50"/>
                    </div>
                    <div>
                        <label class="field-label">Fecha</label>
                        <input class="field" value="15 / 05 / 2026"/>
                    </div>
                </div>
                <div>
                    <label class="field-label">Categoría</label>
                    <select class="field">
                        <option v-for="[k, v] in Object.entries(categories)" :key="k" :value="k">{{ v.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">Nota</label>
                    <textarea class="field" rows="3" placeholder="Detalle del gasto…">Rosas frescas para preservación · lote semanal</textarea>
                </div>
            </div>

            <template #footer>
                <div v-if="ocrPhase === 'review'" class="row" style="gap: 10px">
                    <button class="btn btn-tertiary" style="flex: 1; justify-content: center" @click="slideoverOpen = false">Cancelar</button>
                    <button class="btn btn-primary" style="flex: 1; justify-content: center" @click="confirmExpense">Guardar gasto</button>
                </div>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.exp-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}

.exp-row {
    background: var(--surface-low);
    border-radius: var(--r-lg);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.exp-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    flex-shrink: 0;
}

.exp-cat {
    display: none;
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
    .exp-shell {
        grid-template-columns: 1fr 280px;
    }
    .exp-row {
        display: grid;
        grid-template-columns: 44px 1fr 120px 110px;
        gap: 16px;
    }
    .exp-cat {
        display: inline-flex;
    }
}
</style>
