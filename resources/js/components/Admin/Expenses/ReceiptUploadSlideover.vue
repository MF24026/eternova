<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import { Upload, FileText, X, AlertCircle } from 'lucide-vue-next'
import AppSlideover from '@/components/base/AppSlideover.vue'
import AppButton from '@/components/base/AppButton.vue'
import AppSpinner from '@/components/base/AppSpinner.vue'
import ExpenseService from '@/services/ExpenseService'
import { useToast } from '@/composables/useToast'
import type { Expense } from '@/types/domain/Expense'

// ── Constants ─────────────────────────────────────────────────────────────────

const MAX_FILE_BYTES = 10 * 1024 * 1024   // 10 MB
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
const ACCEPTED_EXTENSIONS = '.jpg,.jpeg,.png,.webp,.pdf'
const POLL_INTERVAL_MS = 1500
const POLL_MAX_TRIES = 20

// ── Props / emits ─────────────────────────────────────────────────────────────

interface Props {
    modelValue: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    /** Emitted when the upload + OCR poll cycle finishes (done or failed).
     *  The parent opens the verification slideover for this draft expense. */
    'ready-to-verify': [expense: Expense]
}>()

// ── State machine ─────────────────────────────────────────────────────────────
//
//   idle       → user picks a file (dropzone)
//   uploading  → POST /expenses/receipt in flight
//   processing → polling ocr-status (pending | processing)
//   slow       → poll cap reached; user can proceed anyway
//
// Verification lives in ReceiptVerificationSlideover so both entry paths
// (upload here + "Verificar" on a draft row) share the same component.

type Step = 'idle' | 'uploading' | 'processing' | 'slow'
const step = ref<Step>('idle')

// ── File selection ────────────────────────────────────────────────────────────

const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const selectedFile = ref<File | null>(null)
const filePreviewUrl = ref<string | null>(null)
const fileError = ref<string | null>(null)

const isPdf = computed(
    () => selectedFile.value?.type === 'application/pdf',
)

function clearFile(): void {
    if (filePreviewUrl.value) {
        URL.revokeObjectURL(filePreviewUrl.value)
    }
    selectedFile.value = null
    filePreviewUrl.value = null
    fileError.value = null
}

function validateAndSetFile(file: File): void {
    fileError.value = null

    if (!ACCEPTED_TYPES.includes(file.type)) {
        fileError.value = 'Formato no soportado. Usa JPG, PNG, WebP o PDF.'
        return
    }
    if (file.size > MAX_FILE_BYTES) {
        fileError.value = 'El archivo supera el límite de 10 MB.'
        return
    }

    clearFile()
    selectedFile.value = file
    // Build an object URL for image previews (PDFs get an icon instead).
    if (file.type !== 'application/pdf') {
        filePreviewUrl.value = URL.createObjectURL(file)
    }
}

function handleFileInputChange(event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (file) {
        validateAndSetFile(file)
        // Reset so the same file can be re-selected after clearing.
        input.value = ''
    }
}

function handleDrop(event: DragEvent): void {
    isDragging.value = false
    const file = event.dataTransfer?.files?.[0]
    if (file) validateAndSetFile(file)
}

function openFilePicker(): void {
    fileInput.value?.click()
}

// ── Upload + OCR polling ──────────────────────────────────────────────────────

const toast = useToast()

// Draft id from a successful upload — needed by the slow-fallback path.
const uploadedDraftId = ref<number | null>(null)

let pollTimer: ReturnType<typeof setTimeout> | null = null
let pollTries = 0

function stopPolling(): void {
    if (pollTimer !== null) {
        clearTimeout(pollTimer)
        pollTimer = null
    }
    pollTries = 0
}

async function pollOcrStatus(expenseId: number): Promise<void> {
    if (pollTries >= POLL_MAX_TRIES) {
        // ~30 s elapsed (20 × 1.5 s). Surface the "taking longer" fallback so the
        // user can proceed to manual verification without being stuck.
        step.value = 'slow'
        return
    }

    pollTries++

    try {
        const result = await ExpenseService.ocrStatus(expenseId)

        if (result.ocr_status === 'done' || result.ocr_status === 'failed') {
            stopPolling()
            // Fetch the full Expense shape (the poll response omits ocr_data details).
            const expense = await ExpenseService.get(expenseId)
            close()
            emit('ready-to-verify', expense)
            return
        }

        // Still pending/processing — schedule the next check.
        pollTimer = setTimeout(() => { void pollOcrStatus(expenseId) }, POLL_INTERVAL_MS)
    } catch {
        // Network hiccup — retry silently up to the cap.
        pollTimer = setTimeout(() => { void pollOcrStatus(expenseId) }, POLL_INTERVAL_MS)
    }
}

