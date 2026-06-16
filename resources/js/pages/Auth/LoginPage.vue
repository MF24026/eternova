<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Flower2, ArrowRight, Loader2 } from 'lucide-vue-next'
import { useAuthStore } from '@/stores/auth'
import { extractFieldErrors, extractErrorMessage } from '@/utils/errors'

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
    <div class="login-bloom">
        <!-- Decorative bloom petals -->
        <span class="petal petal--rose" aria-hidden="true" />
        <span class="petal petal--lilac" aria-hidden="true" />

        <div class="card login-card fade-in">
            <div class="brand">
                <span class="brand-badge">
                    <Flower2 :size="22" />
                </span>
                <span class="serif brand-name">Eternova</span>
            </div>

            <p class="label-gilt">Panel del negocio</p>
            <h1 class="serif login-title">Bienvenido<br>de nuevo.</h1>
            <p class="login-subtitle">
                Tu jardin te espera. Ingresa con tus credenciales para continuar.
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
                        :aria-invalid="!!fieldErrors.email"
                    >
                    <p v-if="fieldErrors.email" class="field-error" role="alert">{{ fieldErrors.email }}</p>
                </div>

                <div class="field-group">
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
                    <p v-if="fieldErrors.password" class="field-error" role="alert">{{ fieldErrors.password }}</p>
                    <router-link :to="{ name: 'forgot-password' }" class="forgot-link" data-testid="forgot-link">
                        Olvidaste tu contrasena?
                    </router-link>
                </div>

                <p v-if="generalError && !Object.keys(fieldErrors).length" class="field-error general-error" role="alert">
                    {{ generalError }}
                </p>

                <button type="submit" class="btn btn-primary login-submit" :disabled="submitting">
                    <Loader2 v-if="submitting" :size="16" class="spin" />
                    <template v-else>
                        Entrar
                        <ArrowRight :size="16" />
                    </template>
                </button>
            </form>

            <p class="login-footer">
                Sin cuenta?
                <router-link :to="{ name: 'signup' }" class="login-link">Crear una gratis</router-link>
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

.petal {
    position: absolute;
    border-radius: 50%;
    filter: blur(8px);
    pointer-events: none;
}

.petal--rose {
    top: -120px;
    right: -140px;
    width: 460px;
    height: 460px;
    opacity: 0.55;
    background: radial-gradient(circle at 35% 35%, #f7c9d2 0%, #f3b6c4 45%, transparent 70%);
}

.petal--lilac {
    bottom: -120px;
    left: -100px;
    width: 340px;
    height: 340px;
    opacity: 0.5;
    background: radial-gradient(circle at 40% 40%, #e3d2ff 0%, #d3bcff 45%, transparent 70%);
}

.login-card {
    position: relative;
    z-index: 2;
    width: 100%;
    max-width: 440px;
    padding: 48px;
    background: var(--surface-lowest);
    box-shadow: var(--shadow-ambient);
}

.brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 32px;
}

.brand-badge {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--gradient);
    color: var(--on-primary);
    display: grid;
    place-items: center;
    flex-shrink: 0;
}

.brand-name {
    font-size: 22px;
    line-height: 1;
    color: var(--on-surface);
}

.login-title {
    font-size: 36px;
    line-height: 1.05;
    margin: 8px 0;
    color: var(--on-surface);
}

.login-subtitle {
    color: var(--on-surface-variant);
    font-size: 14px;
    margin-bottom: 28px;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.field-error {
    font-size: 13px;
    color: var(--error, #b3261e);
}

.general-error {
    margin-top: -4px;
}

.login-submit {
    width: 100%;
    justify-content: center;
    padding: 16px;
    margin-top: 8px;
    font-size: 16px;
}

.login-submit:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.spin {
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.login-footer {
    margin-top: 24px;
    text-align: center;
    font-size: 14px;
    color: var(--on-surface-variant);
}

.login-link {
    color: var(--primary);
    font-weight: 600;
}

.login-link:hover {
    text-decoration: underline;
}

.forgot-link {
    align-self: flex-end;
    font-size: 13px;
    color: var(--primary);
    font-weight: 600;
    margin-top: 2px;
}

.forgot-link:hover {
    text-decoration: underline;
}

@media (max-width: 480px) {
    .login-card {
        padding: 32px 24px;
    }

    .login-title {
        font-size: 30px;
    }
}
</style>
