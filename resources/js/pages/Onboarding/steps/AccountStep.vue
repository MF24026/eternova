<script setup lang="ts">
import { ref, computed } from 'vue'
import { UserIcon, MailIcon, LockIcon } from 'lucide-vue-next'
import AppInput from '@/components/base/AppInput.vue'
import AppButton from '@/components/base/AppButton.vue'
import { useOnboardingStore } from '@/stores/onboarding'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'

const store = useOnboardingStore()

const name = ref(store.account.name)
const email = ref(store.account.email)
const password = ref(store.account.password)

const submitting = ref(false)
const generalError = ref('')
const fieldErrors = ref<Record<string, string>>({})

const canSubmit = computed(() =>
    name.value.length >= 2 &&
    email.value.includes('@') &&
    password.value.length >= 8,
)

async function handleNext(): Promise<void> {
    submitting.value = true
    generalError.value = ''
    fieldErrors.value = {}

    try {
        // Register returns token in the response. AuthService.register does not
        // expose it — call the raw API so we can capture the plain_text_token.
        const { default: api } = await import('@/services/api')
        const response = await api.post<{
            data: { id: number; name: string; email: string }
            plain_text_token: string
            meta: { request_id: string }
        }>('/auth/register', {
            name: name.value,
            email: email.value,
            password: password.value,
        })

        // Stash the bearer token in the store so TenantStep can attach it on /api/v1/tenants.
        store.tempBearerToken = response.data.plain_text_token

        store.account = {
            name: name.value,
            email: email.value,
            password: password.value,
        }

        store.goTo('plan')
    } catch (err: unknown) {
        fieldErrors.value = extractFieldErrors(err)
        generalError.value = extractErrorMessage(err)
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <h2 class="font-serif text-2xl font-semibold text-on-surface tracking-tighter">
                Crea tu cuenta
            </h2>
            <p class="mt-1 text-sm text-on-surface-variant">
                En menos de 2 minutos tendras tu negocio en linea.
            </p>
        </div>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="handleNext">
            <AppInput
                id="account-name"
                v-model="name"
                type="text"
                label="Nombre completo"
                placeholder="Tu nombre"
                autocomplete="name"
                required
                :error="fieldErrors.name ?? ''"
            >
                <template #icon>
                    <UserIcon :size="16" />
                </template>
            </AppInput>

            <AppInput
                id="account-email"
                v-model="email"
                type="email"
                label="Correo electrónico"
                placeholder="tu@correo.com"
                autocomplete="email"
                required
                :error="fieldErrors.email ?? ''"
            >
                <template #icon>
                    <MailIcon :size="16" />
                </template>
            </AppInput>

            <AppInput
                id="account-password"
                v-model="password"
                type="password"
                label="Contraseña"
                placeholder="Mínimo 8 caracteres"
                autocomplete="new-password"
                required
                :error="fieldErrors.password ?? ''"
            >
                <template #icon>
                    <LockIcon :size="16" />
                </template>
            </AppInput>

            <p
                v-if="generalError && !Object.keys(fieldErrors).length"
                class="text-sm text-error"
                role="alert"
            >
                {{ generalError }}
            </p>

            <AppButton
                type="submit"
                :loading="submitting"
                :disabled="!canSubmit"
                class="mt-2 w-full"
            >
                Continuar
            </AppButton>
        </form>
    </div>
</template>
