<script setup lang="ts">
/**
 * SettingsPage (S8-E3/E4) — tenant configuration, wired to the Settings API.
 *
 * Eight groups across two backends (transparent to the user):
 *   - tenant-row:  Marca, Localización, Cotizaciones, Reservas
 *   - branch_settings default:  Contacto, Impuestos, Pedidos, Notificaciones
 *
 * Each tab saves its own group independently (POST /settings/{group}); the
 * response returns the full resolved settings so the local state stays in sync.
 */
import { ref, reactive, computed, onMounted } from 'vue'
import {
    Flower, Phone, MapPin, Receipt, ClipboardList, FileText, Calendar, Bell,
    Camera, Save, Loader2,
} from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import UpgradeLock from '@/components/composite/UpgradeLock.vue'
import SettingsService from '@/services/SettingsService'
import { useToast } from '@/composables/useToast'
import { usePlanGate } from '@/composables/usePlanGate'
import type {
    TenantSettings, SettingsCatalog, SettingsGroup,
} from '@/types/domain/Settings'

const toast = useToast()
const planGate = usePlanGate()

function onUpgrade(): void {
    toast.info('La gestión de plan estará disponible pronto.')
}

onMounted(() => {
    document.title = 'Ajustes — Eternova'
    void load()
})

// ── State ─────────────────────────────────────────────────────────────────────

type TabId = 'marca' | 'contacto' | 'local' | 'impuestos' | 'pedidos' | 'cotizaciones' | 'reservas' | 'notif'

const tabs: { id: TabId; label: string; icon: typeof Flower; group: SettingsGroup }[] = [
    { id: 'marca', label: 'Marca', icon: Flower, group: 'brand' },
    { id: 'contacto', label: 'Contacto', icon: Phone, group: 'contact' },
    { id: 'local', label: 'Localización', icon: MapPin, group: 'locale' },
    { id: 'impuestos', label: 'Impuestos', icon: Receipt, group: 'tax' },
    { id: 'pedidos', label: 'Pedidos', icon: ClipboardList, group: 'orders' },
    { id: 'cotizaciones', label: 'Cotizaciones', icon: FileText, group: 'quotations' },
    { id: 'reservas', label: 'Reservas', icon: Calendar, group: 'reservations' },
    { id: 'notif', label: 'Notificaciones', icon: Bell, group: 'notifications' },
]

const activeTab = ref<TabId>('marca')
const pageState = ref<'loading' | 'loaded' | 'error'>('loading')
const settings = ref<TenantSettings | null>(null)
const catalog = ref<SettingsCatalog | null>(null)

// Per-group saving + validation-error state.
const saving = reactive<Record<string, boolean>>({})
const errors = reactive<Record<string, Record<string, string>>>({})

// Brand logo upload (not part of the JSON settings object).
const logoFile = ref<File | null>(null)
const logoPreview = ref<string | null>(null)

// Reservation occasions edited as newline-separated text.
const occasionsText = ref('')

async function load(): Promise<void> {
    pageState.value = 'loading'
    try {
        const result = await SettingsService.getAll()
        settings.value = result.data
        catalog.value = result.meta.catalog
        occasionsText.value = (result.data.reservations.reservation_occasions ?? []).join('\n')
        pageState.value = 'loaded'
    } catch {
        pageState.value = 'error'
    }
}

// ── Derived: tax/quotation percent <-> bps ──────────────────────────────────────

const taxRatePercent = computed<string>({
    get: () => settings.value ? String(settings.value.tax.rate_bps / 100) : '0',
    set: (v) => { if (settings.value) settings.value.tax.rate_bps = bpsFromPercent(v) },
})

const quotationRatePercent = computed<string>({
    get: () => settings.value ? String(settings.value.quotations.quotation_tax_rate_bps / 100) : '0',
    set: (v) => { if (settings.value) settings.value.quotations.quotation_tax_rate_bps = bpsFromPercent(v) },
})

function bpsFromPercent(raw: string): number {
    const pct = parseFloat(String(raw).replace(',', '.'))
    if (isNaN(pct) || pct < 0) return 0
    return Math.min(9999, Math.round(pct * 100))
}

