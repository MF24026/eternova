import api from './api'
import type { Resource } from '@/types/api'
import type { ProductImage } from '@/types/domain/Product'
import type { Product } from '@/types/domain/Product'

export interface UploadProgressCallback {
    (percent: number): void
}

const ProductImagesService = {
    /**
     * Upload an image file for a product.
     * Calls onProgress with 0-100 percent during the multipart POST.
     */
    async upload(
        productId: number,
        file: File,
        onProgress?: UploadProgressCallback,
        signal?: AbortSignal,
    ): Promise<ProductImage> {
        const form = new FormData()
        form.append('image', file)

        const response = await api.post<Resource<ProductImage>>(
            `/products/${productId}/images`,
            form,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                signal,
                onUploadProgress: (event) => {
                    if (onProgress && event.total) {
                        onProgress(Math.round((event.loaded * 100) / event.total))
                    }
                },
            },
        )

        return response.data.data
    },

    /**
     * Delete an image from the product gallery by its full-size URL.
     */
    async delete(productId: number, fullUrl: string): Promise<void> {
        await api.delete(`/products/${productId}/images`, {
            data: { url: fullUrl },
        })
    },

    /**
     * Reorder the gallery by passing an ordered array of full-size URLs.
     */
    async reorder(productId: number, orderedFullUrls: string[]): Promise<void> {
        await api.patch(`/products/${productId}/images/reorder`, {
            urls: orderedFullUrls,
        })
    },

    /**
     * Set the product's default_image_url to the given full-size URL.
     * Returns the updated Product.
     */
    async setDefault(productId: number, fullUrl: string): Promise<Product> {
        const response = await api.patch<Resource<Product>>(
            `/products/${productId}/images/set-default`,
            { url: fullUrl },
        )
        return response.data.data
    },
}

export default ProductImagesService
