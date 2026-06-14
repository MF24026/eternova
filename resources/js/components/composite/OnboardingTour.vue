<script setup lang="ts">
/**
 * OnboardingTour — lightweight first-login welcome tour for the admin panel.
 *
 * A centered step-based overlay (no fragile element anchoring) that introduces
 * the main areas of the app. Shown once per tenant (persistence handled by the
 * parent via localStorage). Accessible: role="dialog", focus trapped to the
 * card, Escape skips, arrow keys navigate.
 */
import { ref, computed, watch, nextTick } from 'vue'
import {
    LayoutDashboard, ShoppingCart, Package, FileText, Sparkles, X,
} from 'lucide-vue-next'
import AppButton from '@/components/base/AppButton.vue'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; finish: [] }>()

interface Step {
    icon: typeof LayoutDashboard
    title: string
    text: string
}

const steps: Step[] = [
    { icon: Sparkles, title: 'Te damos la bienvenida', text: 'Este es el panel de tu negocio. Te mostramos en 30 segundos lo esencial para empezar.' },
    { icon: LayoutDashboard, title: 'Panel', text: 'Tus métricas en vivo: ventas del día, pedidos pendientes, stock bajo y gastos del mes.' },
    { icon: ShoppingCart, title: 'Punto de venta', text: 'Registra ventas presenciales en segundos. El inventario se descuenta automáticamente.' },
    { icon: Package, title: 'Productos e inventario', text: 'Administra tu catálogo, variantes y existencias por sucursal con alertas de stock bajo.' },
    { icon: FileText, title: 'Cotizaciones y pedidos', text: 'Crea cotizaciones PDF, conviértelas en pedidos y dales seguimiento hasta la entrega.' },
]

const currentStep = ref(0)
const cardRef = ref<HTMLElement | null>(null)

const step = computed(() => steps[currentStep.value])
const isFirst = computed(() => currentStep.value === 0)
const isLast = computed(() => currentStep.value === steps.length - 1)

watch(() => props.modelValue, (open) => {
    if (open) {
        currentStep.value = 0
        void nextTick(() => cardRef.value?.focus())
    }
})

function next(): void {
    if (isLast.value) {
        finish()
        return
    }
    currentStep.value++
}

function prev(): void {
    if (!isFirst.value) currentStep.value--
}

// Skipping and finishing both mean "don't show again": emit finish so the
// parent persists the seen flag.
function skip(): void {
    finish()
}

function finish(): void {
    emit('finish')
    close()
}

function close(): void {
    emit('update:modelValue', false)
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') skip()
    else if (event.key === 'ArrowRight') next()
    else if (event.key === 'ArrowLeft') prev()
}
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        leave-active-class="transition-opacity duration-150"
        leave-to-class="opacity-0"
    >
        <div
            v-if="modelValue"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4"
            style="background: color-mix(in srgb, var(--on-surface) 45%, transparent)"
            data-testid="onboarding-tour"
            @keydown="onKeydown"
        >
            <div
                ref="cardRef"
                role="dialog"
                aria-modal="true"
                aria-labelledby="tour-title"
                tabindex="-1"
                class="relative w-full max-w-md rounded-2xl bg-surface-lowest dark:bg-surface-low p-7 shadow-[var(--shadow-lifted)] focus:outline-none"
            >
                <button
                    type="button"
                    class="btn-icon absolute right-3 top-3"
                    aria-label="Omitir el recorrido"
                    data-testid="tour-skip"
                    @click="skip"
                >
                    <X :size="18" />
                </button>

                <div
                    class="w-14 h-14 rounded-2xl flex items-center justify-center mb-5"
                    style="background: var(--gradient-soft); color: var(--primary)"
                >
                    <component :is="step.icon" :size="26" aria-hidden="true" />
                </div>

                <h2 id="tour-title" class="serif text-2xl text-on-surface tracking-tighter mb-2" data-testid="tour-title">
                    {{ step.title }}
                </h2>
                <p class="text-sm text-on-surface-variant leading-relaxed mb-6">
                    {{ step.text }}
                </p>

                <!-- Progress dots -->
                <div class="flex items-center gap-1.5 mb-6" aria-hidden="true">
                    <span
                        v-for="(_, i) in steps"
                        :key="i"
                        class="h-1.5 rounded-full transition-all duration-200"
                        :style="{
                            width: i === currentStep ? '20px' : '6px',
                            background: i === currentStep ? 'var(--primary)' : 'var(--surface-high)',
                        }"
                    />
                </div>

                <div class="flex items-center justify-between gap-3">
                    <button
                        type="button"
                        class="text-sm text-on-surface-variant hover:text-on-surface transition-colors"
                        data-testid="tour-skip-text"
                        @click="skip"
                    >
                        Omitir
                    </button>
                    <div class="flex items-center gap-2">
                        <AppButton v-if="!isFirst" variant="secondary" size="sm" data-testid="tour-prev" @click="prev">
                            Anterior
                        </AppButton>
                        <AppButton variant="primary" size="sm" :data-testid="isLast ? 'tour-finish' : 'tour-next'" @click="next">
                            {{ isLast ? 'Comenzar' : 'Siguiente' }}
                        </AppButton>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>
