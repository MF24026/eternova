<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppBadge from '@/components/base/AppBadge.vue'
import AppButton from '@/components/base/AppButton.vue'

onMounted(() => { document.title = 'Reservas — Eternova' })

interface ReservationEvent { c: string; o: string; t: 'rose' | 'lilac' | 'cream' }

const selectedDay = ref(15)
const slideoverOpen = ref(false)

// June 2026: June 1 = Monday (index 1 in 0=Sun week)
const startOffset = 1
const totalDays = 30
const calCells = computed(() => {
    const cells: (number | null)[] = []
    for (let i = 0; i < startOffset; i++) cells.push(null)
    for (let d = 1; d <= totalDays; d++) cells.push(d)
    return cells
})

const events: Record<number, ReservationEvent[]> = {
    8: [{ c: 'Camila B.', o: 'Cumpleanos 30', t: 'rose' }],
    12: [{ c: 'Ana L.', o: 'Aniversario', t: 'lilac' }],
    15: [{ c: 'Sofia R.', o: 'Boda · ramos', t: 'rose' }, { c: 'Maria G.', o: 'Dia de la madre', t: 'cream' }],
    18: [{ c: 'Lucia P.', o: 'Baby shower', t: 'lilac' }],
    22: [{ c: 'Valeria C.', o: 'Aniversario empresa', t: 'rose' }],
    28: [{ c: 'Daniela M.', o: 'Graduacion', t: 'cream' }],
}


const selectedEvents = computed(() => events[selectedDay.value] ?? [])
const weekdays = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab']
</script>

<template>
    <div class="res-shell">
        <!-- Calendar -->
        <div class="card" style="padding: 20px">
            <div class="flex justify-between items-center mb-4">
                <p class="serif text-xl text-on-surface">Junio 2026</p>
                <div class="flex items-center gap-1">
                    <button class="btn-icon" aria-label="Mes anterior"><ChevronLeft :size="16" /></button>
                    <button class="btn-icon" aria-label="Mes siguiente"><ChevronRight :size="16" /></button>
                </div>
            </div>

            <!-- Weekday headers -->
            <div class="cal-grid mb-2">
                <div v-for="d in weekdays" :key="d" class="label text-center" style="font-size: 10px; padding: 4px 0">{{ d }}</div>
            </div>

            <!-- Day cells -->
            <div class="cal-grid">
                <div
                    v-for="(day, i) in calCells"
                    :key="i"
                    :class="[
                        'cal-cell',
                        { 'today': day === 5, 'has-event': day !== null && events[day]?.length > 0 },
                        day === selectedDay ? 'ring-2 ring-primary ring-inset' : '',
                        day === null ? 'bg-transparent' : '',
                    ]"
                    @click="day !== null ? selectedDay = day : undefined"
                >
                    <span v-if="day" class="text-xs font-semibold">{{ day }}</span>
                    <div v-if="day && events[day]" class="flex gap-0.5 mt-auto flex-wrap">
                        <span
                            v-for="(ev, j) in events[day].slice(0, 2)"
                            :key="j"
                            class="w-1.5 h-1.5 rounded-full"
                            :style="{ background: ev.t === 'rose' ? 'var(--primary)' : ev.t === 'lilac' ? 'var(--secondary)' : 'var(--warning)' }"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Day detail -->
        <div class="flex flex-col gap-4">
            <div class="flex justify-between items-center">
                <div>
                    <p class="label-gilt">Reservas</p>
                    <p class="serif text-xl text-on-surface">{{ selectedDay }} junio 2026</p>
                </div>
                <AppButton :icon="Plus" size="sm" @click="slideoverOpen = true">Nueva</AppButton>
            </div>

            <div v-if="selectedEvents.length === 0" class="rounded-xl p-8 text-center text-sm text-on-surface-variant" style="background: var(--surface-low)">
                Sin reservas para este dia.
            </div>

            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="ev in selectedEvents"
                    :key="ev.c"
                    class="rounded-xl p-4"
                    style="background: var(--surface-low)"
                >
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-1 h-10 rounded-full shrink-0" :style="{ background: ev.t === 'rose' ? 'var(--primary)' : ev.t === 'lilac' ? 'var(--secondary)' : 'var(--warning)' }" />
                        <div>
                            <p class="serif text-base text-on-surface">{{ ev.o }}</p>
                            <p class="text-xs text-on-surface-variant">{{ ev.c }}</p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <AppBadge :variant="ev.t === 'rose' ? 'primary' : 'neutral'" size="sm">Confirmada</AppBadge>
                        <AppBadge variant="neutral" size="sm">Sin adelanto</AppBadge>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- New reservation slideover -->
    <AppSlideover
        :model-value="slideoverOpen"
        title="Nueva reserva"
        @update:model-value="slideoverOpen = false"
    >
        <div class="flex flex-col gap-4">
            <div><label class="field-label">Cliente</label><input class="field mt-1.5" placeholder="Nombre del cliente..." /></div>
            <div><label class="field-label">Ocasion</label><input class="field mt-1.5" placeholder="Boda, cumpleanos, aniversario..." /></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="field-label">Fecha</label><input class="field mt-1.5" type="date" /></div>
                <div><label class="field-label">Hora</label><input class="field mt-1.5" type="time" /></div>
            </div>
            <div><label class="field-label">Adelanto</label><input class="field mt-1.5" placeholder="$0.00" /></div>
            <div><label class="field-label">Notas</label><textarea class="field mt-1.5" rows="3" placeholder="Detalles del pedido personalizado..." /></div>
        </div>
        <template #footer>
            <div class="flex gap-2.5">
                <AppButton variant="secondary" class="flex-1 justify-center" @click="slideoverOpen = false">Cancelar</AppButton>
                <AppButton class="flex-1 justify-center" @click="slideoverOpen = false">Guardar</AppButton>
            </div>
        </template>
    </AppSlideover>
</template>

<style scoped>
.res-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}
@media (min-width: 1024px) {
    .res-shell { grid-template-columns: 1fr 1fr; }
}
</style>
