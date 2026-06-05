import { useTenantStore } from '@/stores/tenant'

/**
 * Formats dates according to the tenant's locale.
 * Default: dd/mm/yyyy (Latin American convention).
 *
 * Usage:
 *   const { formatDate, formatDateTime } = useFormatDate()
 *   formatDate('2026-05-15') // '15/05/2026'
 */
export function useFormatDate() {
    function getLocale(): string {
        try {
            const store = useTenantStore()
            return store.currentTenant?.language ?? 'es-SV'
        } catch {
            return 'es-SV'
        }
    }

    function formatDate(value: string | Date | null | undefined): string {
        if (!value) return ''
        const date = typeof value === 'string' ? new Date(value) : value
        if (isNaN(date.getTime())) return ''

        return date.toLocaleDateString(getLocale(), {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        })
    }

    function formatDateTime(value: string | Date | null | undefined): string {
        if (!value) return ''
        const date = typeof value === 'string' ? new Date(value) : value
        if (isNaN(date.getTime())) return ''

        return date.toLocaleString(getLocale(), {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        })
    }

    function formatRelative(value: string | Date | null | undefined): string {
        if (!value) return ''
        const date = typeof value === 'string' ? new Date(value) : value
        if (isNaN(date.getTime())) return ''

        const diff = Date.now() - date.getTime()
        const minutes = Math.floor(diff / 60000)
        const hours = Math.floor(minutes / 60)
        const days = Math.floor(hours / 24)

        if (minutes < 1) return 'ahora mismo'
        if (minutes < 60) return `hace ${minutes} min`
        if (hours < 24) return `hace ${hours} h`
        if (days < 7) return `hace ${days} dia${days > 1 ? 's' : ''}`
        return formatDate(date)
    }

    return {
        formatDate,
        formatDateTime,
        formatRelative,
    }
}
