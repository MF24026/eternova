<script setup lang="ts">
import { ref, watch } from 'vue'
import { Plus, X, Lock } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ReservationService from '@/services/ReservationService'
import { useToast } from '@/composables/useToast'

// ── Props / emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
    /**
     * When true, the user is an owner or admin and can edit settings.
     * When false (staff), the form is read-only.
     */
    canEdit?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    canEdit: true,
})

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    /** Emitted after a successful save so the parent can re-read occasions (e.g. for the capture form). */
    saved: []
}>()

// ── State ─────────────────────────────────────────────────────────────────────

type PageState = 'loading' | 'loaded' | 'error'

const pageState = ref<PageState>('loading')
const depositPct = ref(30)
const occasions = ref<string[]>([])
const newOccasion = ref('')
const isSaving = ref(false)
const toast = useToast()

// ── Load ──────────────────────────────────────────────────────────────────────

async function loadSettings(): Promise<void> {
    pageState.value = 'loading'
    try {
        const settings = await ReservationService.getSettings()
        depositPct.value = settings.deposit_pct
        occasions.value = [...settings.occasions]
        pageState.value = 'loaded'
    } catch {
        pageState.value = 'error'
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) void loadSettings()
    },
)

// ── Occasions management ──────────────────────────────────────────────────────

function addOccasion(): void {
    const trimmed = newOccasion.value.trim()
    if (!trimmed) return
    if (occasions.value.includes(trimmed)) {
        toast.warning(`"${trimmed}" ya está en la lista.`)
        return
    }
    occasions.value.push(trimmed)
    newOccasion.value = ''
}

function removeOccasion(index: number): void {
    occasions.value.splice(index, 1)
}

// Allow pressing Enter to add an occasion
function onOccasionKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter') {
        event.preventDefault()
        addOccasion()
    }
}

// ── Save ──────────────────────────────────────────────────────────────────────

async function save(): Promise<void> {
    if (!props.canEdit || isSaving.value) return

    // Validate deposit_pct
    if (depositPct.value < 0 || depositPct.value > 100 || !Number.isFinite(depositPct.value)) {
        toast.error('El porcentaje de anticipo debe estar entre 0 y 100.')
        return
    }

    isSaving.value = true
    try {
        await ReservationService.updateSettings({
            deposit_pct: depositPct.value,
            occasions: occasions.value,
        })
        toast.success('Configuración de reservas guardada.')
        emit('saved')
        close()
    } catch (err: unknown) {
        const apiErr = err as { response?: { status?: number; data?: { message?: string } } }
        if (apiErr.response?.status === 403) {
            toast.error('Solo propietarios y administradores pueden editar esta configuración.')
        } else {
            const msg = apiErr.response?.data?.message ?? 'No se pudo guardar la configuración.'
            toast.error(msg)
        }
    } finally {
        isSaving.value = false
    }
}

