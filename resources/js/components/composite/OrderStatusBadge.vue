<script setup lang="ts">
import AppBadge from '@/components/base/AppBadge.vue'

type OrderStatus = 'pending' | 'preparing' | 'ready' | 'dispatched' | 'delivered' | 'cancelled'

interface Props {
    status: OrderStatus
}

defineProps<Props>()

const config: Record<OrderStatus, { label: string; variant: 'primary' | 'success' | 'warning' | 'error' | 'neutral' | 'info' }> = {
    pending: { label: 'Pendiente', variant: 'warning' },
    preparing: { label: 'Preparando', variant: 'info' },
    ready: { label: 'Listo', variant: 'primary' },
    dispatched: { label: 'Despachado', variant: 'info' },
    delivered: { label: 'Entregado', variant: 'success' },
    cancelled: { label: 'Cancelado', variant: 'error' },
}
</script>

<template>
    <AppBadge :variant="config[status]?.variant ?? 'neutral'">
        {{ config[status]?.label ?? status }}
    </AppBadge>
</template>
