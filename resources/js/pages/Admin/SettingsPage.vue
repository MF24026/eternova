<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { Flower, Phone, MapPin, Receipt, ClipboardList, FileText, Calendar, Bell, Camera, Save } from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'

onMounted(() => { document.title = 'Ajustes — Eternova' })

type TabId = 'marca' | 'contacto' | 'local' | 'impuestos' | 'pedidos' | 'cotizaciones' | 'reservas' | 'notif'

const tabs = [
    { id: 'marca' as const, label: 'Marca', icon: Flower },
    { id: 'contacto' as const, label: 'Contacto', icon: Phone },
    { id: 'local' as const, label: 'Localizacion', icon: MapPin },
    { id: 'impuestos' as const, label: 'Impuestos', icon: Receipt },
    { id: 'pedidos' as const, label: 'Pedidos', icon: ClipboardList },
    { id: 'cotizaciones' as const, label: 'Cotizaciones', icon: FileText },
    { id: 'reservas' as const, label: 'Reservas', icon: Calendar },
    { id: 'notif' as const, label: 'Notificaciones', icon: Bell },
]
const activeTab = ref<TabId>('marca')

const accentColors = [
    { hex: '#7c545d', label: 'Rosa apagada' },
    { hex: '#6a3f5c', label: 'Ciruela' },
    { hex: '#7c5c45', label: 'Bronce' },
    { hex: '#4a7c5e', label: 'Salvia' },
]
const selectedAccent = ref('#7c545d')
const businessName = ref('Carol Creaciones')
const taxEnabled = ref(true)
const taxRate = ref('13')
const currency = ref('USD')
const timezone = ref('America/El_Salvador')

const notifEvents = [
    'Nuevo pedido recibido',
    'Pedido pendiente por mas de 4 horas',
    'Stock bajo en producto',
    'Reserva confirmada',
    'Cotizacion aceptada',
    'Pago recibido',
]
const notifToggles = ref<Record<string, boolean>>({
    'Nuevo pedido recibido': true,
    'Pedido pendiente por mas de 4 horas': true,
    'Stock bajo en producto': true,
    'Reserva confirmada': false,
    'Cotizacion aceptada': true,
    'Pago recibido': true,
})
</script>

