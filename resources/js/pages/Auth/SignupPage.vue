<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthService from '@/services/AuthService'
import { useAuthStore } from '@/stores/auth'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppCard from '@/components/base/AppCard.vue'

const router = useRouter()
const authStore = useAuthStore()

const name = ref('')
const email = ref('')
const password = ref('')
const submitting = ref(false)
const generalError = ref('')
const fieldErrors = ref<Record<string, string>>({})

async function handleSubmit() {
    submitting.value = true
    generalError.value = ''
    fieldErrors.value = {}

    try {
        await AuthService.register(name.value, email.value, password.value)
        // After registration, hydrate the user session via login cookie flow.
        await authStore.login(email.value, password.value)
        await router.push({ name: 'admin.dashboard' })
    } catch (err: unknown) {
        fieldErrors.value = extractFieldErrors(err)
        generalError.value = extractErrorMessage(err)
    } finally {
        submitting.value = false
    }
}
</script>

<template>
    <div class="min-h-screen bg-surface flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-sm">
            <router-link
                :to="{ name: 'home' }"
                class="block text-center font-serif font-semibold text-primary tracking-tighter text-2xl mb-8"
            >
                Eternova
            </router-link>

            <AppCard>
                <h2 class="text-lg font-semibold text-on-surface mb-6">
                    Crear cuenta
                </h2>

                <form class="flex flex-col gap-4" novalidate @submit.prevent="handleSubmit">
                    <AppInput
                        id="name"
                        v-model="name"
                        type="text"
                        label="Nombre completo"
                        placeholder="Tu nombre"
                        autocomplete="name"
                        :error="fieldErrors.name ?? ''"
                    />

                    <AppInput
                        id="email"
                        v-model="email"
                        type="email"
                        label="Correo electronico"
                        placeholder="tu@correo.com"
                        autocomplete="email"
                        :error="fieldErrors.email ?? ''"
                    />

                    <AppInput
                        id="password"
                        v-model="password"
                        type="password"
                        label="Contrasena"
                        placeholder="Minimo 8 caracteres"
                        autocomplete="new-password"
                        :error="fieldErrors.password ?? ''"
                    />

                    <p v-if="generalError && !Object.keys(fieldErrors).length" class="text-sm text-error">
                        {{ generalError }}
                    </p>

                    <AppButton type="submit" :loading="submitting" class="mt-2">
                        Crear cuenta
                    </AppButton>
                </form>

                <p class="mt-5 text-center text-sm text-on-surface-variant">
                    Ya tienes cuenta?
                    <router-link :to="{ name: 'login' }" class="text-primary hover:underline font-medium">
                        Iniciar sesion
                    </router-link>
                </p>
            </AppCard>
        </div>
    </div>
</template>
