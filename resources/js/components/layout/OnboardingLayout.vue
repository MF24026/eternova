<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
    <div
        class="min-h-screen flex flex-col items-center justify-center px-4 py-12"
        style="background: var(--gradient-bloom)"
    >
        <!-- Brand mark at top -->
        <RouterLink
            to="/"
            class="font-serif font-semibold text-primary tracking-tighter text-2xl mb-10"
        >
            Eternova
        </RouterLink>

        <!-- Centered card -->
        <div
            class="w-full max-w-md rounded-2xl bg-surface-lowest shadow-[var(--shadow-lifted)] overflow-hidden"
        >
            <slot />
        </div>

        <!-- Footer note -->
        <p class="mt-8 text-xs text-on-surface-variant text-center">
            Al continuar, aceptas los
            <RouterLink to="/términos" class="text-primary underline-offset-2 hover:underline">
                Términos de uso
            </RouterLink>
            y la
            <RouterLink to="/privacidad" class="text-primary underline-offset-2 hover:underline">
                Política de privacidad
            </RouterLink>
            de Eternova.
        </p>
    </div>
</template>
