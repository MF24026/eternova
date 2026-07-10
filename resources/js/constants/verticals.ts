import { Flower, Shirt, Gem, Gift, ShoppingBasket, Store, type LucideIcon } from 'lucide-vue-next'

export type BusinessType =
    | 'floreria_regalos'
    | 'ropa_boutique'
    | 'accesorios'
    | 'peluches'
    | 'minimarket'
    | 'otro'

/**
 * Onboarding giro picker options. The backend config/verticals.php remains the source
 * of truth for module resolution and the starter catalog; this mirror only carries the
 * label + Lucide icon for the five static picker cards.
 */
export const VERTICALS: Array<{ key: BusinessType; label: string; icon: LucideIcon }> = [
    { key: 'floreria_regalos', label: 'Florería / Regalos', icon: Flower },
    { key: 'ropa_boutique', label: 'Ropa / Boutique', icon: Shirt },
    { key: 'accesorios', label: 'Accesorios / Maquillaje', icon: Gem },
    { key: 'peluches', label: 'Peluches / Juguetería', icon: Gift },
    { key: 'minimarket', label: 'Minimarket / Abarrotes', icon: ShoppingBasket },
    { key: 'otro', label: 'Otro', icon: Store },
]
