<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { CheckIcon, XIcon, BuildingIcon, GlobeIcon } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import { useOnboardingStore } from '@/stores/onboarding'
import TenantsService from '@/services/TenantsService'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'

const store = useOnboardingStore()

interface CountryOption {
    code: string
    label: string
    currency: string
    timezone: string
}

const countries: CountryOption[] = [
    { code: 'SV', label: 'El Salvador',       currency: 'USD', timezone: 'America/El_Salvador' },
    { code: 'CO', label: 'Colombia',           currency: 'COP', timezone: 'America/Bogota' },
    { code: 'MX', label: 'Mexico',             currency: 'MXN', timezone: 'America/Mexico_City' },
    { code: 'GT', label: 'Guatemala',          currency: 'GTQ', timezone: 'America/Guatemala' },
    { code: 'HN', label: 'Honduras',           currency: 'HNL', timezone: 'America/Tegucigalpa' },
    { code: 'US', label: 'Estados Unidos',     currency: 'USD', timezone: 'America/New_York' },
]

const businessName = ref(store.tenantData.business_name)
const slug = ref(store.tenantData.slug)
const selectedCountry = ref(store.tenantData.country_code)

type SlugStatus = 'idle' | 'checking' | 'available' | 'unavailable'
const slugStatus = ref<SlugStatus>('idle')
const slugReason = ref<'format' | 'reserved' | 'taken' | null>(null)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

const reasonLabels: Record<NonNullable<typeof slugReason.value>, string> = {
    format: 'Formato invalido',
    reserved: 'Reservado',
    taken: 'Ya esta en uso',
}

// Auto-derive slug from business name when slug is empty
watch(businessName, (val) => {
    if (slug.value === '') {
        const derived = val
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 40)
        slug.value = derived
    }
})

watch(slug, (val) => {
    if (val.length === 0) {
        slugStatus.value = 'idle'
        slugReason.value = null
        return
    }

    slugStatus.value = 'checking'
    slugReason.value = null

    if (debounceTimer !== null) clearTimeout(debounceTimer)

    debounceTimer = setTimeout(async () => {
        try {
            const result = await TenantsService.checkSlug(val)
            if (result.available) {
                slugStatus.value = 'available'
                slugReason.value = null
            } else {
                slugStatus.value = 'unavailable'
                slugReason.value = result.reason ?? null
            }
        } catch {
            slugStatus.value = 'idle'
        }
    }, 400)
})

function handleCountryChange(code: string): void {
    selectedCountry.value = code
    const country = countries.find(c => c.code === code)
    if (country) {
        store.tenantData.currency = country.currency
        store.tenantData.timezone = country.timezone
    }
}

const submitting = ref(false)
const fieldErrors = ref<Record<string, string>>({})
const generalError = ref('')

const canSubmit = computed(() =>
    businessName.value.length >= 2 &&
    slug.value.length >= 3 &&
    slugStatus.value === 'available',
)

async function handleSubmit(): Promise<void> {
    submitting.value = true
    generalError.value = ''
    fieldErrors.value = {}

    const country = countries.find(c => c.code === selectedCountry.value)

    try {
        const token = store.tempBearerToken ?? ''
        if (!token) {
            throw new Error('Onboarding state lost: missing bearer token. Return to step 1.')
        }
        const { default: api } = await import('@/services/api')

        const response = await api.post<{
            data: { slug: string; id: string }
            meta: { request_id: string }
        }>(
            '/tenants',
            {
                name: businessName.value,
                slug: slug.value,
                business_name: businessName.value,
                country_code: selectedCountry.value,
                currency: country?.currency ?? 'USD',
                language: 'es',
                timezone: country?.timezone ?? 'America/El_Salvador',
                plan_slug: store.planChoice.plan_slug,
                billing_cycle: store.planChoice.billing,
            },
            { headers: { Authorization: `Bearer ${token}` } },
        )

        store.tenantData = {
            slug: slug.value,
            name: businessName.value,
            business_name: businessName.value,
            country_code: selectedCountry.value,
            currency: country?.currency ?? 'USD',
            language: 'es',
            timezone: country?.timezone ?? 'America/El_Salvador',
        }

        store.createdTenantSlug = response.data.data.slug
        store.goTo('done')
    } catch (err: unknown) {
        fieldErrors.value = extractFieldErrors(err)
        generalError.value = extractErrorMessage(err)
    } finally {
        submitting.value = false
    }
}

