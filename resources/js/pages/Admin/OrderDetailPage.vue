<script setup lang="ts">
// Placeholder for S4-E6. This stub provides a navigable target so that row
// clicks in OrdersPage can already push to `admin.orders.detail` without
// breaking navigation. E6 will replace the body of this file entirely.

import { useRoute } from 'vue-router'
import AppSpinner from '@/components/base/AppSpinner.vue'

const route = useRoute()
const orderId = route.params.id as string
</script>

<template>
    <div class="flex flex-col items-center justify-center py-24 text-center gap-4">
        <AppSpinner />
        <p class="text-sm text-on-surface-variant">
            Cargando pedido {{ orderId }}…
        </p>
        <p class="text-xs text-on-surface-variant opacity-60">
            Vista detallada disponible en S4-E6.
        </p>
    </div>
</template>
