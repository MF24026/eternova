<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ArrowRight, Loader2, Eye, EyeOff } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'
import { useFieldValidation } from '@/composables/useFieldValidation'
import { required, email as emailRule } from '@/utils/validators'
import AuthShell from '@/components/layout/AuthShell.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const showPassword = ref(false)
const generalError = ref('')
const fieldErrors = ref<Record<string, string>>({})

// Client-side on-blur feedback. The server response stays the source of truth
// (its field errors merge into the display); this just catches the obvious
// mistakes before a round-trip.
const v = useFieldValidation({
    email: [required, emailRule],
    password: [required],
})

async function handleSubmit() {
    if (!v.validateAll({ email: email.value, password: password.value })) {
        return
    }

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
            Tu jardín te espera. Ingresa con tus credenciales para continuar.
        </p>

        <form class="auth-form" novalidate @submit.prevent="handleSubmit">
            <div class="auth-field-group">
                <label for="email" class="field-label">Correo electrónico</label>
                <input
                    id="email"
                    v-model="email"
                    type="email"
                    class="field"
                    placeholder="tu@correo.com"
                    autocomplete="email"
                    :aria-invalid="!!(v.errors.email || fieldErrors.email)"
                    @blur="v.validateField('email', email)"
                >
                <p v-if="v.errors.email || fieldErrors.email" class="auth-field-error" role="alert" data-testid="error-email">{{ v.errors.email || fieldErrors.email }}</p>
            </div>

            <div class="auth-field-group">
                <label for="password" class="field-label">Contraseña</label>
                <div class="relative">
                    <input
                        id="password"
                        v-model="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="field"
                        style="padding-right: 2.75rem"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        :aria-invalid="!!(v.errors.password || fieldErrors.password)"
                        @blur="v.validateField('password', password)"
                    >
                    <button
                        type="button"
                        class="absolute inset-y-0 right-0 flex items-center px-3 text-on-surface-variant hover:text-on-surface transition-colors"
                        :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                        :aria-pressed="showPassword"
                        data-testid="toggle-password"
                        @click="showPassword = !showPassword"
                    >
                        <component :is="showPassword ? EyeOff : Eye" :size="18" aria-hidden="true" />
                    </button>
                </div>
                <p v-if="v.errors.password || fieldErrors.password" class="auth-field-error" role="alert" data-testid="error-password">{{ v.errors.password || fieldErrors.password }}</p>
                <router-link :to="{ name: 'forgot-password' }" class="auth-forgot-link" data-testid="forgot-link">
                    Olvidaste tu contraseña?
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
