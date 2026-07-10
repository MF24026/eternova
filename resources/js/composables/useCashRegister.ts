import { ref } from 'vue'
import CashRegisterService from '@/services/CashRegisterService'
import type { CashRegisterSession } from '@/types/domain/POS'

/**
 * Cash-register session state for the POS. Tracks the cashier's open session at the
 * active branch and exposes open/close actions. Only relevant when the tenant's giro
 * enables the cash_register module (the API 403s otherwise).
 */
export function useCashRegister() {
    const session = ref<CashRegisterSession | null>(null)
    const loading = ref(false)

    async function refresh(branchId: string): Promise<void> {
        if (branchId === '') return
        loading.value = true
        try {
            session.value = await CashRegisterService.current(branchId)
        } catch {
            session.value = null
        } finally {
            loading.value = false
        }
    }

    async function open(branchId: string, openingAmountCents: number, notes?: string): Promise<void> {
        session.value = await CashRegisterService.open({
            branch_id: branchId,
            opening_amount_cents: openingAmountCents,
            opening_notes: notes || undefined,
        })
    }

    async function close(closingAmountCents: number, notes?: string): Promise<CashRegisterSession | null> {
        if (session.value === null) return null
        const closed = await CashRegisterService.close(session.value.id, {
            closing_amount_cents: closingAmountCents,
            closing_notes: notes || undefined,
        })
        session.value = null // the shift is over; the drawer is closed
        return closed
    }

    async function addMovement(type: 'in' | 'out', amountCents: number, reason: string): Promise<void> {
        if (session.value === null) return
        session.value = await CashRegisterService.movement(session.value.id, {
            type,
            amount_cents: amountCents,
            reason,
        })
    }

    return { session, loading, refresh, open, close, addMovement }
}