function close(): void {
    emit('update:modelValue', false)
}
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Configuración de reservas"
        subtitle="Porcentaje de anticipo y lista de ocasiones"
        test-id="reservation-settings-slideover"
        @update:model-value="close"
    >
        <!-- Loading -->
        <div v-if="pageState === 'loading'" class="flex items-center justify-center py-16">
            <AppSpinner size="lg" />
        </div>

        <!-- Error -->
        <div v-else-if="pageState === 'error'" class="text-center py-16">
            <p class="text-sm text-error mb-4">No se pudo cargar la configuración.</p>
            <AppButton variant="secondary" @click="loadSettings">Reintentar</AppButton>
        </div>

        <!-- Loaded -->
        <div v-else class="flex flex-col gap-6">

            <!-- Read-only notice for staff -->
            <div
                v-if="!canEdit"
                class="flex items-start gap-3 rounded-xl p-4 bg-warning-container"
            >
                <Lock :size="16" class="text-warning shrink-0 mt-0.5" aria-hidden="true" />
                <p class="text-sm text-on-surface">
                    Solo propietarios y administradores pueden modificar esta configuración.
                    Estás viendo la configuración en modo de lectura.
                </p>
            </div>

            <!-- Deposit percentage -->
            <div class="flex flex-col gap-2">
                <h3 class="serif text-lg text-on-surface tracking-tighter">
                    Anticipo por defecto
                </h3>
                <p class="text-xs text-on-surface-variant">
                    Porcentaje que se usa para calcular el anticipo requerido cuando no se especifica un monto manual.
                </p>
                <div class="flex items-center gap-3 mt-1">
                    <div class="relative flex-1 max-w-[180px]">
                        <input
                            v-model.number="depositPct"
                            type="number"
                            min="0"
                            max="100"
                            :disabled="!canEdit"
                            class="w-full px-4 py-2.5 pr-10 rounded-xl
                                   bg-surface-low text-on-surface text-sm
                                   focus:outline-none focus:ring-2 focus:ring-primary/30
                                   dark:bg-surface-mid dark:text-on-surface
                                   disabled:opacity-50 disabled:cursor-not-allowed"
                            aria-label="Porcentaje de anticipo requerido"
                        />
                        <span
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-on-surface-variant pointer-events-none"
                            aria-hidden="true"
                        >
                            %
                        </span>
                    </div>
                    <p class="text-xs text-on-surface-variant">
                        del total de la reserva
                    </p>
                </div>
            </div>

            <!-- Occasions list -->
            <div class="flex flex-col gap-3">
                <div>
                    <h3 class="serif text-lg text-on-surface tracking-tighter">
                        Ocasiones disponibles
                    </h3>
                    <p class="text-xs text-on-surface-variant mt-0.5">
                        Las ocasiones aparecen en el formulario de captura para que el equipo las seleccione rápidamente.
                    </p>
                </div>

                <!-- Current occasions chips -->
                <div
                    v-if="occasions.length > 0"
                    class="flex flex-wrap gap-2"
                    aria-label="Ocasiones configuradas"
                >
                    <span
                        v-for="(occ, index) in occasions"
                        :key="occ"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full
                               bg-primary-container text-on-surface text-sm font-medium"
                    >
                        {{ occ }}
                        <button
                            v-if="canEdit"
                            type="button"
                            class="text-on-surface-variant hover:text-error transition-colors"
                            :aria-label="`Quitar ocasión ${occ}`"
                            @click="removeOccasion(index)"
                        >
                            <X :size="12" />
                        </button>
                    </span>
                </div>
                <p v-else class="text-sm text-on-surface-variant italic">
                    No hay ocasiones configuradas. Agrega algunas usando el campo de abajo.
                </p>

                <!-- Add occasion input -->
                <div v-if="canEdit" class="flex gap-2 mt-1">
                    <input
                        v-model="newOccasion"
                        type="text"
                        placeholder="Nueva ocasión..."
                        class="flex-1 px-4 py-2.5 rounded-xl bg-surface-low text-on-surface text-sm
                               placeholder:text-on-surface-variant/50
                               transition-colors duration-150
                               focus:outline-none focus:ring-2 focus:ring-primary/30
                               dark:bg-surface-mid dark:text-on-surface"
                        aria-label="Nueva ocasión para agregar"
                        @keydown="onOccasionKeydown"
                    />
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-full
                               bg-primary-container text-on-surface text-sm font-medium
                               hover:bg-primary/20 transition-colors
                               disabled:opacity-50 disabled:pointer-events-none"
                        :disabled="!newOccasion.trim()"
                        aria-label="Agregar ocasión"
                        @click="addOccasion"
                    >
                        <Plus :size="14" aria-hidden="true" />
                        Agregar
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <template #footer>
            <div class="flex gap-3">
                <AppButton
                    variant="secondary"
                    class="flex-1"
                    :disabled="isSaving"
                    @click="close"
                >
                    {{ canEdit ? 'Cancelar' : 'Cerrar' }}
                </AppButton>
                <AppButton
                    v-if="canEdit && pageState === 'loaded'"
                    variant="primary"
                    class="flex-1"
                    :loading="isSaving"
                    :disabled="isSaving"
                    @click="save"
                >
                    Guardar configuración
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
