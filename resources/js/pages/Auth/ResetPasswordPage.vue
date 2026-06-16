<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Loader2, CheckCircle2 } from 'lucide-vue-next'
import AuthService from '@/services/AuthService'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'
import AuthShell from '@/components/layout/AuthShell.vue'

const route = useRoute()
const router = useRouter()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
const hasValidLink = computed(() => token.value.length > 0 && email.value.length > 0)

const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const done = ref(false)
const generalError = ref('')
const fieldErrors = ref<Record<string, string>>({})

async function handleSubmit() {
    submitting.value = true
    generalError.value = ''
    fieldErrors.value = {}

    try {
        await AuthService.resetPassword({
            token: token.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        })
        done.value = true
    } catch (err: unknown) {
        fieldErrors.value = extractFieldErrors(err)
        generalError.value = extractErrorMessage(err)
    } finally {
        submitting.value = false
    }
}

function goToLogin() {
    void router.push({ name: 'login' })
}
</script>

<template>
    <AuthShell>
        <!-- Broken / missing link -->
        <template v-if="!hasValidLink">
            <p class="label-gilt">Enlace invalido</p>
            <h1 class="serif auth-title">Algo salio<br>mal.</h1>
            <p class="auth-subtitle" data-testid="reset-invalid-link">
                Este enlace de restablecimiento esta incompleto o ha caducado.
                Solicita uno nuevo desde la pantalla de recuperacion.
            </p>
            <button type="button" class="btn btn-primary auth-submit" @click="router.push({ name: 'forgot-password' })">
                Solicitar nuevo enlace
            </button>
        </template>

        <!-- Success -->
        <template v-else-if="done">
            <span class="auth-badge"><CheckCircle2 :size="26" /></span>
            <h1 class="serif auth-title" data-testid="reset-done">Contrasena<br>actualizada.</h1>
            <p class="auth-subtitle">Ya puedes iniciar sesion con tu nueva contrasena.</p>
            <button type="button" class="btn btn-primary auth-submit" data-testid="reset-go-login" @click="goToLogin">
                Iniciar sesion
            </button>
        </template>

        <!-- Form -->
        <template v-else>
            <p class="label-gilt">Restablecer</p>
            <h1 class="serif auth-title">Crea una nueva<br>contrasena.</h1>
            <p class="auth-subtitle">Para la cuenta <strong>{{ email }}</strong>.</p>

            <form class="auth-form" novalidate @submit.prevent="handleSubmit">
                <div class="auth-field-group">
                    <label for="password" class="field-label">Nueva contrasena</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        class="field"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        data-testid="reset-password"
                        :aria-invalid="!!fieldErrors.password"
                    >
                    <p v-if="fieldErrors.password" class="auth-field-error" role="alert">{{ fieldErrors.password }}</p>
                </div>

                <div class="auth-field-group">
                    <label for="password_confirmation" class="field-label">Confirmar contrasena</label>
                    <input
                        id="password_confirmation"
                        v-model="passwordConfirmation"
                        type="password"
                        class="field"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        data-testid="reset-password-confirm"
                    >
                </div>

                <p v-if="(generalError || fieldErrors.email) && !fieldErrors.password" class="auth-field-error" role="alert" data-testid="reset-error">
                    {{ fieldErrors.email || generalError }}
                </p>

                <button type="submit" class="btn btn-primary auth-submit" data-testid="reset-submit" :disabled="submitting">
                    <Loader2 v-if="submitting" :size="16" class="auth-spin" />
                    <span v-else>Actualizar contrasena</span>
                </button>
            </form>
        </template>

        <p class="auth-footer">
            <router-link :to="{ name: 'login' }" class="auth-link auth-back-link">
                <ArrowLeft :size="14" /> Volver a iniciar sesion
            </router-link>
        </p>
    </AuthShell>
</template>
