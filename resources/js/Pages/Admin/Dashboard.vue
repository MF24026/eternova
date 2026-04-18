<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Card from '@/Components/Card.vue';
import Badge from '@/Components/Badge.vue';
import {
    DollarSign,
    Package,
    AlertTriangle,
    Receipt,
} from 'lucide-vue-next';

defineOptions({ layout: AdminLayout });
</script>

<template>
    <div>
        <div class="mb-6">
            <p class="label-gilt text-xs text-on-surface-variant">Panel de Control</p>
            <h1 class="headline-serif text-2xl font-semibold text-on-surface mt-1">
                Resumen del Negocio
            </h1>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <Card>
                <div class="flex items-start justify-between">
                    <DollarSign class="h-5 w-5 text-primary" />
                    <Badge variant="success">+12%</Badge>
                </div>
                <p class="label-gilt mt-3 text-[11px] text-on-surface-variant">Ventas Totales</p>
                <p class="mt-1 text-2xl font-semibold text-on-surface">$0.00</p>
            </Card>

            <Card>
                <div class="flex items-start justify-between">
                    <Package class="h-5 w-5 text-primary" />
                    <Badge variant="info">0 Nuevos</Badge>
                </div>
                <p class="label-gilt mt-3 text-[11px] text-on-surface-variant">Pedidos Pendientes</p>
                <p class="mt-1 text-2xl font-semibold text-on-surface">0</p>
            </Card>

            <Card>
                <div class="flex items-start justify-between">
                    <AlertTriangle class="h-5 w-5 text-warning" />
                    <Badge variant="warning">--</Badge>
                </div>
                <p class="label-gilt mt-3 text-[11px] text-on-surface-variant">Alertas de Stock</p>
                <p class="mt-1 text-2xl font-semibold text-on-surface">--</p>
            </Card>

            <Card>
                <div class="flex items-start justify-between">
                    <Receipt class="h-5 w-5 text-primary" />
                    <Badge>Meta: $5k</Badge>
                </div>
                <p class="label-gilt mt-3 text-[11px] text-on-surface-variant">Gastos Mensuales</p>
                <p class="mt-1 text-2xl font-semibold text-on-surface">$0.00</p>
            </Card>
        </div>

        <!-- Placeholder sections -->
        <div class="mt-8 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <Card class="lg:col-span-2">
                <h2 class="headline-serif text-lg font-semibold text-on-surface">
                    Rendimiento de Ventas
                </h2>
                <p class="mt-1 label-gilt text-[11px] text-on-surface-variant">Analisis de flujo de ingresos</p>
                <div class="mt-6 flex items-center justify-center h-48 rounded-xl bg-surface-container-low">
                    <p class="text-sm text-on-surface-variant">Grafico disponible cuando haya datos</p>
                </div>
            </Card>

            <Card>
                <h2 class="headline-serif text-lg font-semibold text-on-surface">
                    Entregas de Hoy
                </h2>
                <div class="mt-4 space-y-3">
                    <div class="flex items-center justify-center h-32 rounded-xl bg-surface-container-low">
                        <p class="text-sm text-on-surface-variant">Sin entregas programadas</p>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
