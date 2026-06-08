import { useToast } from '@/composables/useToast'
import type { CartItem } from '@/stores/cart'
import type { StorefrontTenant } from '@/types/domain/Storefront'

export interface CheckoutCustomer {
    name?: string
    note?: string
}

/**
 * Builds the plain-text WhatsApp order message in Spanish.
 *
 * The format is intentionally simple and human-readable — the tenant's staff
 * will read it on their phone. Line items use "2x" prefix and include the
 * variant options if any exist, e.g.:
 *
 *   Hola Flores Aurora, quiero hacer un pedido:
 *
 *   - 2x Rosa Eterna Carmesi (Color: Rojo) - $59.98
 *   - 1x Bouquet Aurora (Tamano: Grande) - $45.00
 *
 *   Total: $104.98
 *
 *   Nombre: Juan Perez
 *   Nota: Para entregar el viernes
 *
 * Prices are formatted with the tenant's currency and language locale via
 * Intl.NumberFormat — the same engine that storefront.formatPrice uses.
 */
export function buildWhatsAppMessage(
    items: CartItem[],
    tenant: StorefrontTenant,
    customer?: CheckoutCustomer,
): string {
    const formatter = new Intl.NumberFormat(tenant.language ?? 'en-US', {
        style: 'currency',
        currency: tenant.currency ?? 'USD',
    })

    const formatCents = (cents: number): string => formatter.format(cents / 100)

    const header = `Hola ${tenant.business_name}, quiero hacer un pedido:`

    const lines = items.map((item) => {
        const optionParts = Object.entries(item.variantOptions)
            .map(([key, value]) => `${key}: ${value}`)
            .join(', ')

        const optionSuffix = optionParts ? ` (${optionParts})` : ''
        const lineTotal = formatCents(item.priceCents * item.quantity)

        return `- ${item.quantity}x ${item.productName}${optionSuffix} - ${lineTotal}`
    })

    const totalCents = items.reduce((sum, i) => sum + i.priceCents * i.quantity, 0)
    const totalLine = `Total: ${formatCents(totalCents)}`

    const parts: string[] = [header, '', ...lines, '', totalLine]

    if (customer?.name?.trim()) {
        parts.push(`Nombre: ${customer.name.trim()}`)
    }
    if (customer?.note?.trim()) {
        parts.push(`Nota: ${customer.note.trim()}`)
    }

    return parts.join('\n')
}

export function useWhatsappCheckout() {
    const toast = useToast()

    /**
     * Opens WhatsApp with a pre-filled order message.
     *
     * Returns true when the wa.me window was opened, false when the tenant has
     * no WhatsApp number configured (a toast explains why to the user).
     *
     * wa.me expects the phone number as digits with country code, no '+', no
     * spaces — which is exactly how tenant.whatsapp_number is stored.
     */
    function checkout(
        items: CartItem[],
        tenant: StorefrontTenant,
        customer?: CheckoutCustomer,
    ): boolean {
        if (!tenant.whatsapp_number?.trim()) {
            toast.warning('Este negocio no tiene WhatsApp configurado')
            return false
        }

        if (items.length === 0) {
            toast.warning('El carrito esta vacio')
            return false
        }

        const message = buildWhatsAppMessage(items, tenant, customer)
        const encoded = encodeURIComponent(message)
        const url = `https://wa.me/${tenant.whatsapp_number.trim()}?text=${encoded}`

        window.open(url, '_blank', 'noopener,noreferrer')
        return true
    }

    return { checkout, buildWhatsAppMessage }
}
