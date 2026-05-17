<script setup lang="ts">
import { ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import {
    Flower, Phone, MapPin, Receipt, ClipboardList, FileText,
    Calendar, Bell, Camera, Pencil
} from 'lucide-vue-next'

defineOptions({ layout: AdminLayout })

type TabId = 'marca' | 'contacto' | 'local' | 'impuestos' | 'pedidos' | 'cotizaciones' | 'reservas' | 'notif'

interface TabItem {
    id: TabId
    label: string
    icon: object
}

const tabs: TabItem[] = [
    { id: 'marca', label: 'Marca', icon: Flower },
    { id: 'contacto', label: 'Contacto', icon: Phone },
    { id: 'local', label: 'Localización', icon: MapPin },
    { id: 'impuestos', label: 'Impuestos', icon: Receipt },
    { id: 'pedidos', label: 'Pedidos', icon: ClipboardList },
    { id: 'cotizaciones', label: 'Cotizaciones', icon: FileText },
    { id: 'reservas', label: 'Reservas', icon: Calendar },
    { id: 'notif', label: 'Notificaciones', icon: Bell },
]

const activeTab = ref<TabId>('marca')

const accentColors = [
    { hex: '#7c545d', label: 'Rosa apagada' },
    { hex: '#6a3f5c', label: 'Ciruela' },
    { hex: '#7c5c45', label: 'Bronce' },
    { hex: '#4a7c5e', label: 'Salvia' },
]
const selectedAccent = ref('#7c545d')

const orderStatuses = ref([
    { id: 'pendiente', label: 'Pendiente', tone: 'warning' },
    { id: 'preparando', label: 'Preparando', tone: 'info' },
    { id: 'listo', label: 'Listo', tone: 'primary' },
    { id: 'entregado', label: 'Entregado', tone: 'success' },
])

const notifEvents = [
    'Nuevo pedido recibido',
    'Pedido pendiente por más de 4 horas',
    'Stock bajo en producto',
    'Reserva confirmada',
    'Cotización aceptada',
    'Pago recibido',
]

const notifToggles = ref<Record<string, boolean>>({
    'Nuevo pedido recibido': true,
    'Pedido pendiente por más de 4 horas': true,
    'Stock bajo en producto': true,
    'Reserva confirmada': false,
    'Cotización aceptada': true,
    'Pago recibido': true,
})

function toneStatusBg(tone: string): string {
    return tone === 'primary' ? 'var(--primary-container)' : `var(--${tone}-container)`
}
function toneStatusColor(tone: string): string {
    return tone === 'primary' ? 'var(--primary)' : `var(--${tone})`
}
</script>

<template>
    <AdminLayout title="Ajustes" breadcrumb="Configuración">
        <div class="settings-shell">
            <!-- Vertical tabs nav -->
            <div class="card" style="background: var(--surface-low); padding: 16px; overflow-y: auto">
                <!-- Mobile: horizontal tabs -->
                <div class="settings-tabs-mobile">
                    <div class="tabs">
                        <button
                            v-for="t in tabs"
                            :key="t.id"
                            :class="['tab', { active: activeTab === t.id }]"
                            style="display: flex; align-items: center; gap: 6px"
                            @click="activeTab = t.id"
                        >
                            <component :is="t.icon" :size="14"/>
                            <span>{{ t.label }}</span>
                        </button>
                    </div>
                </div>
                <!-- Desktop: vertical nav -->
                <div class="settings-tabs-desktop stack" style="gap: 4px">
                    <button
                        v-for="t in tabs"
                        :key="t.id"
                        :class="['sidebar-item', { active: activeTab === t.id }]"
                        @click="activeTab = t.id"
                    >
                        <component :is="t.icon" :size="16"/>
                        <span class="label-txt">{{ t.label }}</span>
                    </button>
                </div>
            </div>

            <!-- Content panel -->
            <div class="card scroll" style="padding: 24px 32px">
                <!-- Marca -->
                <div v-if="activeTab === 'marca'" class="stack" style="gap: 24px">
                    <div>
                        <h2 class="serif" style="margin: 0; font-size: 28px">Identidad de marca</h2>
                        <p style="color: var(--on-surface-variant); font-size: 14px; margin-top: 6px">
                            Cómo te ven tus clientes en tienda, comprobantes y comunicaciones.
                        </p>
                    </div>
                    <div>
                        <label class="field-label">Logotipo</label>
                        <div class="row" style="gap: 16px">
                            <span style="width: 80px; height: 80px; border-radius: 50%; background: var(--gradient);
                                display: grid; place-items: center; color: var(--on-primary); flex-shrink: 0">
                                <Flower :size="36"/>
                            </span>
                            <div>
                                <button class="btn btn-tertiary">
                                    <Camera :size="14" style="margin-right: 6px"/> Cambiar
                                </button>
                                <p style="font-size: 12px; color: var(--on-surface-variant); margin-top: 8px">PNG o SVG · mínimo 512×512px</p>
                            </div>
                        </div>
                    </div>
                    <div class="settings-grid-2">
                        <div>
                            <label class="field-label">Nombre del negocio</label>
                            <input class="field" value="Carol Creaciones"/>
                        </div>
                        <div>
                            <label class="field-label">Tagline</label>
                            <input class="field" value="Hecho despacio, con manos que recuerdan"/>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Color de acento</label>
                        <div class="row" style="gap: 8px; flex-wrap: wrap">
                            <button
                                v-for="c in accentColors"
                                :key="c.hex"
                                :style="{
                                    display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '8px',
                                    padding: '8px', borderRadius: 'var(--r-lg)',
                                    background: c.hex === selectedAccent ? 'var(--surface-highest)' : 'transparent',
                                }"
                                @click="selectedAccent = c.hex"
                            >
                                <span :style="{
                                    width: '36px', height: '36px', borderRadius: '50%', background: c.hex,
                                    boxShadow: c.hex === selectedAccent
                                        ? '0 0 0 2px var(--surface-lowest), 0 0 0 4px var(--primary)'
                                        : 'none',
                                }"/>
                                <span style="font-size: 11px">{{ c.label }}</span>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Tipografía</label>
                        <div class="card" style="background: var(--surface-low); padding: 16px">
                            <div style="font-size: 11px; color: var(--on-surface-variant)">Titulares</div>
                            <div class="serif" style="font-size: 28px">Noto Serif</div>
                            <div style="font-size: 11px; color: var(--on-surface-variant); margin-top: 12px">Cuerpo</div>
                            <div style="font-size: 18px; font-weight: 500">Plus Jakarta Sans</div>
                        </div>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Contacto -->
                <div v-else-if="activeTab === 'contacto'" class="stack" style="gap: 18px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Información de contacto</h2>
                    <div class="settings-grid-2">
                        <div><label class="field-label">Teléfono principal</label><input class="field" value="+503 2225-7777"/></div>
                        <div><label class="field-label">WhatsApp</label><input class="field" value="+503 7892-1234"/></div>
                        <div><label class="field-label">Correo</label><input class="field" value="hola@carolcreaciones.sv"/></div>
                        <div><label class="field-label">Sitio web</label><input class="field" value="carolcreaciones.sv"/></div>
                    </div>
                    <div>
                        <label class="field-label">Dirección del atelier</label>
                        <input class="field" value="Calle Loma Linda 234, Col. San Benito, San Salvador"/>
                    </div>
                    <div>
                        <label class="field-label">Horario de atención</label>
                        <div class="stack" style="gap: 8px">
                            <div
                                v-for="(day, i) in ['Lunes a viernes', 'Sábado', 'Domingo']"
                                :key="day"
                                class="row"
                                style="gap: 12px; padding: 12px; background: var(--surface-low); border-radius: var(--r-md)"
                            >
                                <span style="flex: 1; font-size: 13px; font-weight: 600">{{ day }}</span>
                                <input
                                    class="field"
                                    style="width: 100px; padding: 8px 12px; background: var(--surface-lowest)"
                                    :value="i === 2 ? 'Cerrado' : i === 1 ? '10–14' : '9–18'"
                                />
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Localización -->
                <div v-else-if="activeTab === 'local'" class="stack" style="gap: 18px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Localización</h2>
                    <div class="settings-grid-2">
                        <div>
                            <label class="field-label">País</label>
                            <select class="field">
                                <option value="SV">El Salvador</option>
                                <option value="CO">Colombia</option>
                                <option value="MX">México</option>
                                <option value="AR">Argentina</option>
                                <option value="GT">Guatemala</option>
                                <option value="HN">Honduras</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Moneda</label>
                            <select class="field">
                                <option>USD ($)</option>
                                <option>COP ($)</option>
                                <option>MXN ($)</option>
                                <option>ARS ($)</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label">Idioma</label>
                            <select class="field"><option>Español</option></select>
                        </div>
                        <div>
                            <label class="field-label">Formato de teléfono</label>
                            <input class="field" value="+503 ####-####"/>
                        </div>
                        <div>
                            <label class="field-label">Formato de fecha</label>
                            <select class="field"><option>DD/MM/YYYY</option><option>MM/DD/YYYY</option><option>YYYY-MM-DD</option></select>
                        </div>
                        <div>
                            <label class="field-label">Zona horaria</label>
                            <select class="field">
                                <option>America/El_Salvador (UTC-6)</option>
                                <option>America/Bogota (UTC-5)</option>
                                <option>America/Mexico_City (UTC-6)</option>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Impuestos -->
                <div v-else-if="activeTab === 'impuestos'" class="stack" style="gap: 18px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Impuestos</h2>
                    <div class="settings-grid-2">
                        <div><label class="field-label">IVA por defecto</label><input class="field" value="13.00%"/></div>
                        <div><label class="field-label">NRC</label><input class="field" value="123456-7"/></div>
                        <div><label class="field-label">NIT</label><input class="field" value="0614-100190-101-1"/></div>
                        <div><label class="field-label">Giro</label><input class="field" value="Comercio de arreglos florales"/></div>
                    </div>
                    <div class="card" style="background: var(--surface-low); padding: 16px; display: flex; gap: 12px; align-items: center">
                        <input type="checkbox" checked style="accent-color: var(--primary); width: 18px; height: 18px; flex-shrink: 0"/>
                        <div>
                            <div style="font-size: 14px; font-weight: 600">Precios incluyen IVA</div>
                            <div style="font-size: 12px; color: var(--on-surface-variant)">Los precios mostrados ya incluyen el impuesto.</div>
                        </div>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Pedidos -->
                <div v-else-if="activeTab === 'pedidos'" class="stack" style="gap: 18px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Estados de pedido</h2>
                    <div class="stack" style="gap: 8px">
                        <div
                            v-for="(s, i) in orderStatuses"
                            :key="s.id"
                            class="row"
                            style="gap: 12px; padding: 14px; background: var(--surface-low); border-radius: var(--r-lg)"
                        >
                            <span :style="{
                                width: '28px', height: '28px', borderRadius: '50%',
                                background: toneStatusBg(s.tone),
                                color: toneStatusColor(s.tone),
                                display: 'grid', placeItems: 'center',
                                fontWeight: 700, fontSize: '12px', flexShrink: 0,
                            }">{{ i + 1 }}</span>
                            <input v-model="s.label" class="field" style="background: var(--surface-lowest); flex: 1"/>
                            <span :class="`bloom bloom-${s.tone === 'primary' ? 'primary' : s.tone}`" style="font-size: 11px">Tono {{ s.tone }}</span>
                            <button class="btn-icon"><Pencil :size="14"/></button>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Envío gratis desde</label>
                        <input class="field" style="max-width: 200px" value="$150.00"/>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Cotizaciones -->
                <div v-else-if="activeTab === 'cotizaciones'" class="stack" style="gap: 18px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Cotizaciones</h2>
                    <div class="settings-grid-2">
                        <div>
                            <label class="field-label">Días de validez por defecto</label>
                            <input class="field" value="15"/>
                        </div>
                        <div>
                            <label class="field-label">Términos por defecto</label>
                            <select class="field">
                                <option>50% anticipo, 50% al entregar</option>
                                <option>Pago contra entrega</option>
                                <option>30 días</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Texto al pie del PDF</label>
                        <textarea class="field" rows="3">Gracias por considerar a Carol Creaciones para este momento especial. Cada pieza se elabora a mano.</textarea>
                    </div>
                    <div>
                        <label class="field-label">Términos y condiciones default</label>
                        <textarea class="field" rows="4" placeholder="Incluir términos de pago, políticas de cancelación…"/>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Reservas -->
                <div v-else-if="activeTab === 'reservas'" class="stack" style="gap: 18px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Reservas</h2>
                    <div class="settings-grid-2">
                        <div>
                            <label class="field-label">Porcentaje mínimo de anticipo</label>
                            <input class="field" value="30%"/>
                        </div>
                        <div>
                            <label class="field-label">Días de antelación mínima</label>
                            <input class="field" value="3"/>
                        </div>
                        <div>
                            <label class="field-label">Días antes del recordatorio</label>
                            <input class="field" value="3"/>
                        </div>
                        <div>
                            <label class="field-label">Cancelación hasta (días antes)</label>
                            <input class="field" value="7"/>
                        </div>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>

                <!-- Notificaciones -->
                <div v-else-if="activeTab === 'notif'" class="stack" style="gap: 14px">
                    <h2 class="serif" style="margin: 0; font-size: 28px">Notificaciones</h2>
                    <div
                        v-for="n in notifEvents"
                        :key="n"
                        class="row"
                        style="gap: 12px; padding: 14px; background: var(--surface-low); border-radius: var(--r-lg)"
                    >
                        <Bell :size="16" style="color: var(--on-surface-variant); flex-shrink: 0"/>
                        <span style="flex: 1; font-size: 14px">{{ n }}</span>
                        <div class="row" style="gap: 8px">
                            <span class="bloom bloom-soft" style="font-size: 10px">App</span>
                            <button
                                class="bloom bloom-primary"
                                :style="{
                                    fontSize: '10px',
                                    opacity: notifToggles[n] ? 1 : 0.4,
                                    transition: 'opacity .2s',
                                    cursor: 'pointer',
                                }"
                                @click="notifToggles[n] = !notifToggles[n]"
                            >
                                WhatsApp
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-primary" style="align-self: flex-start">Guardar cambios</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.settings-shell {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
    height: calc(100vh - 120px);
    overflow: hidden;
}

.settings-grid-2 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}

.settings-tabs-mobile {
    display: block;
    overflow-x: auto;
}
.settings-tabs-desktop {
    display: none;
}

@media (min-width: 640px) {
    .settings-grid-2 {
        grid-template-columns: 1fr 1fr;
    }
}

@media (min-width: 1024px) {
    .settings-shell {
        grid-template-columns: 220px 1fr;
    }
    .settings-tabs-mobile {
        display: none;
    }
    .settings-tabs-desktop {
        display: flex;
    }
}
</style>
