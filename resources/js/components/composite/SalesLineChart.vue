<script setup lang="ts">
/**
 * Sales line chart (Chart.js via vue-chartjs).
 *
 * Renders a daily sales series as a filled line. Line/fill use the brand
 * --primary token (read from CSS so it adapts to tenant branding); the tooltip
 * formats centavos via the injected `format` callback.
 */
import { computed } from 'vue'
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Filler,
    Tooltip,
} from 'chart.js'
import type { ChartData, ChartOptions, ScriptableContext, TooltipItem } from 'chart.js'
import type { SalesPoint } from '@/types/domain/Dashboard'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Filler, Tooltip)

const props = defineProps<{
    points: SalesPoint[]
    format: (cents: number) => string
}>()

function brandColor(): string {
    const v = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim()
    return v || '#7c545d'
}

function dayLabel(date: string): string {
    const d = new Date(date + 'T00:00:00')
    return d.toLocaleDateString('es-SV', { day: '2-digit', month: 'short' })
}

const chartData = computed<ChartData<'line'>>(() => {
    const color = brandColor()
    return {
        labels: props.points.map((p) => dayLabel(p.date)),
        datasets: [
            {
                data: props.points.map((p) => p.total_cents / 100),
                borderColor: color,
                backgroundColor: (ctx: ScriptableContext<'line'>) => {
                    const { ctx: c, chartArea } = ctx.chart
                    if (!chartArea) return 'transparent'
                    const gradient = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
                    gradient.addColorStop(0, color + '40')
                    gradient.addColorStop(1, color + '00')
                    return gradient
                },
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: color,
            },
        ],
    }
})

const chartOptions = computed<ChartOptions<'line'>>(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            callbacks: {
                label: (ctx: TooltipItem<'line'>) => props.format(Math.round((ctx.parsed.y ?? 0) * 100)),
            },
        },
    },
    scales: {
        x: {
            grid: { display: false },
            ticks: { maxTicksLimit: 7, color: 'rgba(125,84,93,0.55)', font: { size: 10 } },
        },
        y: {
            beginAtZero: true,
            grid: { color: 'rgba(125,84,93,0.10)' },
            ticks: { display: false },
            border: { display: false },
        },
    },
}))
</script>

<template>
    <div style="height: 180px">
        <Line :data="chartData" :options="chartOptions" />
    </div>
</template>
