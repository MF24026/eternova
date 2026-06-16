<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowRight, Loader2 } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'
import AuthShell from '@/components/layout/AuthShell.vue'

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
    <AuthShell>
        <p class="label-gilt">Panel del negocio</p>
        <h1 class="serif auth-title">Bienvenido<br>de nuevo.</h1>
        <p class="auth-subtitle">
            Tu jardin te espera. Ingresa con tus credenciales para continuar.
        </p>

        <form class="auth-form" novalidate @submit.prevent="handleSubmit">
            <div class="auth-field-group">
                <label for="email" class="field-label">Correo electronico</label>
                <input
                    id="email"
                    v-model="email"
                    type="email"
                    class="field"
                    placeholder="tu@correo.com"
                    autocomplete="email"
                    :aria-invalid="!!fieldErrors.email"
                >
                <p v-if="fieldErrors.email" class="auth-field-error" role="alert">{{ fieldErrors.email }}</p>
            </div>

            <div class="auth-field-group">
                <label for="password" class="field-label">Contrasena</label>
                <input
                    id="password"
                    v-model="password"
                    type="password"
                    class="field"
                    placeholder="••••••••"
                    autocomplete="current-password"
                    :aria-invalid="!!fieldErrors.password"
                >
                <p v-if="fieldErrors.password" class="auth-field-error" role="alert">{{ fieldErrors.password }}</p>
                <router-link :to="{ name: 'forgot-password' }" class="auth-forgot-link" data-testid="forgot-link">
                    Olvidaste tu contrasena?
                </router-link>
            </div>

            <p v-if="generalError && !Object.keys(fieldErrors).length" class="auth-field-error" role="alert">
                {{ generalError }}
            </p>

            <button type="submit" class="btn btn-primary auth-submit" :disabled="submitting">
                <Loader2 v-if="submitting" :size="16" class="auth-spin" />
                <template v-else>
                    Entrar
                    <ArrowRight :size="16" />
                </template>
            </button>
        </form>

        <p class="auth-footer">
            Sin cuenta?
            <router-link :to="{ name: 'signup' }" class="auth-link">Crear una gratis</router-link>
        </p>
    </AuthShell>
</template>
