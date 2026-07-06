<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppHeader from '@/components/layout/AppHeader.vue'
</script>

<template>
    <div class="min-h-screen bg-surface">
        <AppHeader />

        <main class="max-w-2xl mx-auto px-4 py-20 text-center">
            <h1 class="font-serif text-4xl font-semibold text-on-surface tracking-tighter mb-4">
                Gestiona tu negocio con elegancia
            </h1>
            <p class="text-on-surface-variant text-lg mb-10">
                Catálogo digital, pedidos, inventario y mas — todo en un solo lugar.
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <RouterLink
                    :to="{ name: 'signup' }"
                    class="inline-flex items-center justify-center px-8 py-3 rounded-full bg-primary text-on-primary font-medium text-sm hover:bg-primary-dim transition-colors"
                >
                    Crear cuenta gratis
                </RouterLink>
                <RouterLink
                    :to="{ name: 'login' }"
                    class="inline-flex items-center justify-center px-8 py-3 rounded-full bg-surface-high text-on-surface font-medium text-sm hover:bg-surface-highest transition-colors dark:bg-surface-mid dark:hover:bg-surface-high"
                >
                    Iniciar sesión
                </RouterLink>
            </div>
        </main>
    </div>
</template>
