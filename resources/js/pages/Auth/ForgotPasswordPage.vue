<script setup lang="ts">
import { ref } from 'vue'
import { ArrowLeft, Loader2, MailCheck } from 'lucide-vue-next'
import AuthService from '@/services/AuthService'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'
import AuthShell from '@/components/layout/AuthShell.vue'

const email = ref('')
const submitting = ref(false)
const sent = ref(false)
const generalError = ref('')
const fieldErrors = ref<Record<string, string>>({})

async function handleSubmit() {
    submitting.value = true
    generalError.value = ''
    fieldErrors.value = {}

    try {
        await AuthService.forgotPassword(email.value)
        // The API responds the same whether or not the email exists, so we always
        // show the confirmation state (no user enumeration).
        sent.value = true
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
        <template v-if="!sent">
            <p class="label-gilt">Recuperar acceso</p>
            <h1 class="serif auth-title">Olvidaste tu<br>contraseña?</h1>
            <p class="auth-subtitle">
                Ingresa tu correo y te enviaremos un enlace para crear una nueva.
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
                        data-testid="forgot-email"
                        :aria-invalid="!!fieldErrors.email"
                    >
                    <p v-if="fieldErrors.email" class="auth-field-error" role="alert">{{ fieldErrors.email }}</p>
                </div>

                <p v-if="generalError && !Object.keys(fieldErrors).length" class="auth-field-error" role="alert">
                    {{ generalError }}
                </p>

                <button type="submit" class="btn btn-primary auth-submit" data-testid="forgot-submit" :disabled="submitting">
                    <Loader2 v-if="submitting" :size="16" class="auth-spin" />
                    <span v-else>Enviar enlace</span>
                </button>
            </form>
        </template>

        <template v-else>
            <span class="auth-badge"><MailCheck :size="26" /></span>
            <h1 class="serif auth-title" data-testid="forgot-sent">Revisa tu<br>correo.</h1>
            <p class="auth-subtitle">
                Si el correo esta registrado, te enviamos un enlace para restablecer la contraseña.
                Revisa también la carpeta de spam.
            </p>
        </template>

        <p class="auth-footer">
            <router-link :to="{ name: 'login' }" class="auth-link auth-back-link">
                <ArrowLeft :size="14" /> Volver a iniciar sesión
            </router-link>
        </p>
    </AuthShell>
</template>
