<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import BrandMark from '@/Components/BrandMark.vue';
import Petal from '@/Components/Petal.vue';

const form = useForm({
    email: '',
    password: '',
    remember: true,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Bienvenida de nuevo"/>

    <div
        style="
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: var(--gradient-bloom);
            position: relative;
            overflow: hidden;
            padding: 24px;
        "
    >
        <div
            class="petal-anim"
            style="position: absolute; top: -80px; right: -120px; width: 480px; height: 480px; opacity: 0.55; pointer-events: none;"
        >
            <Petal tone="rose"/>
        </div>
        <div
            class="petal-anim"
            style="position: absolute; bottom: -100px; left: -80px; width: 320px; height: 320px; opacity: 0.45; animation-delay: -7s; pointer-events: none;"
        >
            <Petal tone="lilac"/>
        </div>

        <div
            class="card fade-in"
            style="
                background: var(--surface-lowest);
                width: 100%;
                max-width: 440px;
                padding: 48px;
                box-shadow: var(--shadow-ambient);
                position: relative;
                z-index: 2;
            "
        >
            <div style="margin-bottom: 32px">
                <BrandMark
                    :size="44"
                    primary-text="Eternova"
                    :wordmark-size="20"
                />
            </div>

            <div class="label-gilt" style="margin-bottom: 8px">Panel del atelier</div>
            <h1 class="serif" style="font-size: 36px; margin: 0 0 8px; line-height: 1.05">
                Bienvenida<br/>de nuevo.
            </h1>
            <p style="color: var(--on-surface-variant); margin-bottom: 28px; font-size: 14px">
                Tu jardin te espera. Ingresa con tus credenciales del atelier.
            </p>

            <form class="stack" style="gap: 16px" @submit.prevent="submit">
                <div>
                    <label class="field-label" for="email">Correo</label>
                    <input
                        id="email"
                        v-model="form.email"
                        class="field"
                        type="email"
                        autocomplete="email"
                        required
                        autofocus
                    />
                    <p v-if="form.errors.email" class="text-xs" style="margin-top: 6px; color: var(--error)">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label class="field-label" for="password">Contrasena</label>
                    <input
                        id="password"
                        v-model="form.password"
                        class="field"
                        type="password"
                        autocomplete="current-password"
                        required
                        placeholder="••••••••"
                    />
                    <p v-if="form.errors.password" class="text-xs" style="margin-top: 6px; color: var(--error)">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div class="row" style="justify-content: space-between; font-size: 13px">
                    <label class="row" style="gap: 8px; cursor: pointer">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            style="accent-color: var(--primary)"
                        />
                        Recordarme
                    </label>
                    <button type="button" class="btn-secondary" style="font-size: 13px">
                        Olvidaste tu clave?
                    </button>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                    style="width: 100%; justify-content: center; padding: 16px"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Entrando...' : 'Entrar al atelier' }}
                    <ArrowRight :size="16"/>
                </button>
            </form>

            <div style="margin: 24px 0 16px; text-align: center; color: var(--on-surface-variant); font-size: 12px; text-transform: uppercase; letter-spacing: 0.12em">
                O continua con
            </div>
            <a
                href="/auth/google/redirect"
                class="btn btn-tertiary"
                style="width: 100%; justify-content: center; padding: 14px; gap: 10px; text-decoration: none"
            >
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M17.64 9.205c0-.639-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 0 1-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
                    <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/>
                    <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
                    <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
                </svg>
                Continuar con Google
            </a>

            <p style="margin-top: 24px; text-align: center; font-size: 13px; color: var(--on-surface-variant)">
                No tienes cuenta?
                <a href="/onboarding" class="btn-secondary" style="font-size: 13px">
                    Crea tu atelier
                </a>
            </p>
        </div>
    </div>
</template>
