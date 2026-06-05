import type { AxiosError } from 'axios'
import type { ApiErrorResponse } from '@/types/api'

/**
 * Extracts a human-readable message from an Axios error response.
 * Falls back to a generic message if the response does not match
 * the expected envelope format.
 */
export function extractErrorMessage(error: unknown): string {
    if (!isAxiosError(error)) {
        return 'Ocurrio un error inesperado.'
    }

    const data = error.response?.data as ApiErrorResponse | undefined
    if (data?.message) {
        return data.message
    }

    return 'Ocurrio un error inesperado.'
}

/**
 * Extracts field-level validation errors from a 422 Axios response.
 * Returns an empty object if there are no field errors.
 */
export function extractFieldErrors(error: unknown): Record<string, string> {
    if (!isAxiosError(error)) {
        return {}
    }

    const data = error.response?.data as ApiErrorResponse | undefined
    if (!data?.errors) {
        return {}
    }

    const flat: Record<string, string> = {}
    for (const [field, messages] of Object.entries(data.errors)) {
        flat[field] = messages[0] ?? ''
    }
    return flat
}

function isAxiosError(error: unknown): error is AxiosError {
    return (
        typeof error === 'object' &&
        error !== null &&
        'isAxiosError' in error &&
        (error as Record<string, unknown>).isAxiosError === true
    )
}
