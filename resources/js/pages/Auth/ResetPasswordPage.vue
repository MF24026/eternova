<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Flower2, ArrowLeft, Loader2, CheckCircle2 } from 'lucide-vue-next'
import AuthService from '@/services/AuthService'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'

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
    <div class="login-bloom">
        <span class="petal petal--rose" aria-hidden="true" />
        <span class="petal petal--lilac" aria-hidden="true" />

        <div class="card login-card fade-in">
            <div class="brand">
                <span class="brand-badge"><Flower2 :size="22" /></span>
                <span class="serif brand-name">Eternova</span>
            </div>

            <!-- Broken / missing link -->
            <template v-if="!hasValidLink">
                <p class="label-gilt">Enlace invalido</p>
                <h1 class="serif login-title">Algo salio<br>mal.</h1>
                <p class="login-subtitle" data-testid="reset-invalid-link">
                    Este enlace de restablecimiento esta incompleto o ha caducado.
                    Solicita uno nuevo desde la pantalla de recuperacion.
                </p>
                <button type="button" class="btn btn-primary login-submit" @click="router.push({ name: 'forgot-password' })">
                    Solicitar nuevo enlace
                </button>
            </template>

            <!-- Success -->
            <template v-else-if="done">
                <span class="sent-badge"><CheckCircle2 :size="26" /></span>
                <h1 class="serif login-title" data-testid="reset-done">Contrasena<br>actualizada.</h1>
                <p class="login-subtitle">Ya puedes iniciar sesion con tu nueva contrasena.</p>
                <button type="button" class="btn btn-primary login-submit" data-testid="reset-go-login" @click="goToLogin">
                    Iniciar sesion
                </button>
            </template>

            <!-- Form -->
            <template v-else>
                <p class="label-gilt">Restablecer</p>
                <h1 class="serif login-title">Crea una nueva<br>contrasena.</h1>
                <p class="login-subtitle">Para la cuenta <strong>{{ email }}</strong>.</p>

                <form class="login-form" novalidate @submit.prevent="handleSubmit">
                    <div class="field-group">
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
                        <p v-if="fieldErrors.password" class="field-error" role="alert">{{ fieldErrors.password }}</p>
                    </div>

                    <div class="field-group">
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

                    <p v-if="(generalError || fieldErrors.email) && !fieldErrors.password" class="field-error general-error" role="alert" data-testid="reset-error">
                        {{ fieldErrors.email || generalError }}
                    </p>

                    <button type="submit" class="btn btn-primary login-submit" data-testid="reset-submit" :disabled="submitting">
                        <Loader2 v-if="submitting" :size="16" class="spin" />
                        <span v-else>Actualizar contrasena</span>
                    </button>
                </form>
            </template>

            <p class="login-footer">
                <router-link :to="{ name: 'login' }" class="login-link back-link">
                    <ArrowLeft :size="14" /> Volver a iniciar sesion
                </router-link>
            </p>
        </div>
    </div>
</template>

<style scoped>
.login-bloom {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
    position: relative;
    overflow: hidden;
    background: var(--gradient-bloom);
}
.petal { position: absolute; border-radius: 50%; filter: blur(8px); pointer-events: none; }
.petal--rose {
    top: -120px; right: -140px; width: 460px; height: 460px; opacity: 0.55;
    background: radial-gradient(circle at 35% 35%, #f7c9d2 0%, #f3b6c4 45%, transparent 70%);
}
.petal--lilac {
    bottom: -120px; left: -100px; width: 340px; height: 340px; opacity: 0.5;
    background: radial-gradient(circle at 40% 40%, #e3d2ff 0%, #d3bcff 45%, transparent 70%);
}
.login-card {
    position: relative; z-index: 2; width: 100%; max-width: 440px; padding: 48px;
    background: var(--surface-lowest); box-shadow: var(--shadow-ambient);
}
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 32px; }
.brand-badge {
    width: 44px; height: 44px; border-radius: 50%; background: var(--gradient);
    color: var(--on-primary); display: grid; place-items: center; flex-shrink: 0;
}
.brand-name { font-size: 22px; line-height: 1; color: var(--on-surface); }
.sent-badge {
    width: 56px; height: 56px; border-radius: 50%; background: var(--gradient-soft);
    color: var(--primary); display: grid; place-items: center; margin-bottom: 20px;
}
.login-title { font-size: 36px; line-height: 1.05; margin: 8px 0; color: var(--on-surface); }
.login-subtitle { color: var(--on-surface-variant); font-size: 14px; margin-bottom: 28px; }
.login-form { display: flex; flex-direction: column; gap: 16px; }
.field-group { display: flex; flex-direction: column; gap: 6px; }
.field-error { font-size: 13px; color: var(--error, #b3261e); }
.general-error { margin-top: -4px; }
.login-submit { width: 100%; justify-content: center; padding: 16px; margin-top: 8px; font-size: 16px; }
.login-submit:disabled { opacity: 0.7; cursor: not-allowed; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.login-footer { margin-top: 24px; text-align: center; font-size: 14px; color: var(--on-surface-variant); }
.login-link { color: var(--primary); font-weight: 600; }
.login-link:hover { text-decoration: underline; }
.back-link { display: inline-flex; align-items: center; gap: 6px; }
@media (max-width: 480px) {
    .login-card { padding: 32px 24px; }
    .login-title { font-size: 30px; }
}
</style>
