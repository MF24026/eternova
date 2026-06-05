import { useTenantStore } from '@/stores/tenant'

type CountryCode = 'SV' | 'CO' | 'MX' | 'GT' | 'HN' | string

/**
 * Formats a raw phone number according to the tenant's country code.
 *
 * Country formats:
 *   SV (El Salvador): +503 XXXX-XXXX
 *   CO (Colombia):    +57 XXX XXX XXXX
 *   MX (Mexico):      +52 XX XXXX XXXX
 *   GT (Guatemala):   +502 XXXX-XXXX
 *   HN (Honduras):    +504 XXXX-XXXX
 *
 * Usage:
 *   const { formatPhone } = useFormatPhone()
 *   formatPhone('78921234') // '+503 7892-1234' for SV tenant
 */
export function useFormatPhone() {
    function getCountryCode(): CountryCode {
        try {
            const store = useTenantStore()
            return store.currentTenant?.country_code ?? 'SV'
        } catch {
            return 'SV'
        }
    }

    function stripNonDigits(value: string): string {
        return value.replace(/\D/g, '')
    }

    function formatPhone(value: string | null | undefined, countryOverride?: CountryCode): string {
        if (!value) return ''
        const country = countryOverride ?? getCountryCode()
        const digits = stripNonDigits(value)

        switch (country) {
            case 'SV': {
                // +503 XXXX-XXXX (8 local digits)
                const local = digits.slice(-8)
                if (local.length < 8) return value
                return `+503 ${local.slice(0, 4)}-${local.slice(4)}`
            }
            case 'CO': {
                // +57 XXX XXX XXXX (10 local digits)
                const local = digits.slice(-10)
                if (local.length < 10) return value
                return `+57 ${local.slice(0, 3)} ${local.slice(3, 6)} ${local.slice(6)}`
            }
            case 'MX': {
                // +52 XX XXXX XXXX (10 local digits)
                const local = digits.slice(-10)
                if (local.length < 10) return value
                return `+52 ${local.slice(0, 2)} ${local.slice(2, 6)} ${local.slice(6)}`
            }
            case 'GT': {
                // +502 XXXX-XXXX (8 local digits)
                const local = digits.slice(-8)
                if (local.length < 8) return value
                return `+502 ${local.slice(0, 4)}-${local.slice(4)}`
            }
            case 'HN': {
                // +504 XXXX-XXXX (8 local digits)
                const local = digits.slice(-8)
                if (local.length < 8) return value
                return `+504 ${local.slice(0, 4)}-${local.slice(4)}`
            }
            default:
                return value
        }
    }

    return { formatPhone }
}