<template>
    <div class="settings-shell">
        <!-- Tab sidebar -->
        <aside class="settings-nav card" style="padding: 12px">
            <div class="flex flex-row lg:flex-col gap-1 overflow-x-auto lg:overflow-x-visible scroll">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    :class="['settings-tab', { active: activeTab === tab.id }]"
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" :size="16" class="shrink-0" />
                    <span class="whitespace-nowrap">{{ tab.label }}</span>
                </button>
            </div>
        </aside>

        <!-- Settings content -->
        <div class="flex flex-col gap-5">
            <!-- Marca -->
            <template v-if="activeTab === 'marca'">
                <div class="card" style="padding: 24px">
                    <p class="font-semibold text-on-surface mb-4">Identidad visual</p>
                    <div class="flex items-center gap-5 mb-6">
                        <div class="w-20 h-20 rounded-2xl flex items-center justify-center shrink-0" style="background: var(--gradient-soft)">
                            <span class="font-serif text-3xl text-primary font-semibold">C</span>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-on-surface mb-1">Logo del negocio</p>
                            <p class="text-xs text-on-surface-variant mb-2">PNG, JPG o SVG · max 2MB</p>
                            <button class="btn btn-tertiary text-xs py-1.5 px-3">
                                <Camera :size="12" class="mr-1.5" /> Cambiar logo
                            </button>
                        </div>
                    </div>
                    <div class="flex flex-col gap-4">
                        <AppInput v-model="businessName" label="Nombre del negocio" />
                        <div>
                            <label class="field-label mb-2 block">Color de acento</label>
                            <div class="flex gap-2 flex-wrap">
                                <button
                                    v-for="c in accentColors"
                                    :key="c.hex"
                                    :title="c.label"
                                    class="w-8 h-8 rounded-full transition-all duration-150"
                                    :style="{ background: c.hex, boxShadow: selectedAccent === c.hex ? `0 0 0 3px white, 0 0 0 5px ${c.hex}` : 'none' }"
                                    @click="selectedAccent = c.hex"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Contacto -->
            <template v-else-if="activeTab === 'contacto'">
                <div class="card" style="padding: 24px">
                    <p class="font-semibold text-on-surface mb-4">Informacion de contacto</p>
                    <div class="flex flex-col gap-4">
                        <AppInput model-value="+503 7892-1234" label="Telefono principal" @update:model-value="() => {}" />
                        <AppInput model-value="hola@carolcreaciones.sv" label="Correo electronico" type="email" @update:model-value="() => {}" />
                        <AppInput model-value="carolcreaciones.sv" label="Sitio web" @update:model-value="() => {}" />
                        <div>
                            <label class="field-label">Direccion</label>
                            <textarea class="field mt-1.5" rows="2">Col. Escalon, Calle La Masferrer #25, San Salvador</textarea>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Localizacion -->
            <template v-else-if="activeTab === 'local'">
                <div class="card" style="padding: 24px">
                    <p class="font-semibold text-on-surface mb-4">Region y formato</p>
                    <div class="flex flex-col gap-4">
                        <div>
                            <label class="field-label">Pais</label>
                            <select class="field mt-1.5">
                                <option>El Salvador</option>
                                <option>Colombia</option>
                                <option>Mexico</option>
                            </select>
                        </div>
                        <AppInput v-model="timezone" label="Zona horaria" />
                        <div>
                            <label class="field-label">Moneda</label>
                            <select v-model="currency" class="field mt-1.5">
                                <option value="USD">USD — Dolar estadounidense</option>
                                <option value="COP">COP — Peso colombiano</option>
                                <option value="MXN">MXN — Peso mexicano</option>
                            </select>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Impuestos -->
            <template v-else-if="activeTab === 'impuestos'">
                <div class="card" style="padding: 24px">
                    <p class="font-semibold text-on-surface mb-4">Configuracion fiscal</p>
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between p-4 rounded-xl" style="background: var(--surface-low)">
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Aplicar IVA</p>
                                <p class="text-xs text-on-surface-variant">Agregar impuesto a las ventas</p>
                            </div>
                            <button
                                class="w-12 h-6 rounded-full transition-colors duration-200 relative"
                                :style="{ background: taxEnabled ? 'var(--primary)' : 'var(--surface-high)' }"
                                :aria-checked="taxEnabled"
                                role="switch"
                                @click="taxEnabled = !taxEnabled"
                            >
                                <span class="absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200" :class="taxEnabled ? 'translate-x-7' : 'translate-x-1'" />
                            </button>
                        </div>
                        <AppInput v-model="taxRate" label="Tasa de IVA (%)" type="number" help-text="Porcentaje a aplicar sobre el subtotal" />
                    </div>
                </div>
            </template>

            <!-- Notificaciones -->
            <template v-else-if="activeTab === 'notif'">
                <div class="card" style="padding: 24px">
                    <p class="font-semibold text-on-surface mb-4">Alertas y notificaciones</p>
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="event in notifEvents"
                            :key="event"
                            class="flex items-center justify-between p-3.5 rounded-xl"
                            style="background: var(--surface-low)"
                        >
                            <p class="text-sm text-on-surface">{{ event }}</p>
                            <button
                                class="w-11 h-6 rounded-full transition-colors duration-200 relative shrink-0"
                                :style="{ background: notifToggles[event] ? 'var(--primary)' : 'var(--surface-high)' }"
                                :aria-checked="notifToggles[event]"
                                role="switch"
                                @click="notifToggles[event] = !notifToggles[event]"
                            >
                                <span class="absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200" :class="notifToggles[event] ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Generic placeholder for other tabs -->
            <template v-else>
                <div class="card" style="padding: 24px">
                    <p class="font-semibold text-on-surface mb-2">{{ tabs.find(t => t.id === activeTab)?.label }}</p>
                    <p class="text-sm text-on-surface-variant">Configuracion disponible en Sprint 8.</p>
                </div>
            </template>

            <!-- Save bar -->
            <div class="flex justify-end">
                <AppButton :icon="Save" size="sm">Guardar cambios</AppButton>
            </div>
        </div>
    </div>
</template>

<style scoped>
.settings-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}
.settings-nav { height: fit-content; }
.settings-tab {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border-radius: var(--r-lg);
    font-size: 13px;
    font-weight: 500;
    color: var(--on-surface-variant);
    transition: background 0.2s, color 0.2s;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none;
    width: 100%;
}
.settings-tab:hover { background: var(--surface-mid); color: var(--on-surface); }
.settings-tab.active { background: var(--surface-highest); color: var(--primary); font-weight: 600; }
@media (min-width: 1024px) {
    .settings-shell { grid-template-columns: 200px 1fr; align-items: start; }
}
</style>
