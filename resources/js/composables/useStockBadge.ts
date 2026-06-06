export interface StockBadgeResult {
    variant: 'success' | 'warning' | 'error' | 'neutral'
    label: string
}

const DEFAULT_MIN_ALERT = 10

/**
 * Derives a badge variant and label from a stock quantity.
 * The min_alert threshold is typically sourced from the product variant
 * (added by issue #37). Until that lands, callers pass it explicitly or
 * fall back to the default of 10.
 */
export function useStockBadge() {
    function forAvailable(available: number, minAlert: number = DEFAULT_MIN_ALERT): StockBadgeResult {
        if (available <= 0) {
            return { variant: 'error', label: 'Sin stock' }
        }
        if (available <= minAlert) {
            return { variant: 'warning', label: 'Stock bajo' }
        }
        return { variant: 'success', label: 'En stock' }
    }

    return { forAvailable }
}
