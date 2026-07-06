<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppHeader from '@/components/layout/AppHeader.vue'
</script>

<template>
    <div class="min-h-screen bg-surface">
        <AppHeader />

        <main class="max-w-2xl mx-auto px-4 py-24 text-center">
            <p class="text-6xl font-serif font-semibold text-primary tracking-tighter mb-4">
                404
            </p>
            <h1 class="text-xl font-semibold text-on-surface mb-3">
                Página no encontrada
            </h1>
            <p class="text-on-surface-variant text-sm mb-8">
                La dirección que buscas no existe o fue movida.
            </p>
            <RouterLink
                :to="{ name: 'home' }"
                class="inline-flex items-center justify-center px-6 py-2.5 rounded-full bg-primary text-on-primary text-sm font-medium hover:bg-primary-dim transition-colors"
            >
                Volver al inicio
            </RouterLink>
        </main>
    </div>
</template>
