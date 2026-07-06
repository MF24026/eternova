import type { PosProductVariant } from '@/types/domain/POS'

export interface VariantGroup {
    label: string
    variants: PosProductVariant[]
}

/**
 * Group variants by the value of their FIRST option axis (e.g. size), preserving
 * the original variant order. Variants with no options fall into a single group
 * with an empty label.
 */
export function groupVariantsByFirstOption(variants: PosProductVariant[]): VariantGroup[] {
    const order: string[] = []
    const map = new Map<string, PosProductVariant[]>()

    for (const variant of variants) {
        const label = Object.values(variant.options ?? {})[0] ?? ''
        if (!map.has(label)) {
            map.set(label, [])
            order.push(label)
        }
        map.get(label)!.push(variant)
    }

    return order.map((label) => ({ label, variants: map.get(label)! }))
}
