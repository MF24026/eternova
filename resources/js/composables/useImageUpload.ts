import { ref } from 'vue'
import ProductImagesService from '@/services/ProductImagesService'
import type { ProductImage } from '@/types/domain/Product'
import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'

export interface UseImageUpload {
    isUploading: ReturnType<typeof ref<boolean>>
    uploadProgress: ReturnType<typeof ref<number>>
    uploadError: ReturnType<typeof ref<string | null>>
    upload: (productId: number, file: File) => Promise<ProductImage | null>
    cancel: () => void
}

/**
 * Encapsulates multipart image upload with progress, error state, and cancel.
 *
 * Usage:
 *   const { isUploading, uploadProgress, upload, cancel } = useImageUpload()
 *   const image = await upload(productId, file)
 */
export function useImageUpload() {
    const isUploading   = ref<boolean>(false)
    const uploadProgress = ref<number>(0)
    const uploadError   = ref<string | null>(null)

    let abortController: AbortController | null = null

    async function upload(productId: number, file: File): Promise<ProductImage | null> {
        isUploading.value    = true
        uploadProgress.value = 0
        uploadError.value    = null

        abortController = new AbortController()

        try {
            const image = await ProductImagesService.upload(
                productId,
                file,
                (percent) => { uploadProgress.value = percent },
                abortController.signal,
            )
            return image
        } catch (err) {
            if ((err as { name?: string }).name === 'CanceledError') {
                // Intentional cancel — not an error for the user.
                return null
            }

            const axiosError = err as AxiosError<ApiErrorResponse>
            const firstError = axiosError.response?.data?.errors?.['image']?.[0]
                ?? axiosError.response?.data?.message
                ?? 'Upload failed.'

            uploadError.value = firstError
            return null
        } finally {
            isUploading.value = false
            abortController   = null
        }
    }

    function cancel(): void {
        abortController?.abort()
    }

    return {
        isUploading,
        uploadProgress,
        uploadError,
        upload,
        cancel,
    }
}