const countryOptions = computed(() => {
    if (!catalog.value) return []
    return Object.entries(catalog.value.countries).map(([code, c]) => ({ code, ...c }))
})

// ── Country change → suggest currency (i18n-latam) ──────────────────────────────

function onCountryChange(): void {
    if (!settings.value || !catalog.value) return
    const entry = catalog.value.countries[settings.value.locale.country_code]
    if (entry) settings.value.locale.currency = entry.currency
}

// ── Brand uploads ───────────────────────────────────────────────────────────────

function onLogoSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null
    logoFile.value = file
    logoPreview.value = file ? URL.createObjectURL(file) : null
}

// ── Save one group ───────────────────────────────────────────────────────────────

async function save(group: SettingsGroup): Promise<void> {
    if (!settings.value || saving[group]) return
    saving[group] = true
    errors[group] = {}

    try {
        const payload = buildPayload(group)
        const updated = await SettingsService.updateGroup(group, payload)
        settings.value = updated
        occasionsText.value = (updated.reservations.reservation_occasions ?? []).join('\n')
        // Clear transient brand upload state after a successful save.
        if (group === 'brand') {
            logoFile.value = null
            logoPreview.value = null
        }
        toast.success('Cambios guardados.')
    } catch (err: unknown) {
        const apiErr = err as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } }
        if (apiErr.response?.status === 422) {
            const serverErrors = apiErr.response.data?.errors ?? {}
            const mapped: Record<string, string> = {}
            for (const [field, messages] of Object.entries(serverErrors)) {
                mapped[field] = messages[0] ?? 'Campo inválido.'
            }
            errors[group] = mapped
            toast.error('Revisa los campos marcados.')
        } else {
            toast.error(apiErr.response?.data?.message ?? 'No se pudieron guardar los cambios.')
        }
    } finally {
        saving[group] = false
    }
}

function buildPayload(group: SettingsGroup): Record<string, unknown> | FormData {
    const s = settings.value!

    if (group === 'brand') {
        const form = new FormData()
        form.append('business_name', s.brand.business_name ?? '')
        if (s.brand.primary_color) form.append('primary_color', s.brand.primary_color)
        if (s.brand.secondary_color) form.append('secondary_color', s.brand.secondary_color)
        if (logoFile.value) form.append('logo', logoFile.value)
        return form
    }

    if (group === 'reservations') {
        return {
            reservation_deposit_pct: s.reservations.reservation_deposit_pct,
            reservation_occasions: occasionsText.value
                .split('\n')
                .map((o) => o.trim())
                .filter((o) => o.length > 0),
        }
    }

    // The rest send their group object verbatim.
    return { ...s[group] } as Record<string, unknown>
}

const accentSwatches = ['#7c545d', '#6a3f5c', '#7c5c45', '#4a7c5e', '#5a4b71']

const notificationLabels: { key: keyof TenantSettings['notifications']; label: string }[] = [
    { key: 'new_order', label: 'Nuevo pedido recibido' },
    { key: 'order_pending', label: 'Pedido pendiente por mucho tiempo' },
    { key: 'low_stock', label: 'Stock bajo en producto' },
    { key: 'reservation_confirmed', label: 'Reserva confirmada' },
    { key: 'quotation_accepted', label: 'Cotización aceptada' },
    { key: 'payment_received', label: 'Pago recibido' },
]
</script>

