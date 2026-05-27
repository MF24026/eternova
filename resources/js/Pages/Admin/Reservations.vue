<script setup lang="ts">
import { ref, computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Slideover from '@/Components/Slideover.vue'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-vue-next'


interface ReservationEvent {
    c: string
    o: string
    t: 'rose' | 'lilac' | 'cream'
}

const selectedDay = ref(15)
const slideoverOpen = ref(false)

// May 2026: May 1 = Friday (index 5 in 0=Sun week)
const startOffset = 5
const totalDays = 31
const calCells = computed(() => {
    const cells: (number | null)[] = []
    for (let i = 0; i < startOffset; i++) cells.push(null)
    for (let d = 1; d <= totalDays; d++) cells.push(d)
    return cells
})

const events: Record<number, ReservationEvent[]> = {
    8: [{ c: 'Camila B.', o: 'Cumpleaños 30', t: 'rose' }],
    12: [{ c: 'Ana L.', o: 'Aniversario', t: 'lilac' }],
    15: [{ c: 'Sofía R.', o: 'Boda · ramos', t: 'rose' }, { c: 'María G.', o: 'Día de la madre', t: 'cream' }],
    18: [{ c: 'Lucía P.', o: 'Baby shower', t: 'lilac' }],
    22: [{ c: 'Valeria C.', o: 'Aniversario empresa', t: 'rose' }],
    28: [{ c: 'Daniela M.', o: 'Graduación', t: 'cream' }],
}

const toneBar: Record<string, string> = {
    rose: 'var(--primary)',
    lilac: 'var(--secondary)',
    cream: 'var(--warning)',
}

const toneChip: Record<string, { bg: string; color: string }> = {
    rose: { bg: 'var(--primary-container)', color: 'var(--primary-dim)' },
    lilac: { bg: 'var(--secondary-container)', color: 'var(--on-secondary-container)' },
    cream: { bg: 'var(--warning-container)', color: 'var(--warning)' },
}

const dayLabel = computed(() => {
    const days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']
    // May 1 2026 = Friday (5), so day d has weekday (5 + d - 1) % 7
    const wd = (5 + selectedDay.value - 1) % 7
    return `${selectedDay.value} de mayo · ${days[wd]}`
})

const selectedEvents = computed(() => events[selectedDay.value] ?? [])

// New reservation form
const form = ref({
    customer: '',
    occasion: 'Cumpleaños',
    deliveryDate: '15 / 05 / 2026',
    description: '',
    deposit: '$50.00',
    total: '$215.00',
})

const balance = computed(() => {
    const dep = parseFloat(form.value.deposit.replace('$', '')) || 0
    const tot = parseFloat(form.value.total.replace('$', '')) || 0
    return Math.max(0, tot - dep).toFixed(2)
})
</script>

<template>
    <AdminLayout title="Reservas" breadcrumb="Agenda del atelier">
        <div class="res-shell">
            <!-- Calendar -->
            <div class="card" style="padding: 24px; display: flex; flex-direction: column">
                <div class="row" style="justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 12px">
                    <div>
                        <div class="label-gilt">Mayo 2026</div>
                        <div class="serif" style="font-size: 24px">6 reservas este mes</div>
                    </div>
                    <div class="row" style="gap: 8px">
                        <button class="btn-icon"><ChevronLeft :size="18"/></button>
                        <button class="btn-icon"><ChevronRight :size="18"/></button>
                        <button class="btn btn-primary" @click="slideoverOpen = true">
                            <Plus :size="14" style="margin-right: 4px"/> Nueva reserva
                        </button>
                    </div>
                </div>

                <!-- Day labels -->
                <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; margin-bottom: 6px">
                    <div
                        v-for="d in ['D', 'L', 'M', 'M', 'J', 'V', 'S']"
                        :key="d + Math.random()"
                        style="text-align: center; font-size: 11px; font-weight: 700; color: var(--on-surface-variant); text-transform: uppercase; letter-spacing: .1em"
                    >
                        {{ d }}
                    </div>
                </div>

                <!-- Calendar grid -->
                <div class="cal-grid" style="flex: 1">
                    <template v-for="(cell, i) in calCells" :key="i">
                        <div v-if="cell === null" style="background: transparent"/>
                        <button
                            v-else
                            :class="[
                                'cal-cell',
                                events[cell] ? 'has-event' : '',
                                cell === 15 ? 'today' : '',
                            ]"
                            :style="cell === selectedDay && cell !== 15 ? { boxShadow: '0 0 0 2px var(--primary)' } : {}"
                            @click="selectedDay = cell"
                        >
                            <span style="font-size: 13px; font-weight: 500">{{ cell }}</span>
                            <div v-if="events[cell]" style="display: flex; flex-direction: column; gap: 2px; margin-top: 4px">
                                <div
                                    v-for="(e, j) in events[cell].slice(0, 2)"
                                    :key="j"
                                    :style="{
                                        width: '100%', height: '4px',
                                        borderRadius: '99px',
                                        background: toneBar[e.t],
                                    }"
                                />
                                <span v-if="events[cell].length > 2" style="font-size: 9px; color: var(--on-surface-variant)">
                                    +{{ events[cell].length - 2 }}
                                </span>
                            </div>
                        </button>
                    </template>
                </div>
            </div>

            <!-- Day detail panel -->
            <div class="card" style="background: var(--surface-low); padding: 24px; display: flex; flex-direction: column; min-height: 0">
                <div class="label-gilt">{{ dayLabel }}</div>
                <div class="serif" style="font-size: 22px; margin-bottom: 16px">{{ selectedEvents.length }} reservas</div>

                <div class="scroll stack" style="gap: 12px; flex: 1; min-height: 0">
                    <div
                        v-if="selectedEvents.length === 0"
                        class="card"
                        style="background: var(--surface-lowest); padding: 16px; text-align: center; color: var(--on-surface-variant); font-size: 14px"
                    >
                        Día tranquilo en el atelier.
                    </div>
                    <div
                        v-for="(e, i) in selectedEvents"
                        :key="i"
                        class="card"
                        style="background: var(--surface-lowest); padding: 16px"
                    >
                        <div class="row" style="justify-content: space-between; margin-bottom: 10px">
                            <span
                                class="bloom"
                                :style="{ background: toneChip[e.t].bg, color: toneChip[e.t].color }"
                            >
                                {{ e.o }}
                            </span>
                            <span style="font-size: 12px; color: var(--on-surface-variant)">3:00 PM</span>
                        </div>
                        <div class="serif" style="font-size: 18px; margin-bottom: 4px">{{ e.c }}</div>
                        <div style="font-size: 12px; color: var(--on-surface-variant); margin-bottom: 12px">
                            Ramo grande de rosas eternas con cinta marfil
                        </div>
                        <div class="row" style="gap: 8px; font-size: 11px">
                            <span style="color: var(--on-surface-variant)">Anticipo</span>
                            <span style="font-weight: 700">$50</span>
                            <span style="color: var(--on-surface-variant); margin-left: 8px">Saldo</span>
                            <span style="font-weight: 700; color: var(--primary)">$165</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New reservation slideover -->
        <Slideover
            :open="slideoverOpen"
            title="Crear reserva"
            subtitle="Nueva entrada"
            @close="slideoverOpen = false"
        >
            <div class="stack" style="gap: 18px">
                <div>
                    <label class="field-label">Cliente</label>
                    <input v-model="form.customer" class="field" placeholder="Nombre completo"/>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label class="field-label">Ocasión</label>
                        <select v-model="form.occasion" class="field">
                            <option>Cumpleaños</option>
                            <option>Aniversario</option>
                            <option>Boda</option>
                            <option>Graduación</option>
                            <option>Baby shower</option>
                            <option>Otro</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label">Fecha entrega</label>
                        <input v-model="form.deliveryDate" class="field" placeholder="DD / MM / YYYY"/>
                    </div>
                </div>
                <div>
                    <label class="field-label">Descripción</label>
                    <textarea
                        v-model="form.description"
                        class="field"
                        rows="4"
                        placeholder="Detalles del arreglo, preferencias…"
                    />
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label class="field-label">Anticipo</label>
                        <input v-model="form.deposit" class="field"/>
                    </div>
                    <div>
                        <label class="field-label">Total estimado</label>
                        <input v-model="form.total" class="field"/>
                    </div>
                </div>
                <div class="card" style="background: var(--surface-low); padding: 14px">
                    <div class="label-gilt" style="margin-bottom: 8px">Saldo pendiente</div>
                    <div class="serif" style="color: var(--primary); font-size: 28px">${{ balance }}</div>
                </div>
            </div>

            <template #footer>
                <div class="row" style="gap: 10px">
                    <button class="btn btn-tertiary" style="flex: 1; justify-content: center" @click="slideoverOpen = false">
                        Cancelar
                    </button>
                    <button class="btn btn-primary" style="flex: 1; justify-content: center" @click="slideoverOpen = false">
                        Guardar reserva
                    </button>
                </div>
            </template>
        </Slideover>
    </AdminLayout>
</template>

<style scoped>
.res-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}

@media (min-width: 1024px) {
    .res-shell {
        grid-template-columns: 1.6fr 1fr;
    }
}
</style>