async function uploadReceipt(): Promise<void> {
    if (!selectedFile.value) return

    step.value = 'uploading'

    try {
        const draft = await ExpenseService.uploadReceipt(selectedFile.value)
        uploadedDraftId.value = draft.id
        step.value = 'processing'
        void pollOcrStatus(draft.id)
    } catch (err: unknown) {
        step.value = 'idle'
        const apiErr = err as { response?: { data?: { message?: string } } }
        toast.error(
            apiErr.response?.data?.message ?? 'No se pudo subir la factura. Intenta de nuevo.',
        )
    }
}

// ── Slow-fallback: proceed without waiting for OCR ────────────────────────────

async function proceedFromSlow(): Promise<void> {
    if (uploadedDraftId.value === null) return
    try {
        const expense = await ExpenseService.get(uploadedDraftId.value)
        close()
        emit('ready-to-verify', expense)
    } catch {
        toast.error('No se pudo cargar el borrador. Cierra y búscalo en la lista.')
    }
}

// ── Open / close ──────────────────────────────────────────────────────────────

function reset(): void {
    stopPolling()
    clearFile()
    step.value = 'idle'
    fileError.value = null
    uploadedDraftId.value = null
}

function close(): void {
    emit('update:modelValue', false)
}

// Reset when the slideover closes so it's fresh on next open.
watch(
    () => props.modelValue,
    (open) => {
        if (!open) reset()
    },
)

onUnmounted(() => {
    stopPolling()
    if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value)
})
</script>