<template>
    <!-- Loading -->
    <div v-if="pageState === 'loading'" class="flex flex-col items-center justify-center py-32 gap-4" aria-busy="true">
        <AppSpinner size="lg" />
        <p class="text-sm text-on-surface-variant">Cargando ajustes…</p>
    </div>

    <!-- Error -->
    <div v-else-if="pageState === 'error'" class="flex flex-col items-center justify-center py-32 gap-5 text-center">
        <p class="serif text-2xl text-on-surface tracking-tighter">No se pudieron cargar los ajustes</p>
        <AppButton variant="secondary" @click="load">Reintentar</AppButton>
    </div>

    <!-- Loaded -->
    <div v-else-if="settings && catalog" class="settings-shell">
        <!-- Tab sidebar -->
        <aside class="settings-nav card" style="padding: 12px">
            <div class="flex flex-row lg:flex-col gap-1 overflow-x-auto lg:overflow-x-visible">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    :class="['settings-tab', { active: activeTab === tab.id }]"
                    :data-testid="`tab-${tab.id}`"
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" :size="16" class="shrink-0" />
                    <span class="whitespace-nowrap">{{ tab.label }}</span>
                </button>
            </div>
        </aside>

        <!-- Content -->
        <div class="flex flex-col gap-5">

            <!-- ── Marca ── -->
            <template v-if="activeTab === 'marca'">
                <div class="card" style="padding: 24px" data-testid="panel-marca">
                    <p class="font-semibold text-on-surface mb-4">Identidad visual</p>
                    <div class="flex items-center gap-5 mb-6">
                        <div class="w-20 h-20 rounded-2xl flex items-center justify-center shrink-0 overflow-hidden" style="background: var(--gradient-soft)">
                            <img v-if="logoPreview || settings.brand.logo_url" :src="logoPreview ?? settings.brand.logo_url ?? ''" alt="Logo" class="w-full h-full object-cover" />
                            <span v-else class="font-serif text-3xl text-primary font-semibold">{{ settings.brand.business_name?.charAt(0) ?? 'E' }}</span>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-on-surface mb-1">Logo del negocio</p>
                            <p class="text-xs text-on-surface-variant mb-2">PNG, JPG, SVG o WEBP · máx 2MB</p>
                            <label class="btn btn-tertiary text-xs py-1.5 px-3 cursor-pointer inline-flex items-center">
                                <Camera :size="12" class="mr-1.5" /> Cambiar logo
                                <input type="file" accept="image/*" class="hidden" data-testid="input-logo" @change="onLogoSelected" />
                            </label>
                        </div>
                    </div>
                    <div class="flex flex-col gap-4">
                        <AppInput v-model="settings.brand.business_name" label="Nombre del negocio" :error="errors.brand?.business_name" data-testid="input-business-name" />
                        <div>
                            <label class="field-label mb-2 block">Color de acento</label>
                            <div class="flex gap-2 flex-wrap items-center">
                                <button
                                    v-for="c in accentSwatches"
                                    :key="c"
                                    type="button"
                                    class="w-8 h-8 rounded-full transition-all duration-150"
                                    :style="{ background: c, boxShadow: settings.brand.primary_color === c ? `0 0 0 3px var(--surface), 0 0 0 5px ${c}` : 'none' }"
                                    @click="settings.brand.primary_color = c"
                                />
                                <input v-model="settings.brand.primary_color" type="color" class="w-8 h-8 rounded-full cursor-pointer bg-transparent" aria-label="Color personalizado" />
                            </div>
                            <p v-if="errors.brand?.primary_color" class="text-xs text-error mt-1">{{ errors.brand.primary_color }}</p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Contacto ── -->
            <template v-else-if="activeTab === 'contacto'">
                <div class="card" style="padding: 24px" data-testid="panel-contacto">
                    <p class="font-semibold text-on-surface mb-4">Información de contacto</p>
                    <div class="flex flex-col gap-4">
                        <AppInput v-model="settings.contact.phone" label="Teléfono principal" :error="errors.contact?.phone" data-testid="input-phone" />
                        <AppInput v-model="settings.contact.email" label="Correo electrónico" type="email" :error="errors.contact?.email" data-testid="input-email" />
                        <AppInput v-model="settings.contact.website" label="Sitio web" :error="errors.contact?.website" />
                        <div>
                            <label class="field-label">Dirección</label>
                            <textarea v-model="settings.contact.address" class="field mt-1.5" rows="2" data-testid="input-address"></textarea>
                            <p v-if="errors.contact?.address" class="text-xs text-error mt-1">{{ errors.contact.address }}</p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Localización ── -->
            <template v-else-if="activeTab === 'local'">
                <div class="card" style="padding: 24px" data-testid="panel-local">
                    <p class="font-semibold text-on-surface mb-4">Región y formato</p>
                    <div class="flex flex-col gap-4">
                        <div>
                            <label class="field-label">País</label>
                            <select v-model="settings.locale.country_code" class="field mt-1.5" data-testid="select-country" @change="onCountryChange">
                                <option v-for="c in countryOptions" :key="c.code" :value="c.code">{{ c.name }}</option>
                            </select>
                            <p v-if="errors.locale?.country_code" class="text-xs text-error mt-1">{{ errors.locale.country_code }}</p>
                        </div>
                        <div>
                            <label class="field-label">Moneda</label>
                            <select v-model="settings.locale.currency" class="field mt-1.5" data-testid="select-currency">
                                <option v-for="cur in catalog.currencies" :key="cur" :value="cur">{{ cur }}</option>
                            </select>
                            <p v-if="errors.locale?.currency" class="text-xs text-error mt-1">{{ errors.locale.currency }}</p>
                        </div>
                        <AppInput v-model="settings.locale.timezone" label="Zona horaria" :error="errors.locale?.timezone" data-testid="input-timezone" />
                    </div>
                </div>

                <!-- Custom domain — plan-gated (Enterprise) -->
                <div class="card" style="padding: 24px" data-testid="panel-custom-domain">
                    <p class="font-semibold text-on-surface mb-1">Dominio personalizado</p>
                    <p class="text-xs text-on-surface-variant mb-4">Usa tu propio dominio (ej. tienda.tunegocio.com) en el catálogo público.</p>
                    <UpgradeLock
                        :locked="!planGate.allows('custom_domain')"
                        title="Dominio personalizado"
                        required-plan="Enterprise"
                        @upgrade="onUpgrade"
                    >
                        <AppInput model-value="" label="Tu dominio" placeholder="tienda.tunegocio.com" data-testid="input-custom-domain" @update:model-value="() => {}" />
                    </UpgradeLock>
                </div>
            </template>

            <!-- ── Impuestos ── -->
            <template v-else-if="activeTab === 'impuestos'">
                <div class="card" style="padding: 24px" data-testid="panel-impuestos">
                    <p class="font-semibold text-on-surface mb-4">Configuración fiscal</p>
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between p-4 rounded-xl" style="background: var(--surface-low)">
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Aplicar IVA</p>
                                <p class="text-xs text-on-surface-variant">Agregar impuesto a las ventas</p>
                            </div>
                            <button
                                type="button" role="switch" :aria-checked="settings.tax.enabled" data-testid="toggle-tax"
                                class="w-12 h-6 rounded-full transition-colors duration-200 relative shrink-0"
                                :style="{ background: settings.tax.enabled ? 'var(--primary)' : 'var(--surface-high)' }"
                                @click="settings.tax.enabled = !settings.tax.enabled"
                            >
                                <span class="absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200" :class="settings.tax.enabled ? 'translate-x-7' : 'translate-x-1'" />
                            </button>
                        </div>
                        <AppInput v-model="taxRatePercent" label="Tasa de IVA (%)" type="number" help-text="Porcentaje sobre el subtotal" :error="errors.tax?.rate_bps" data-testid="input-tax-rate" />
                        <AppInput v-model="settings.tax.id_label" label="Etiqueta de identificación fiscal" help-text="Ej. NIT, RFC, RUC" :error="errors.tax?.id_label" />
                        <AppInput v-model="settings.tax.id_number" label="Número de identificación fiscal" :error="errors.tax?.id_number" />
                    </div>
                </div>
            </template>

            <!-- ── Pedidos ── -->
            <template v-else-if="activeTab === 'pedidos'">
                <div class="card" style="padding: 24px" data-testid="panel-pedidos">
                    <p class="font-semibold text-on-surface mb-4">Flujo de pedidos</p>
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center justify-between p-4 rounded-xl" style="background: var(--surface-low)">
                            <div>
                                <p class="text-sm font-semibold text-on-surface">Confirmar pedidos automáticamente</p>
                                <p class="text-xs text-on-surface-variant">Saltar el paso de confirmación manual</p>
                            </div>
                            <button
                                type="button" role="switch" :aria-checked="settings.orders.auto_confirm" data-testid="toggle-auto-confirm"
                                class="w-12 h-6 rounded-full transition-colors duration-200 relative shrink-0"
                                :style="{ background: settings.orders.auto_confirm ? 'var(--primary)' : 'var(--surface-high)' }"
                                @click="settings.orders.auto_confirm = !settings.orders.auto_confirm"
                            >
                                <span class="absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200" :class="settings.orders.auto_confirm ? 'translate-x-7' : 'translate-x-1'" />
                            </button>
                        </div>
                        <AppInput v-model.number="settings.orders.default_prep_minutes" label="Tiempo de preparación predeterminado (min)" type="number" :error="errors.orders?.default_prep_minutes" data-testid="input-prep" />
                        <AppInput v-model.number="settings.orders.pending_alert_hours" label="Alertar pedidos pendientes tras (horas)" type="number" :error="errors.orders?.pending_alert_hours" />
                    </div>
                </div>
            </template>

            <!-- ── Cotizaciones ── -->
            <template v-else-if="activeTab === 'cotizaciones'">
                <div class="card" style="padding: 24px" data-testid="panel-cotizaciones">
                    <p class="font-semibold text-on-surface mb-4">Valores predeterminados de cotización</p>
                    <div class="flex flex-col gap-4">
                        <AppInput v-model="quotationRatePercent" label="IVA predeterminado (%)" type="number" :error="errors.quotations?.quotation_tax_rate_bps" data-testid="input-quotation-rate" />
                        <AppInput v-model.number="settings.quotations.quotation_valid_days" label="Validez predeterminada (días)" type="number" :error="errors.quotations?.quotation_valid_days" data-testid="input-valid-days" />
                        <div>
                            <label class="field-label">Términos y condiciones predeterminados</label>
                            <textarea v-model="settings.quotations.quotation_terms" class="field mt-1.5" rows="3" data-testid="input-quotation-terms"></textarea>
                            <p v-if="errors.quotations?.quotation_terms" class="text-xs text-error mt-1">{{ errors.quotations.quotation_terms }}</p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Reservas ── -->
            <template v-else-if="activeTab === 'reservas'">
                <div class="card" style="padding: 24px" data-testid="panel-reservas">
                    <p class="font-semibold text-on-surface mb-4">Configuración de reservas</p>
                    <div class="flex flex-col gap-4">
                        <AppInput v-model.number="settings.reservations.reservation_deposit_pct" label="Anticipo requerido (%)" type="number" help-text="Porcentaje del total a solicitar como adelanto" :error="errors.reservations?.reservation_deposit_pct" data-testid="input-deposit-pct" />
                        <div>
                            <label class="field-label">Ocasiones (una por línea)</label>
                            <textarea v-model="occasionsText" class="field mt-1.5" rows="5" data-testid="input-occasions" placeholder="Boda&#10;Cumpleaños&#10;Aniversario"></textarea>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ── Notificaciones ── -->
            <template v-else-if="activeTab === 'notif'">
                <div class="card" style="padding: 24px" data-testid="panel-notif">
                    <p class="font-semibold text-on-surface mb-4">Alertas y notificaciones</p>
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="item in notificationLabels"
                            :key="item.key"
                            class="flex items-center justify-between p-3.5 rounded-xl"
                            style="background: var(--surface-low)"
                        >
                            <p class="text-sm text-on-surface">{{ item.label }}</p>
                            <button
                                type="button" role="switch" :aria-checked="settings.notifications[item.key]" :data-testid="`toggle-${item.key}`"
                                class="w-11 h-6 rounded-full transition-colors duration-200 relative shrink-0"
                                :style="{ background: settings.notifications[item.key] ? 'var(--primary)' : 'var(--surface-high)' }"
                                @click="settings.notifications[item.key] = !settings.notifications[item.key]"
                            >
                                <span class="absolute top-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200" :class="settings.notifications[item.key] ? 'translate-x-6' : 'translate-x-1'" />
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Save bar -->
            <div class="flex justify-end">
                <AppButton
                    :icon="saving[tabs.find(t => t.id === activeTab)!.group] ? Loader2 : Save"
                    size="sm"
                    data-testid="btn-save"
                    :disabled="saving[tabs.find(t => t.id === activeTab)!.group]"
                    @click="save(tabs.find(t => t.id === activeTab)!.group)"
                >
                    {{ saving[tabs.find(t => t.id === activeTab)!.group] ? 'Guardando…' : 'Guardar cambios' }}
                </AppButton>
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
