<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import AppSpinner from '@/components/base/AppSpinner.vue'

onMounted(() => { document.title = 'Reserva — Eternova' })

const route = useRoute()
const reservationId = route.params['id'] as string
</script>

<template>
    <div class="flex flex-col items-center justify-center py-24 gap-4 text-on-surface-variant">
        <AppSpinner size="lg" />
        <p class="text-sm">Cargando reserva #{{ reservationId }}…</p>
        <p class="text-xs opacity-60">La vista de detalle completa se construye en S5-E7.</p>
    </div>
</template>
