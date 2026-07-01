export interface TaxConfig {
    enabled: boolean
    rate_bps: number
    prices_include_tax: boolean
}

export interface TaxTotals {
    subtotalCents: number
    taxCents: number
    totalCents: number
}

/**
 * Display-only mirror of the server TaxCalculator. The server recomputes at
 * checkout, so this never persists a total. Integer cents; rounding derives the
 * tax from the base so the parts always reconcile.
 */
export function computeTax(itemsCents: number, discountCents: number, tax: TaxConfig): TaxTotals {
    const discount = Math.min(Math.max(0, discountCents), Math.max(0, itemsCents))

    if (!tax.enabled || tax.rate_bps <= 0) {
        return { subtotalCents: itemsCents, taxCents: 0, totalCents: Math.max(0, itemsCents - discount) }
    }

    const base = itemsCents - discount

    if (tax.prices_include_tax) {
        const divisor = 10_000 + tax.rate_bps
        const net = Math.floor((base * 10_000 + Math.floor(divisor / 2)) / divisor)
        return { subtotalCents: net, taxCents: base - net, totalCents: base }
    }

    const taxCents = Math.floor((base * tax.rate_bps) / 10_000)
    return { subtotalCents: itemsCents, taxCents, totalCents: base + taxCents }
}