<template>
    <AppSlideover
        :model-value="modelValue"
        title="Subir factura"
        subtitle="Sube una foto o PDF — el sistema extrae los datos automáticamente"
        test-id="receipt-upload-slideover"
        @update:model-value="close"
    >
        <!-- ── Processing state ──────────────────────────────────────────────── -->
        <div
            v-if="step === 'uploading' || step === 'processing'"
            class="flex flex-col items-center justify-center gap-5 py-16 text-center"
            data-testid="receipt-processing-state"
        >
            <AppSpinner size="lg" />
            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-on-surface">
                    {{ step === 'uploading' ? 'Subiendo factura…' : 'Procesando factura…' }}
                </p>
                <p class="text-xs text-on-surface-variant max-w-xs">
                    {{ step === 'uploading'
                        ? 'Espera mientras se sube el archivo.'
                        : 'El sistema está leyendo los datos. Esto tarda unos segundos.' }}
                </p>
            </div>
        </div>

        <!-- ── Slow fallback ─────────────────────────────────────────────────── -->
        <div
            v-else-if="step === 'slow'"
            class="flex flex-col items-center justify-center gap-5 py-12 text-center"
            data-testid="receipt-slow-state"
        >
            <div
                class="w-14 h-14 rounded-2xl flex items-center justify-center"
                style="background: var(--warning-container)"
            >
                <AlertCircle :size="26" style="color: var(--warning)" />
            </div>
            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-on-surface">
                    Tardando más de lo normal
                </p>
                <p class="text-xs text-on-surface-variant max-w-xs">
                    El procesamiento OCR está demorando. Puedes continuar y completar
                    los datos manualmente — la lectura automática seguirá en segundo
                    plano o puedes ignorarla.
                </p>
            </div>
            <AppButton
                variant="primary"
                size="sm"
                @click="proceedFromSlow"
            >
                Continuar y completar manualmente
            </AppButton>
        </div>

        <!-- ── Idle / file selection ─────────────────────────────────────────── -->
        <div v-else class="flex flex-col gap-5">

            <!-- Dropzone / file preview -->
            <div class="flex flex-col gap-2">
                <p class="text-xs font-semibold uppercase tracking-[0.05em] text-on-surface-variant">
                    Archivo de factura
                </p>

                <!-- File preview (once a file is selected) -->
                <div
                    v-if="selectedFile"
                    class="relative rounded-xl overflow-hidden bg-surface-low dark:bg-surface-mid"
                    data-testid="receipt-file-preview"
                >
                    <!-- Image preview -->
                    <img
                        v-if="!isPdf && filePreviewUrl"
                        :src="filePreviewUrl"
                        alt="Vista previa de la factura"
                        class="w-full max-h-64 object-contain"
                    />

                    <!-- PDF icon -->
                    <div
                        v-else
                        class="flex flex-col items-center justify-center gap-3 py-10"
                    >
                        <div
                            class="w-14 h-14 rounded-2xl flex items-center justify-center"
                            style="background: var(--primary-container)"
                        >
                            <FileText :size="26" style="color: var(--primary)" />
                        </div>
                        <div class="flex flex-col items-center gap-0.5">
                            <p class="text-sm font-medium text-on-surface">
                                {{ selectedFile.name }}
                            </p>
                            <p class="text-xs text-on-surface-variant">
                                {{ (selectedFile.size / 1024).toFixed(0) }} KB — PDF
                            </p>
                        </div>
                    </div>

                    <!-- Remove file -->
                    <button
                        type="button"
                        class="absolute top-2 right-2 btn-icon bg-surface-lowest/80 backdrop-blur-sm"
                        aria-label="Eliminar archivo seleccionado"
                        @click="clearFile"
                    >
                        <X :size="14" />
                    </button>
                </div>

                <!-- Dropzone (empty state) -->
                <button
                    v-else
                    type="button"
                    :class="[
                        'flex flex-col items-center justify-center gap-3 w-full rounded-xl',
                        'border-2 border-dashed transition-colors duration-150 cursor-pointer min-h-36',
                        'bg-surface-low dark:bg-surface-mid',
                        isDragging
                            ? 'border-primary bg-primary/5'
                            : 'border-outline-soft hover:border-primary hover:bg-primary/5',
                    ]"
                    data-testid="receipt-dropzone"
                    @click="openFilePicker"
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="handleDrop"
                >
                    <div
                        class="w-12 h-12 rounded-xl flex items-center justify-center"
                        style="background: var(--primary-container)"
                    >
                        <Upload :size="22" style="color: var(--primary)" />
                    </div>
                    <div class="flex flex-col items-center gap-0.5">
                        <span class="text-sm font-medium text-on-surface">
                            Arrastra tu factura o haz clic
                        </span>
                        <span class="text-xs text-on-surface-variant">
                            JPG, PNG, WebP o PDF — máx. 10 MB
                        </span>
                    </div>
                </button>

                <!-- Validation error -->
                <p
                    v-if="fileError"
                    class="text-xs"
                    style="color: var(--error)"
                    role="alert"
                    data-testid="receipt-file-error"
                >
                    {{ fileError }}
                </p>

                <!-- Hidden file input -->
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    :accept="ACCEPTED_EXTENSIONS"
                    data-testid="receipt-file-input"
                    @change="handleFileInputChange"
                />
            </div>

            <!-- OCR framing note: suggestions, not facts -->
            <div class="flex gap-3 px-4 py-3 rounded-xl bg-surface-low dark:bg-surface-mid">
                <AlertCircle :size="16" class="text-on-surface-variant shrink-0 mt-0.5" />
                <p class="text-xs text-on-surface-variant leading-relaxed">
                    Los datos extraídos son
                    <strong class="text-on-surface">sugerencias</strong>
                    — siempre podrás revisarlos y corregirlos antes de confirmar el gasto.
                </p>
            </div>
        </div>

        <!-- ── Footer ────────────────────────────────────────────────────────── -->
        <template #footer>
            <div v-if="step === 'idle'" class="flex gap-3">
                <AppButton
                    variant="secondary"
                    class="flex-1"
                    @click="close"
                >
                    Cancelar
                </AppButton>
                <AppButton
                    variant="primary"
                    class="flex-1"
                    :disabled="!selectedFile || !!fileError"
                    :icon="Upload"
                    data-testid="btn-subir-factura-submit"
                    @click="uploadReceipt"
                >
                    Subir y procesar
                </AppButton>
            </div>
        </template>
    </AppSlideover>
</template>