function handleBack(): void {
    store.goTo('plan')
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <h2 class="font-serif text-2xl font-semibold text-on-surface tracking-tighter">
                Configura tu negocio
            </h2>
            <p class="mt-1 text-sm text-on-surface-variant">
                Puedes cambiar estos datos mas adelante en Configuracion.
            </p>
        </div>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="handleSubmit">
            <!-- Business name -->
            <AppInput
                id="tenant-business-name"
                v-model="businessName"
                type="text"
                label="Nombre de tu negocio"
                placeholder="Ej. Floristeria Rosa Eterna"
                autocomplete="organization"
                required
                :error="fieldErrors.business_name ?? fieldErrors.name ?? ''"
            >
                <template #icon>
                    <BuildingIcon :size="16" />
                </template>
            </AppInput>

            <!-- Slug field with live availability indicator -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="tenant-slug"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Subdominio
                    <span class="text-error text-xs" aria-hidden="true">*</span>
                </label>
                <div class="relative">
                    <div class="flex items-center">
                        <div class="relative flex-1">
                            <input
                                id="tenant-slug"
                                v-model="slug"
                                type="text"
                                placeholder="mi-floristeria"
                                autocomplete="off"
                                aria-describedby="tenant-slug-status"
                                :aria-invalid="slugStatus === 'unavailable' || !!fieldErrors.slug"
                                class="w-full px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm placeholder:text-on-surface-variant/50 transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary/30 dark:bg-surface-mid pr-10"
                                :class="[
                                    slugStatus === 'available' ? 'ring-2 ring-success/60' : '',
                                    slugStatus === 'unavailable' || fieldErrors.slug ? 'ring-2 ring-error/60' : '',
                                ]"
                            />
                            <!-- Status icon inside input -->
                            <span
                                class="absolute right-3 top-1/2 -translate-y-1/2"
                                aria-hidden="true"
                            >
                                <AppSpinner v-if="slugStatus === 'checking'" size="sm" />
                                <CheckIcon
                                    v-else-if="slugStatus === 'available'"
                                    :size="16"
                                    class="text-success"
                                />
                                <XIcon
                                    v-else-if="slugStatus === 'unavailable'"
                                    :size="16"
                                    class="text-error"
                                />
                            </span>
                        </div>
                        <span class="ml-2 text-xs text-on-surface-variant whitespace-nowrap">
                            .eternova.app
                        </span>
                    </div>

                    <p
                        id="tenant-slug-status"
                        class="mt-1 text-xs"
                        :class="{
                            'text-success': slugStatus === 'available',
                            'text-error': slugStatus === 'unavailable' || !!fieldErrors.slug,
                            'text-on-surface-variant': slugStatus === 'idle' || slugStatus === 'checking',
                        }"
                    >
                        <template v-if="slugStatus === 'available'">Disponible</template>
                        <template v-else-if="slugStatus === 'unavailable' && slugReason">
                            {{ reasonLabels[slugReason] }}
                        </template>
                        <template v-else-if="fieldErrors.slug">{{ fieldErrors.slug }}</template>
                        <template v-else>Tu tienda estara en tu-nombre.eternova.app</template>
                    </p>
                </div>
            </div>

            <!-- Country -->
            <div class="flex flex-col gap-1.5">
                <label
                    for="tenant-country"
                    class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant"
                >
                    Pais
                    <span class="text-error text-xs" aria-hidden="true">*</span>
                </label>
                <div class="relative">
                    <span
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none"
                        aria-hidden="true"
                    >
                        <GlobeIcon :size="16" />
                    </span>
                    <select
                        id="tenant-country"
                        :value="selectedCountry"
                        class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm transition-colors duration-150 focus:outline-none focus:ring-2 focus:ring-primary/30 dark:bg-surface-mid"
                        @change="handleCountryChange(($event.target as HTMLSelectElement).value)"
                    >
                        <option
                            v-for="country in countries"
                            :key="country.code"
                            :value="country.code"
                        >
                            {{ country.label }}
                        </option>
                    </select>
                </div>
            </div>

            <p
                v-if="generalError && !Object.keys(fieldErrors).length"
                class="text-sm text-error"
                role="alert"
            >
                {{ generalError }}
            </p>

            <div class="flex gap-3 mt-2">
                <AppButton
                    type="button"
                    variant="secondary"
                    class="flex-1"
                    @click="handleBack"
                >
                    Atras
                </AppButton>
                <AppButton
                    type="submit"
                    :loading="submitting"
                    :disabled="!canSubmit"
                    class="flex-1"
                >
                    Crear negocio
                </AppButton>
            </div>
        </form>
    </div>
</template>
