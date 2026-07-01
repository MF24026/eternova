import api from '@/services/api'
import type { AppNotification } from '@/types/domain/Notifications'

/**
 * Thin wrappers over the notifications API. Every call is scoped server-side to
 * the authenticated user + current tenant; the axios instance handles CSRF + 401.
 */
export const NotificationsService = {
    async getUnreadCount(): Promise<number> {
        const { data } = await api.get('/notifications/unread-count')
        return Number(data?.data?.count ?? 0)
    },

    async list(unreadOnly = false, perPage = 15): Promise<AppNotification[]> {
        const { data } = await api.get('/notifications', {
            params: { unread_only: unreadOnly ? 'true' : undefined, per_page: perPage },
        })
        return (data?.data ?? []) as AppNotification[]
    },

    async markRead(id: string): Promise<void> {
        await api.patch(`/notifications/${id}/read`)
    },

    async markAllRead(): Promise<void> {
        await api.patch('/notifications/read-all')
    },
}
