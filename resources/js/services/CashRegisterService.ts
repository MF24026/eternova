import api from '@/services/api'
import type { CashRegisterSession } from '@/types/domain/POS'

/** Endpoints for the POS cash-register session (arqueo). Gated by module:cash_register. */
export default {
    async current(branchId: string): Promise<CashRegisterSession | null> {
        const { data } = await api.get<{ data: CashRegisterSession | null }>('/pos/cash-register/current', {
            params: { branch_id: branchId },
        })
        return data.data
    },

    async open(payload: { branch_id: string; opening_amount_cents: number; opening_notes?: string }): Promise<CashRegisterSession> {
        const { data } = await api.post<{ data: CashRegisterSession }>('/pos/cash-register/open', payload)
        return data.data
    },

    async close(
        sessionId: number,
        payload: { closing_amount_cents: number; closing_notes?: string },
    ): Promise<CashRegisterSession> {
        const { data } = await api.post<{ data: CashRegisterSession }>(`/pos/cash-register/${sessionId}/close`, payload)
        return data.data
    },

    async movement(
        sessionId: number,
        payload: { type: 'in' | 'out'; amount_cents: number; reason: string },
    ): Promise<CashRegisterSession> {
        const { data } = await api.post<{ data: CashRegisterSession }>(`/pos/cash-register/${sessionId}/movements`, payload)
        return data.data
    },
}
