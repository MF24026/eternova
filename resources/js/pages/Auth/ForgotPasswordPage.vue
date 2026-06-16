<script setup lang="ts">
import { ref } from 'vue'
import { Flower2, ArrowLeft, Loader2, MailCheck } from 'lucide-vue-next'
import AuthService from '@/services/AuthService'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'

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
    <div class="login-bloom">
        <span class="petal petal--rose" aria-hidden="true" />
        <span class="petal petal--lilac" aria-hidden="true" />

        <div class="card login-card fade-in">
            <div class="brand">
                <span class="brand-badge"><Flower2 :size="22" /></span>
                <span class="serif brand-name">Eternova</span>
            </div>

            <template v-if="!sent">
                <p class="label-gilt">Recuperar acceso</p>
                <h1 class="serif login-title">Olvidaste tu<br>contrasena?</h1>
                <p class="login-subtitle">
                    Ingresa tu correo y te enviaremos un enlace para crear una nueva.
                </p>

                <form class="login-form" novalidate @submit.prevent="handleSubmit">
                    <div class="field-group">
                        <label for="email" class="field-label">Correo electronico</label>
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
                        <p v-if="fieldErrors.email" class="field-error" role="alert">{{ fieldErrors.email }}</p>
                    </div>

                    <p v-if="generalError && !Object.keys(fieldErrors).length" class="field-error general-error" role="alert">
                        {{ generalError }}
                    </p>

                    <button type="submit" class="btn btn-primary login-submit" data-testid="forgot-submit" :disabled="submitting">
                        <Loader2 v-if="submitting" :size="16" class="spin" />
                        <span v-else>Enviar enlace</span>
                    </button>
                </form>
            </template>

            <template v-else>
                <span class="sent-badge"><MailCheck :size="26" /></span>
                <h1 class="serif login-title" data-testid="forgot-sent">Revisa tu<br>correo.</h1>
                <p class="login-subtitle">
                    Si el correo esta registrado, te enviamos un enlace para restablecer la contrasena.
                    Revisa tambien la carpeta de spam.
                </p>
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
