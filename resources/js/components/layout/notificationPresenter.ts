import { AlertCircle, Ban, Bell, Clock, FileText, PackageX } from 'lucide-vue-next'
import type { AppNotification, PresentedNotification } from '@/types/domain/Notifications'

function str(v: unknown, fallback = ''): string {
    return v === null || v === undefined ? fallback : String(v)
}

function money(cents: unknown, currency: unknown): string {
    const amount = Number(cents ?? 0) / 100
    const code = str(currency, 'USD')
    try {
        return new Intl.NumberFormat('es-SV', { style: 'currency', currency: code }).format(amount)
    } catch {
        return `${amount.toFixed(2)} ${code}`
    }
}

/**
 * Maps a notification's `type` + `payload` to a display shape. The API stores
 * heterogeneous payloads with no title/body, so all display copy lives here.
 * Unknown types fall back to a generic bell with an empty body.
 */
export function presentNotification(n: AppNotification): PresentedNotification {
    const p = n.payload ?? {}

    switch (n.type) {
        case 'inventory.low_stock':
            return {
                icon: PackageX,
                title: 'Stock bajo',
                body: `${str(p.product_name, 'Producto')} (${str(p.sku, 'N/A')}) en ${str(p.branch_name, 'sucursal')}: ${str(p.available, '0')} disponibles`,
            }
        case 'billing.trial_ending':
            return {
                icon: Clock,
                title: 'Tu prueba termina pronto',
                body: `Quedan ${str(p.days_left, '0')} dias de prueba`,
            }
        case 'billing.invoice_ready':
            return {
                icon: FileText,
                title: 'Factura lista',
                body: `Factura ${str(p.number, '')} — ${money(p.total_cents, p.currency)}`,
            }
        case 'billing.charge_failed':
            return {
                icon: AlertCircle,
                title: 'Cobro fallido',
                body: 'No pudimos procesar tu pago. Revisa tu metodo de pago.',
            }
        case 'billing.suspended':
            return {
                icon: Ban,
                title: 'Suscripcion suspendida',
                body: 'Tu suscripcion fue suspendida por falta de pago.',
            }
        default:
            return { icon: Bell, title: 'Notificacion', body: '' }
    }
}
