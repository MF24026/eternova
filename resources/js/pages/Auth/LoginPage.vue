<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'
import AppButton from '@/components/base/AppButton.vue'
import AppInput from '@/components/base/AppInput.vue'
import AppCard from '@/components/base/AppCard.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

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
        await authStore.login(email.value, password.value)
        const redirect = typeof route.query.redirect === 'string'
            ? route.query.redirect
            : '/admin/dashboard'
        await router.push(redirect)
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
                    Iniciar sesion
                </h2>

                <form class="flex flex-col gap-4" novalidate @submit.prevent="handleSubmit">
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
                        placeholder="••••••••"
                        autocomplete="current-password"
                        :error="fieldErrors.password ?? ''"
                    />

                    <p v-if="generalError && !Object.keys(fieldErrors).length" class="text-sm text-error">
                        {{ generalError }}
                    </p>

                    <AppButton type="submit" :loading="submitting" class="mt-2">
                        Entrar
                    </AppButton>
                </form>

                <p class="mt-5 text-center text-sm text-on-surface-variant">
                    Sin cuenta?
                    <router-link :to="{ name: 'signup' }" class="text-primary hover:underline font-medium">
                        Crear una gratis
                    </router-link>
                </p>
            </AppCard>
        </div>
    </div>
</template>
